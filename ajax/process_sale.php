<?php
// Suppress ALL error output for JSON responses
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

// Clear any output that might have been generated (warnings, notices, etc.)
ob_clean();

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    ob_end_flush();
    exit;
}

try {
    $auth->requirePermission('pos.access');
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Permission denied: ' . $e->getMessage()]);
    ob_end_flush();
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['cart']) || empty($input['cart'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    ob_end_flush();
    exit;
}

error_log("========================================");
error_log("PROCESS SALE: Script started");
error_log("PROCESS SALE: Request received at " . date('Y-m-d H:i:s'));

try {
    $db = Database::getInstance();
    error_log("PROCESS SALE: Database instance obtained");
    
    // Store db instance globally for updateStock to use if needed
    $GLOBALS['current_transaction_db'] = $db;
    
    // Start transaction - check if it actually started
    $pdo = $db->getPdo();
    if (!$pdo->inTransaction()) {
        if (!$db->beginTransaction()) {
            throw new Exception('Failed to start database transaction');
        }
    }
    error_log("PROCESS SALE: Transaction started");
    
    $branchId = $_SESSION['branch_id'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;
    error_log("PROCESS SALE: Initial branchId = " . ($branchId ?? 'NULL'));
    error_log("PROCESS SALE: Initial userId = " . ($userId ?? 'NULL'));
    
    // If branchId is not set, try to get from primary database (HEAD OFFICE = 1)
    if (!$branchId) {
        $primaryDb = Database::getPrimaryInstance();
        $defaultBranch = $primaryDb->getRow("SELECT id FROM branches WHERE branch_name LIKE '%HEAD%' OR branch_name LIKE '%OFFICE%' OR id = 1 LIMIT 1");
        if ($defaultBranch) {
            $branchId = $defaultBranch['id'];
            $_SESSION['branch_id'] = $branchId;
            error_log("PROCESS SALE: branchId was NULL, using default branch ID: $branchId");
        }
    }
    
    error_log("PROCESS SALE: branchId from session = " . ($branchId ?? 'NULL'));
    error_log("PROCESS SALE: userId = " . $userId);
    
    // Ensure tables exist - create if they don't (suppress any output)
    @ensurePOSTables($db);
    
    // Get current shift - handle null branch_id
    if ($branchId !== null) {
        $shift = $db->getRow("SELECT * FROM shifts WHERE branch_id = :branch_id AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
            ':branch_id' => $branchId,
            ':user_id' => $userId
        ]);
    } else {
        $shift = $db->getRow("SELECT * FROM shifts WHERE (branch_id IS NULL OR branch_id = 0) AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
            ':user_id' => $userId
        ]);
    }
    
    if (!$shift) {
        // Try to create a shift as fallback (shouldn't happen if UI is working correctly)
        if ($branchId !== null) {
            $lastShift = $db->getRow("SELECT shift_number FROM shifts WHERE branch_id = :branch_id ORDER BY id DESC LIMIT 1", [':branch_id' => $branchId]);
        } else {
            $lastShift = $db->getRow("SELECT shift_number FROM shifts WHERE (branch_id IS NULL OR branch_id = 0) ORDER BY id DESC LIMIT 1");
        }
        $shiftNumber = ($lastShift ? intval($lastShift['shift_number']) : 0) + 1;
        
        $shiftData = [
            'shift_number' => $shiftNumber,
            'branch_id' => $branchId ?? 0,
            'user_id' => $userId,
            'opened_at' => date('Y-m-d H:i:s'),
            'opened_by' => $userId,
            'starting_cash' => 0.00,
            'expected_cash' => 0.00,
            'status' => 'open'
        ];
        
        $shiftId = $db->insert('shifts', $shiftData);
        
        if (!$shiftId) {
            throw new Exception('No active shift found and failed to create one: ' . $db->getLastError());
        }
        
        $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [':id' => $shiftId]);
        
        if (!$shift) {
            throw new Exception('No active shift found and failed to create one');
        }
    }
    
    // Generate receipt number (format: BRANCH-DATE-SEQ where BRANCH is branch_id or 0, DATE is ymd, SEQ is 4-digit padded sequence)
    $datePart = date('ymd');
    $branchPrefix = $branchId ?? 0;
    
    // Use a more robust approach with retry logic to handle race conditions
    $maxRetries = 20;
    $receiptNumber = null;
    
    for ($retry = 0; $retry < $maxRetries; $retry++) {
        // Get the maximum sequence number for today (within transaction for consistency)
        $pattern = $branchPrefix . '-' . $datePart . '-%';
        
        if ($branchId !== null) {
            $maxReceipt = $db->getRow("SELECT receipt_number FROM sales WHERE branch_id = :branch_id AND receipt_number LIKE :pattern ORDER BY receipt_number DESC LIMIT 1", [
                ':branch_id' => $branchId,
                ':pattern' => $pattern
            ]);
        } else {
            $maxReceipt = $db->getRow("SELECT receipt_number FROM sales WHERE (branch_id IS NULL OR branch_id = 0) AND receipt_number LIKE :pattern ORDER BY receipt_number DESC LIMIT 1", [
                ':pattern' => $pattern
            ]);
        }
        
        // Extract sequence number from the last receipt
        $seq = 1;
        if ($maxReceipt && isset($maxReceipt['receipt_number'])) {
            // Receipt format: BRANCH-DATE-SEQ (e.g., "1-251214-0001" where 0001 is the sequence)
            // Extract the sequence part after the date
            $receiptNum = $maxReceipt['receipt_number'];
            $prefix = $branchPrefix . '-' . $datePart . '-';
            
            if (strpos($receiptNum, $prefix) === 0) {
                // Extract the sequence part (everything after the prefix, before any suffix)
                $seqPart = substr($receiptNum, strlen($prefix));
                // Remove any suffix (e.g., "-A12") if present
                if (preg_match('/^(\d+)/', $seqPart, $matches)) {
                    $seq = intval($matches[1]) + 1;
                }
            }
        }
        
        // Add retry offset to handle concurrent requests
        $seq += $retry;
        
        // Pad sequence to 4 digits (max 9999 per day per branch)
        $seqPadded = str_pad($seq, 4, '0', STR_PAD_LEFT);
        $receiptNumber = $branchPrefix . '-' . $datePart . '-' . $seqPadded;
        
        // Check if this receipt number already exists (race condition check)
        $existing = $db->getRow("SELECT id FROM sales WHERE receipt_number = :receipt_number", [
            ':receipt_number' => $receiptNumber
        ]);
        
        if (!$existing) {
            // Receipt number is unique, break out of loop
            break;
        }
        
        // Small random delay to avoid rapid retries (helps with race conditions)
        if ($retry < $maxRetries - 1) {
            usleep(rand(10000, 50000)); // 10-50ms random delay
        }
    }
    
    if (!$receiptNumber || $retry >= $maxRetries) {
        // Last resort: use max sequence + random 2-digit suffix to ensure uniqueness
        $randomSuffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 2));
        $seq = ($seq ?? 9999);
        $seqPadded = str_pad($seq, 4, '0', STR_PAD_LEFT);
        $receiptNumber = $branchPrefix . '-' . $datePart . '-' . $seqPadded . '-' . $randomSuffix;
    }
    
    // Check if fiscalization is enabled - if so, verify fiscal day is open BEFORE creating sale
    if ($branchId) {
        $primaryDb = Database::getPrimaryInstance();
        $branch = $primaryDb->getRow(
            "SELECT id, fiscalization_enabled FROM branches WHERE id = :id",
            [':id' => $branchId]
        );
        
        if ($branch && $branch['fiscalization_enabled']) {
            // Fiscalization is enabled - MUST have open fiscal day
            try {
                require_once APP_PATH . '/includes/fiscal_service.php';
                $fiscalService = new FiscalService($branchId);
                $status = $fiscalService->getFiscalDayStatus();
                
                if (!$status || !isset($status['fiscalDayStatus'])) {
                    throw new Exception('Could not verify fiscal day status. Sale cannot be processed.');
                }
                
                $fiscalDayStatus = $status['fiscalDayStatus'];
                $isDayOpen = ($fiscalDayStatus === 'FiscalDayOpened' || $fiscalDayStatus === 'FiscalDayCloseFailed');
                
                if (!$isDayOpen) {
                    // Fiscal day is closed - attempt to auto-open
                    error_log("PROCESS SALE: Fiscal day is closed (Status: $fiscalDayStatus). Attempting to auto-open...");
                    try {
                        $openResult = $fiscalService->openFiscalDay();
                        error_log("PROCESS SALE: Auto-open initiated. Result: " . json_encode($openResult));
                        
                        // Verify fiscal day was successfully opened by checking status again
                        sleep(1); // Give ZIMRA a moment to process
                        $status = $fiscalService->getFiscalDayStatus();
                        
                        if (!$status || !isset($status['fiscalDayStatus'])) {
                            throw new Exception('Could not verify fiscal day status after auto-open. Sale cannot be processed.');
                        }
                        
                        $fiscalDayStatus = $status['fiscalDayStatus'];
                        $isDayOpen = ($fiscalDayStatus === 'FiscalDayOpened' || $fiscalDayStatus === 'FiscalDayCloseFailed');
                        
                        if (!$isDayOpen) {
                            throw new Exception('Fiscal day auto-open failed. Status after open attempt: ' . $fiscalDayStatus);
                        }
                        
                        error_log("PROCESS SALE: Fiscal day successfully auto-opened. Status: $fiscalDayStatus");
                    } catch (Exception $openError) {
                        error_log("PROCESS SALE: Auto-open failed: " . $openError->getMessage());
                        throw new Exception('Fiscal day auto-open failed: ' . $openError->getMessage());
                    }
                } else {
                    error_log("PROCESS SALE: Fiscal day status verified - Day is open (Status: $fiscalDayStatus)");
                }
            } catch (Exception $e) {
                // Block the sale if fiscal day check/auto-open fails
                error_log("PROCESS SALE: Fiscal day check/auto-open failed: " . $e->getMessage());
                throw new Exception('Sale cannot be processed: ' . $e->getMessage());
            }
        }
    }
    
    // Calculate totals
    $subtotal = 0;
    foreach ($input['cart'] as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    
    $discount = $input['discount'] ?? ['type' => null, 'amount' => 0];
    $discountAmount = 0;
    if ($discount['type'] === 'value') {
        $discountAmount = $discount['amount'];
    } else if ($discount['type'] === 'percentage') {
        $discountAmount = ($subtotal * $discount['amount']) / 100;
    }
    
    $total = $subtotal - $discountAmount;
    
    // Create sale record
    $customerId = null;
    if (isset($input['customer']) && is_array($input['customer']) && isset($input['customer']['id'])) {
        $customerId = $input['customer']['id'];
    }
    
    $saleData = [
        'receipt_number' => $receiptNumber,
        'shift_id' => $shift['id'],
        'branch_id' => $branchId,
        'user_id' => $userId,
        'customer_id' => $customerId,
        'sale_date' => date('Y-m-d H:i:s'),
        'subtotal' => $subtotal,
        'discount_type' => $discount['type'] ?? null,
        'discount_amount' => $discountAmount,
        'tax_amount' => 0,
        'total_amount' => $total,
        'payment_status' => 'paid',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    // Try to insert with retry logic for duplicate receipt numbers
    $saleId = false;
    $insertRetries = 10;
    $currentReceiptNumber = $receiptNumber;
    $datePart = date('ymd');
    $branchPrefix = $branchId ?? 0;
    
    for ($insertRetry = 0; $insertRetry < $insertRetries; $insertRetry++) {
        try {
            $saleData['receipt_number'] = $currentReceiptNumber;
            $saleId = $db->insert('sales', $saleData);
            
            if ($saleId) {
                // Success, break out of retry loop
                $receiptNumber = $currentReceiptNumber; // Update the receipt number used
                break;
            } else {
                $error = $db->getLastError();
                
                // Check if it's a duplicate key error
                if (strpos($error, 'Duplicate entry') !== false || strpos($error, '1062') !== false) {
                    // Generate a new receipt number with random suffix to ensure uniqueness
                    $randomSuffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 2));
                    $seq = rand(9000, 9999);
                    $seqPadded = str_pad($seq, 4, '0', STR_PAD_LEFT);
                    $currentReceiptNumber = $branchPrefix . '-' . $datePart . '-' . $seqPadded . '-' . $randomSuffix;
                    
                    // Small delay before retry
                    usleep(rand(10000, 50000));
                    continue;
                } else {
                    // Different error, throw exception
                    throw new Exception('Failed to create sale record: ' . $error);
                }
            }
        } catch (PDOException $e) {
            // Check if it's a duplicate key error
            if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), '1062') !== false) {
                // Generate a new receipt number with random suffix to ensure uniqueness
                $randomSuffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 2));
                $seq = rand(9000, 9999);
                $seqPadded = str_pad($seq, 4, '0', STR_PAD_LEFT);
                $currentReceiptNumber = $branchPrefix . '-' . $datePart . '-' . $seqPadded . '-' . $randomSuffix;
                
                // Small delay before retry
                usleep(rand(10000, 50000));
                continue;
            } else {
                // Different error, re-throw
                throw $e;
            }
        }
    }
    
    if (!$saleId) {
        throw new Exception('Failed to create sale record after ' . $insertRetries . ' attempts. Last receipt number tried: ' . $currentReceiptNumber);
    }
    
    // Create sale items
    foreach ($input['cart'] as $item) {
        $product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $item['id']]);
        
        $itemData = [
            'sale_id' => $saleId,
            'product_id' => $item['id'],
            'product_name' => $item['name'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['price'],
            'total_price' => $item['price'] * $item['quantity'],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $itemId = $db->insert('sale_items', $itemData);
        if (!$itemId) {
            throw new Exception('Failed to create sale item: ' . $db->getLastError());
        }
        
        // Update stock within the same transaction
        if (function_exists('updateStock')) {
            try {
                updateStock($item['id'], -$item['quantity'], $branchId, 'Sale', true);
            } catch (Exception $stockError) {
                // Log stock update error but don't fail the sale
                error_log("Stock update error for product {$item['id']}: " . $stockError->getMessage());
            }
        }
    }
    
    // Load currency functions
    require_once APP_PATH . '/includes/currency_functions.php';
    
    // Get base currency
    $baseCurrency = getBaseCurrency($db);
    $baseCurrencyId = $baseCurrency ? $baseCurrency['id'] : null;
    
    // Create payment records (for split payments)
    $payments = $input['payments'] ?? [['method' => 'cash', 'amount' => $total]];
    
    foreach ($payments as $payment) {
        $currencyId = isset($payment['currency_id']) ? intval($payment['currency_id']) : $baseCurrencyId;
        $exchangeRate = isset($payment['exchange_rate']) ? floatval($payment['exchange_rate']) : 1.0;
        $originalAmount = isset($payment['original_amount']) ? floatval($payment['original_amount']) : floatval($payment['amount']);
        $baseAmount = isset($payment['base_amount']) ? floatval($payment['base_amount']) : floatval($payment['amount']);
        
        $paymentData = [
            'sale_id' => $saleId,
            'payment_method' => $payment['method'],
            'currency_id' => $currencyId,
            'exchange_rate' => $exchangeRate,
            'original_amount' => $originalAmount,
            'base_amount' => $baseAmount,
            'amount' => $baseAmount, // Store base amount in amount field for backward compatibility
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $paymentId = $db->insert('sale_payments', $paymentData);
        if (!$paymentId) {
            throw new Exception('Failed to create payment record: ' . $db->getLastError());
        }
    }
    
    // Update sales table with base currency
    if ($baseCurrencyId) {
        $db->update('sales', ['base_currency_id' => $baseCurrencyId], ['id' => $saleId]);
    }
    
    // Update shift expected cash (use base amounts for cash payments)
    // IMPORTANT: We add the FULL payment amount here, then record change as pay_out
    // The expected_cash calculation in cash.php uses: starting + cashSales - payOuts
    // Where cashSales = full payments from sale_payments, and payOuts includes change
    $cashPayments = array_filter($payments, function($p) {
        return strtolower($p['method']) === 'cash';
    });
    $totalCashPaid = 0;
    foreach ($cashPayments as $p) {
        $totalCashPaid += isset($p['base_amount']) ? floatval($p['base_amount']) : floatval($p['amount']);
    }
    
    // Calculate change for cash payments (in base currency)
    $change = 0;
    if ($totalCashPaid > $total) {
        $change = $totalCashPaid - $total;
    }
    
    // Add FULL payment amount to expected_cash
    // Change will be recorded as pay_out and automatically deducted in the expected_cash calculation
    if ($totalCashPaid > 0) {
        try {
            $db->update('shifts', [
                'expected_cash' => $shift['expected_cash'] + $totalCashPaid
            ], ['id' => $shift['id']]);
        } catch (Exception $updateError) {
            // Log but don't fail - shift update is not critical
            error_log("Shift update error: " . $updateError->getMessage());
        }
    }
    
    // Record change as a drawer transaction (pay_out) if change > 0
    // This will be included in payOuts calculation, which is subtracted from expected_cash
    // Formula: expected_cash = starting + cashSales (full payments) - payOuts (includes change) - refunds
    if ($change > 0) {
        try {
            // Check if there's enough cash in drawer to give change
            // Available cash = starting_cash + expected_cash (before this sale) + payment received
            // We need to check BEFORE we update expected_cash with the payment
            $availableCash = $shift['starting_cash'] + $shift['expected_cash'] + $totalCashPaid;
            $borrowedAmount = 0;
            
            if ($availableCash < $change) {
                // Not enough cash in drawer - change is being borrowed from outside
                $borrowedAmount = $change - $availableCash;
                $borrowedAmount = max(0, $borrowedAmount); // Ensure non-negative
            }
            
            $changeTransaction = [
                'shift_id' => $shift['id'],
                'transaction_type' => 'pay_out',
                'amount' => $change,
                'reason' => 'Change Given',
                'notes' => 'Change for receipt ' . $receiptNumber . ($borrowedAmount > 0 ? ' (Borrowed $' . number_format($borrowedAmount, 2) . ' from outside - needs to be repaid)' : ''),
                'user_id' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $db->insert('drawer_transactions', $changeTransaction);
            
            // Subtract change from expected_cash (since we added full payment above)
            $db->update('shifts', [
                'expected_cash' => $shift['expected_cash'] - $change
            ], ['id' => $shift['id']]);
        } catch (Exception $changeError) {
            // Log but don't fail - change recording is not critical
            error_log("Change transaction error: " . $changeError->getMessage());
        }
    }
    
    // Commit the transaction - check if transaction is still active
    $pdo = $db->getPdo();
    if ($pdo && $pdo->inTransaction()) {
        try {
            $db->commitTransaction();
        } catch (PDOException $commitError) {
            // If commit fails with "no active transaction", it might have auto-committed
            if (strpos($commitError->getMessage(), 'no active transaction') !== false) {
                error_log("Transaction may have auto-committed: " . $commitError->getMessage());
            } else {
                throw $commitError;
            }
        }
    }
    
    // Clear the global transaction db reference
    unset($GLOBALS['current_transaction_db']);
    
    // Log activity outside of transaction (in case it fails, we don't want to rollback the sale)
    try {
        logActivity($userId, 'pos_sale', ['sale_id' => $saleId, 'receipt_number' => $receiptNumber, 'amount' => $total]);
    } catch (Exception $logError) {
        // Log the logging error but don't fail the sale
        error_log("Failed to log activity: " . $logError->getMessage());
    }
    
    // Fiscalize sale BEFORE sending response (so QR code is available for receipt)
    error_log("========================================");
    error_log("PROCESS SALE: FISCALIZATION CHECK START");
    error_log("PROCESS SALE: Sale ID = $saleId");
    error_log("PROCESS SALE: Branch ID = " . ($branchId ?? 'NULL'));
    error_log("PROCESS SALE: User ID = " . ($userId ?? 'NULL'));
    error_log("PROCESS SALE: Session branch_id = " . ($_SESSION['branch_id'] ?? 'NOT SET'));
    
    $fiscalDetails = null;
    $fiscalizationSuccess = false;
    $fiscalizationError = null;
    
    if ($branchId) {
        error_log("PROCESS SALE: Branch ID is set, proceeding with fiscalization check");
        error_log("FISCALIZATION: Attempting to fiscalize sale $saleId for branch $branchId");
        try {
            // Suppress any output from fiscalization
            ob_start();
            require_once APP_PATH . '/includes/fiscal_helper.php';
            error_log("FISCALIZATION: fiscal_helper.php loaded, calling fiscalizeSale()");
            $result = fiscalizeSale($saleId, $branchId, $db);
            ob_end_clean(); // Discard any output
            
            if ($result && is_array($result)) {
                error_log("FISCALIZATION: ✓ Successfully fiscalized sale $saleId");
                $fiscalizationSuccess = true;
                
                // Get fiscal details from the result or from database
                $primaryDb = Database::getPrimaryInstance();
                $fiscalReceipt = $primaryDb->getRow(
                    "SELECT receipt_qr_code, receipt_qr_data, receipt_verification_code, receipt_global_no, receipt_id 
                     FROM fiscal_receipts 
                     WHERE sale_id = :sale_id 
                     ORDER BY id DESC LIMIT 1",
                    [':sale_id' => $saleId]
                );
                
                if ($fiscalReceipt) {
                    $fiscalDetails = [
                        'fiscalized' => true,
                        'receipt_id' => $fiscalReceipt['receipt_id'],
                        'receipt_global_no' => $fiscalReceipt['receipt_global_no'],
                        'verification_code' => $fiscalReceipt['receipt_verification_code'],
                        'qr_code' => $fiscalReceipt['receipt_qr_code'], // Base64 encoded QR image
                        'qr_data' => $fiscalReceipt['receipt_qr_data']
                    ];
                    error_log("FISCALIZATION: Fiscal details retrieved for response");
                } else {
                    // Fallback: get from sale record
                    $sale = $db->getRow("SELECT fiscal_details FROM sales WHERE id = :id", [':id' => $saleId]);
                    if ($sale && $sale['fiscal_details']) {
                        $fiscalDetails = json_decode($sale['fiscal_details'], true);
                        $fiscalDetails['fiscalized'] = true;
                    }
                }
            } else {
                error_log("FISCALIZATION: ✗ fiscalizeSale returned false for sale $saleId");
                // If fiscalizeSale returns false (not an exception), it means fiscalization is disabled or not applicable
                // Don't set an error in this case - it's expected behavior
            }
        } catch (Exception $e) {
            // Capture ZIMRA error for display to user
            ob_end_clean(); // Clean any output from error
            $errorMessage = $e->getMessage();
            error_log("FISCALIZATION ERROR for sale $saleId: " . $errorMessage);
            error_log("FISCALIZATION STACK TRACE: " . $e->getTraceAsString());
            
            // Extract ZIMRA error details
            if (strpos($errorMessage, 'ZIMRA API Error') !== false) {
                // Format: "ZIMRA API Error ($errorCode): $errorMessage | Validation errors: ... | Full response: ..."
                $fiscalizationError = $errorMessage;
            } else {
                // Other errors (connection, etc.)
                $fiscalizationError = 'Fiscalization Error: ' . $errorMessage;
            }
            // Sale will still be returned, but without fiscal details
        } catch (Error $e) {
            // Catch fatal errors too
            ob_end_clean();
            $errorMessage = $e->getMessage();
            error_log("FISCALIZATION FATAL ERROR for sale $saleId: " . $errorMessage);
            error_log("FISCALIZATION FATAL STACK TRACE: " . $e->getTraceAsString());
            $fiscalizationError = 'Fatal Fiscalization Error: ' . $errorMessage;
        }
    } else {
        error_log("FISCALIZATION: ✗ branchId is null or empty (" . var_export($branchId, true) . "), skipping fiscalization");
        error_log("FISCALIZATION: Session branch_id = " . ($_SESSION['branch_id'] ?? 'NOT SET'));
        error_log("FISCALIZATION: This is why fiscalization was not called!");
    }
    error_log("PROCESS SALE: FISCALIZATION CHECK END");
    error_log("========================================");
    
    // Clear any output and send JSON (including fiscal details if available)
    ob_clean();
    $responseData = [
        'success' => true, 
        'message' => 'Sale processed successfully', 
        'receipt_id' => $saleId,
        'receipt_number' => $receiptNumber
    ];
    
    // Include fiscal details in response if fiscalization was successful
    if ($fiscalDetails) {
        $responseData['fiscal_details'] = $fiscalDetails;
        error_log("PROCESS SALE: Including fiscal details in response");
    }
    
    // Include fiscalization error if it occurred (sale still succeeded, but fiscalization failed)
    if ($fiscalizationError) {
        $responseData['fiscalization_error'] = $fiscalizationError;
        error_log("PROCESS SALE: Including fiscalization error in response: " . $fiscalizationError);
    }
    
    $response = json_encode($responseData);
    
    // End output buffering and send response
    ob_end_clean();
    echo $response;
    exit;
    
} catch (Exception $e) {
    // Only try to rollback if we have a db instance and transaction might be active
    if (isset($db)) {
        try {
            // Check if there's an active transaction before trying to rollback
            $pdo = $db->getPdo();
            if ($pdo && $pdo->inTransaction()) {
                $db->rollbackTransaction();
            }
        } catch (Exception $rollbackError) {
            // Ignore rollback errors - transaction might already be committed or not started
            error_log("Rollback error (non-critical): " . $rollbackError->getMessage());
        }
    }
    
    // Clear the global transaction db reference
    unset($GLOBALS['current_transaction_db']);
    
    logError("POS sale error: " . $e->getMessage());
    
    // Clear any output and send JSON error
    ob_clean();
    $response = json_encode(['success' => false, 'message' => 'Failed to process sale: ' . $e->getMessage()]);
    
    // End output buffering and send response
    ob_end_clean();
    echo $response;
    exit;
}
