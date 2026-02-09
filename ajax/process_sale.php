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
require_once APP_PATH . '/includes/settings_functions.php';

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
    
    // Check if fiscalization should be skipped
    $skipFiscalization = isset($input['skip_fiscalization']) && ($input['skip_fiscalization'] == 1 || $input['skip_fiscalization'] === true || $input['skip_fiscalization'] === '1');
    
    // Check if fiscalization is enabled - if so, verify fiscal day is open BEFORE creating sale
    // Skip this check if skip_fiscalization flag is set (non-fiscal sales don't need fiscal day to be open)
    if ($branchId && !$skipFiscalization) {
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
    
    // Get prices_include_tax setting
    $pricesIncludeTax = getSetting('prices_include_tax', '1') == '1';
    $defaultTaxRate = getDefaultTaxRate();
    
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
    
    $deliveryCost = isset($input['delivery_cost']) ? floatval($input['delivery_cost']) : 0;
    
    // Calculate tax based on prices_include_tax setting
    $taxAmount = 0;
    if ($pricesIncludeTax) {
        // Prices already include tax
        // Calculate: subtotal (without tax) from total (with tax)
        // total_with_tax = subtotal_without_tax * (1 + tax_rate)
        // subtotal_without_tax = total_with_tax / (1 + tax_rate)
        $totalWithTax = $subtotal - $discountAmount + $deliveryCost;
        if ($defaultTaxRate > 0) {
            $taxDecimal = $defaultTaxRate / 100;
            $subtotalWithoutTax = $totalWithTax / (1 + $taxDecimal);
            $taxAmount = $totalWithTax - $subtotalWithoutTax;
            $total = $totalWithTax; // Total stays the same (includes tax)
            $subtotal = $subtotalWithoutTax; // Update subtotal to exclude tax
        } else {
            $total = $subtotal - $discountAmount + $deliveryCost;
            $taxAmount = 0;
        }
    } else {
        // Prices do NOT include tax - add tax on top
        $subtotalAfterDiscount = $subtotal - $discountAmount;
        if ($defaultTaxRate > 0) {
            $taxDecimal = $defaultTaxRate / 100;
            $taxAmount = $subtotalAfterDiscount * $taxDecimal;
        }
        $total = $subtotalAfterDiscount + $taxAmount + $deliveryCost;
    }
    
    // Create sale record
    $customerId = null;
    if (isset($input['customer']) && is_array($input['customer']) && isset($input['customer']['id'])) {
        $customerId = $input['customer']['id'];
    }
    
    // Check if this is a credit sale
    $isCreditSale = isset($input['is_credit_sale']) && $input['is_credit_sale'] === true;
    $paymentTermId = isset($input['payment_term_id']) ? intval($input['payment_term_id']) : null;
    
    // Check if this is a wholesale sale
    $isWholesaleSale = isset($input['is_wholesale_sale']) && $input['is_wholesale_sale'] === true;
    $isPendingPayment = isset($input['is_pending_payment']) && $input['is_pending_payment'] === true;
    
    // Validate credit sale requirements
    if ($isCreditSale) {
        // Validate customer is selected
        if (!$customerId) {
            throw new Exception('Customer is required for credit sales');
        }
        
        // Validate payment terms
        if (!$paymentTermId) {
            throw new Exception('Payment terms are required for credit sales');
        }
    }
    
    // Get payments (will calculate total paid after we have base currency)
    $payments = $input['payments'] ?? [['method' => 'cash', 'amount' => $total]];
    
    // Calculate account balance (for credit sales) - will be calculated after we have base currency
    $accountBalance = 0;
    $paymentStatus = 'paid';
    
    $saleData = [
        'receipt_number' => $receiptNumber,
        'is_wholesale_sale' => $isWholesaleSale ? 1 : 0,
        'is_pending_payment' => $isPendingPayment ? 1 : 0,
        'shift_id' => $shift['id'],
        'branch_id' => $branchId,
        'user_id' => $userId,
        'customer_id' => $customerId,
        'sale_date' => date('Y-m-d H:i:s'),
        'subtotal' => $subtotal,
        'discount_type' => $discount['type'] ?? null,
        'discount_amount' => $discountAmount,
        'delivery_cost' => $deliveryCost,
        'tax_amount' => $taxAmount,
        'total_amount' => $total,
        'payment_status' => $paymentStatus,
        'is_credit_sale' => $isCreditSale ? 1 : 0,
        'payment_term_id' => $paymentTermId,
        'account_balance' => $accountBalance,
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
    
    // Get product tax rates from fiscal config if available (for accurate price storage)
    $productTaxRates = [];
    if ($branchId && $pricesIncludeTax) {
        try {
            $primaryDb = Database::getPrimaryInstance();
            $device = $primaryDb->getRow(
                "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1 LIMIT 1",
                [':branch_id' => $branchId]
            );
            
            if ($device) {
                $config = $primaryDb->getRow(
                    "SELECT applicable_taxes FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id LIMIT 1",
                    [':branch_id' => $branchId, ':device_id' => $device['device_id']]
                );
                
                if ($config && !empty($config['applicable_taxes'])) {
                    $applicableTaxes = json_decode($config['applicable_taxes'], true);
                    if (is_array($applicableTaxes)) {
                        // Create a map of taxID -> taxPercent for quick lookup
                        // CRITICAL: Include exempt taxes (taxPercent=null) with special marker
                        foreach ($applicableTaxes as $tax) {
                            if (isset($tax['taxID'])) {
                                $taxId = intval($tax['taxID']);
                                $taxCode = $tax['taxCode'] ?? '';
                                
                                // For exempt taxes (taxCode='E' or taxPercent=null), store as 0 (no tax extraction)
                                if ($taxCode === 'E' || (!isset($tax['taxPercent']) || $tax['taxPercent'] === null)) {
                                    $productTaxRates[$taxId] = 0; // Exempt = 0% (no tax to extract)
                                } elseif (isset($tax['taxPercent']) && $tax['taxPercent'] !== null) {
                                    $productTaxRates[$taxId] = floatval($tax['taxPercent']);
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // If fiscal config not available, fall back to default tax rate
            error_log("PROCESS SALE: Could not get fiscal config for tax rates: " . $e->getMessage());
        }
    }
    
    // Check if cart contains trade-in products (should not be fiscalized)
    $hasTradeInProducts = false;
    
    // Create sale items
    foreach ($input['cart'] as $item) {
        $product = $db->getRow("SELECT p.*, pc.tax_id as category_tax_id 
                                 FROM products p 
                                 LEFT JOIN product_categories pc ON p.category_id = pc.id 
                                 WHERE p.id = :id", [':id' => $item['id']]);
        
        if (!$product) {
            throw new Exception("Product not found: {$item['id']}");
        }
        
        // Check if product is a trade-in product
        if (!empty($product['is_trade_in']) && $product['is_trade_in'] == 1) {
            $hasTradeInProducts = true;
        }
        
        // Validate if product can be sold (for products requiring specific list)
        $requiresSpecificList = productRequiresSpecificList($product, $db);
        if ($requiresSpecificList) {
            if (!canSellProduct($item['id'], $branchId, $db)) {
                throw new Exception("Product '{$item['name']}' cannot be sold. Quantity must equal the number of available product_specific_list entries.");
            }
            
            // Validate specific list entries are provided
            $specificListEntries = $item['specific_list_entries'] ?? [];
            if (empty($specificListEntries) || count($specificListEntries) !== $item['quantity']) {
                throw new Exception("Product '{$item['name']}' requires specific instance details for all {$item['quantity']} items.");
            }
        }
        
        // Check if product has specific list entries with their own prices
        $specificItemPrices = [];
        $useSpecificItemPrices = false;
        $totalSpecificPrice = 0;
        
        if ($requiresSpecificList && !empty($specificListEntries)) {
            // Get prices from specific list entries
            foreach ($specificListEntries as $entry) {
                $specificId = intval($entry['id'] ?? 0);
                if ($specificId <= 0) {
                    // Try to find by serial number or IMEI
                    if (!empty($entry['serial_number'])) {
                        $existing = $db->getRow(
                            "SELECT id, selling_price, wholesale_price FROM product_specific_list WHERE serial_number = :serial AND product_id = :product_id AND branch_id = :branch_id AND status = 'available'",
                            [':serial' => $entry['serial_number'], ':product_id' => $product['id'], ':branch_id' => $branchId]
                        );
                        if ($existing) {
                            $specificId = $existing['id'];
                            $entry = $existing;
                        }
                    } elseif (!empty($entry['imei'])) {
                        $existing = $db->getRow(
                            "SELECT id, selling_price, wholesale_price FROM product_specific_list WHERE imei = :imei AND product_id = :product_id AND branch_id = :branch_id AND status = 'available'",
                            [':imei' => $entry['imei'], ':product_id' => $product['id'], ':branch_id' => $branchId]
                        );
                        if ($existing) {
                            $specificId = $existing['id'];
                            $entry = $existing;
                        }
                    }
                }
                
                if ($specificId > 0) {
                    $specificItem = $db->getRow(
                        "SELECT selling_price, wholesale_price FROM product_specific_list WHERE id = :id",
                        [':id' => $specificId]
                    );
                    if ($specificItem) {
                        // Use wholesale price if wholesale sale and available, otherwise use selling price
                        if ($isWholesaleSale && !empty($specificItem['wholesale_price']) && floatval($specificItem['wholesale_price']) > 0) {
                            $price = floatval($specificItem['wholesale_price']);
                            $specificItemPrices[] = $price;
                            $totalSpecificPrice += $price;
                        } elseif (!empty($specificItem['selling_price']) && floatval($specificItem['selling_price']) > 0) {
                            $price = floatval($specificItem['selling_price']);
                            $specificItemPrices[] = $price;
                            $totalSpecificPrice += $price;
                        }
                    }
                }
            }
            
            // If we have specific item prices for all items, use them
            if (!empty($specificItemPrices) && count($specificItemPrices) === $item['quantity']) {
                $useSpecificItemPrices = true;
            }
        }
        
        // Check if wholesale price should be used (fallback to product-level prices)
        $useWholesalePrice = !$useSpecificItemPrices && $isWholesaleSale && !empty($product['wholesale_price']) && floatval($product['wholesale_price']) > 0;
        
        // Calculate unit price without tax for storage
        // Priority: Specific item prices > Product wholesale price > Product selling price > Cart price
        if ($useSpecificItemPrices) {
            // Use average of specific item prices (all prices are tax-inclusive)
            $unitPriceWithTax = $totalSpecificPrice / count($specificItemPrices);
        } elseif ($useWholesalePrice) {
            $unitPriceWithTax = floatval($product['wholesale_price']);
        } else {
            $unitPriceWithTax = $item['price'];
        }
        $unitPriceWithoutTax = $unitPriceWithTax;
        
        if ($pricesIncludeTax) {
            // Get the ACTUAL tax rate for this product (not the default)
            $productTaxRate = null;
            $isExempt = false;
            
            // Priority 1: Product's own tax_id
            if (!empty($product['tax_id']) && isset($productTaxRates[intval($product['tax_id'])])) {
                $productTaxRate = $productTaxRates[intval($product['tax_id'])];
                // Check if this is exempt (taxRate = 0 from our map, or check taxCode from applicable taxes)
                if ($productTaxRate == 0) {
                    $isExempt = true;
                }
            }
            // Priority 2: Category's tax_id
            elseif (!empty($product['category_tax_id']) && isset($productTaxRates[intval($product['category_tax_id'])])) {
                $productTaxRate = $productTaxRates[intval($product['category_tax_id'])];
                if ($productTaxRate == 0) {
                    $isExempt = true;
                }
            }
            // Fallback: Use default tax rate (ONLY if not exempt)
            elseif ($defaultTaxRate > 0) {
                $productTaxRate = $defaultTaxRate;
            }
            
            // CRITICAL: For exempt products (taxRate = 0), do NOT extract tax
            // Price with tax = Price without tax for exempt items
            if ($isExempt || $productTaxRate == 0) {
                // Exempt: No tax extraction, price stays the same
                $unitPriceWithoutTax = $unitPriceWithTax;
                error_log("PROCESS SALE: Product '{$item['name']}' - EXEMPT TAX - Price: $unitPriceWithTax (no tax extraction)");
            } elseif ($productTaxRate > 0) {
                // Calculate price without tax using the product's actual tax rate
                $taxDecimal = $productTaxRate / 100;
                $unitPriceWithoutTax = $unitPriceWithTax / (1 + $taxDecimal);
                error_log("PROCESS SALE: Product '{$item['name']}' - Price with tax: $unitPriceWithTax, Tax rate: $productTaxRate%, Price without tax: $unitPriceWithoutTax");
            }
        }
        // If prices_include_tax is false, unitPriceWithoutTax = unitPriceWithTax (no change)
        
        // Store serial numbers and IMEI in JSON before deletion (for receipt display)
        $specificItemData = [];
        if ($requiresSpecificList && !empty($specificListEntries)) {
            foreach ($specificListEntries as $entry) {
                $specificId = intval($entry['id'] ?? 0);
                if ($specificId <= 0) {
                    // Try to find by serial number or IMEI
                    if (!empty($entry['serial_number'])) {
                        $existing = $db->getRow(
                            "SELECT id, serial_number, imei, color, storage FROM product_specific_list WHERE serial_number = :serial AND product_id = :product_id AND branch_id = :branch_id AND status = 'available'",
                            [':serial' => $entry['serial_number'], ':product_id' => $product['id'], ':branch_id' => $branchId]
                        );
                        if ($existing) {
                            $specificId = $existing['id'];
                            $entry = $existing;
                        }
                    } elseif (!empty($entry['imei'])) {
                        $existing = $db->getRow(
                            "SELECT id, serial_number, imei, color, storage FROM product_specific_list WHERE imei = :imei AND product_id = :product_id AND branch_id = :branch_id AND status = 'available'",
                            [':imei' => $entry['imei'], ':product_id' => $product['id'], ':branch_id' => $branchId]
                        );
                        if ($existing) {
                            $specificId = $existing['id'];
                            $entry = $existing;
                        }
                    }
                }
                
                if ($specificId > 0) {
                    $specificItem = $db->getRow(
                        "SELECT serial_number, imei, color, storage FROM product_specific_list WHERE id = :id",
                        [':id' => $specificId]
                    );
                    if ($specificItem) {
                        $specificItemData[] = [
                            'serial_number' => $specificItem['serial_number'] ?? null,
                            'imei' => $specificItem['imei'] ?? null,
                            'color' => $specificItem['color'] ?? null,
                            'storage' => $specificItem['storage'] ?? null
                        ];
                    }
                } elseif (!empty($entry['serial_number']) || !empty($entry['imei'])) {
                    // Use data from cart if available
                    $specificItemData[] = [
                        'serial_number' => $entry['serial_number'] ?? null,
                        'imei' => $entry['imei'] ?? null,
                        'color' => $entry['color'] ?? null,
                        'storage' => $entry['storage'] ?? null
                    ];
                }
            }
        }
        
        $itemData = [
            'sale_id' => $saleId,
            'product_id' => $item['id'],
            'product_name' => $item['name'],
            'quantity' => $item['quantity'],
            'unit_price' => $unitPriceWithoutTax, // Store price WITHOUT tax
            'total_price' => $unitPriceWithoutTax * $item['quantity'], // Store total WITHOUT tax
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Add specific_item_data as JSON if available (for receipt display after deletion)
        if (!empty($specificItemData)) {
            $itemData['specific_item_data'] = json_encode($specificItemData);
        }
        
        $itemId = $db->insert('sale_items', $itemData);
        if (!$itemId) {
            throw new Exception('Failed to create sale item: ' . $db->getLastError());
        }
        
        // Handle product_specific_list entries if required
        if ($requiresSpecificList && !empty($specificListEntries)) {
            foreach ($specificListEntries as $entry) {
                $specificId = intval($entry['id'] ?? 0);
                if ($specificId <= 0) {
                    // Try to find by serial number or IMEI
                    if (!empty($entry['serial_number'])) {
                        $existing = $db->getRow(
                            "SELECT id FROM product_specific_list WHERE serial_number = :serial AND product_id = :product_id AND branch_id = :branch_id AND status = 'available'",
                            [':serial' => $entry['serial_number'], ':product_id' => $product['id'], ':branch_id' => $branchId]
                        );
                        if ($existing) {
                            $specificId = $existing['id'];
                        }
                    } elseif (!empty($entry['imei'])) {
                        $existing = $db->getRow(
                            "SELECT id FROM product_specific_list WHERE imei = :imei AND product_id = :product_id AND branch_id = :branch_id AND status = 'available'",
                            [':imei' => $entry['imei'], ':product_id' => $product['id'], ':branch_id' => $branchId]
                        );
                        if ($existing) {
                            $specificId = $existing['id'];
                        }
                    }
                }
                
                if ($specificId > 0) {
                    // DELETE the product_specific_list entry when sold (not just mark as sold)
                    // This ensures it never appears in any lists again
                    $deleted = $db->delete('product_specific_list', ['id' => $specificId]);
                    if (!$deleted) {
                        error_log("Failed to delete product_specific_list entry {$specificId} after sale");
                        // Fallback: Mark as sold if delete fails
                        $db->update('product_specific_list', [
                            'status' => 'sold',
                            'sale_item_id' => $itemId
                        ], ['id' => $specificId]);
                    }
                }
            }
            
            // Update product quantity to match count of available entries
            $count = getProductSpecificListCount($product['id'], $branchId, 'available', $db);
            $db->update('products', ['quantity_in_stock' => $count], ['id' => $product['id']]);
            
            // Create stock movement record
            $previousQuantity = (int)($product['quantity_in_stock'] ?? 0);
            $db->insert('stock_movements', [
                'product_id' => $product['id'],
                'branch_id' => $branchId,
                'movement_type' => 'Sale',
                'quantity' => -$item['quantity'],
                'previous_quantity' => $previousQuantity,
                'new_quantity' => $count,
                'user_id' => $_SESSION['user_id'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            error_log("PROCESS SALE: Updated product {$product['id']} quantity from {$previousQuantity} to {$count} (sold {$item['quantity']} items from product_specific_list)");
        } else {
            // Normal product: Update stock using updateStock function
            if (function_exists('updateStock')) {
                try {
                    updateStock($item['id'], -$item['quantity'], $branchId, 'Sale', true);
                } catch (Exception $stockError) {
                    // Log stock update error but don't fail the sale
                    error_log("Stock update error for product {$item['id']}: " . $stockError->getMessage());
                }
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
    
    // Calculate account balance for credit sales (after payments are created)
    if ($isCreditSale) {
        // Calculate total paid from payment records
        $totalPaid = 0;
        foreach ($payments as $payment) {
            $baseAmount = isset($payment['base_amount']) ? floatval($payment['base_amount']) : floatval($payment['amount']);
            $totalPaid += $baseAmount;
        }
        
        $accountBalance = $total - $totalPaid;
        $paymentStatus = $accountBalance > 0 ? 'pending' : 'paid';
        
        // Update sale with account balance and payment status
        $db->update('sales', [
            'account_balance' => $accountBalance,
            'payment_status' => $paymentStatus
        ], ['id' => $saleId]);
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
    
    // Handle invoice conversion - update invoice status to Paid and link sale to invoice
    if (isset($_SESSION['invoice_to_sale']) && !empty($_SESSION['invoice_to_sale']['invoice_id'])) {
        $invoiceId = intval($_SESSION['invoice_to_sale']['invoice_id']);
        try {
            // Update invoice status to Paid
            $db->update('invoices', [
                'status' => 'Paid',
                'amount_paid' => $total,
                'balance_due' => 0
            ], ['id' => $invoiceId]);
            
            // Link sale to invoice (if invoice_id column exists in sales table)
            try {
                $db->update('sales', ['invoice_id' => $invoiceId], ['id' => $saleId]);
            } catch (Exception $e) {
                // Column might not exist, that's okay
                error_log("Could not link sale to invoice (column may not exist): " . $e->getMessage());
            }
            
            error_log("PROCESS SALE: Invoice $invoiceId marked as Paid and linked to sale $saleId");
            
            // Clear invoice conversion session data
            unset($_SESSION['invoice_to_sale']);
        } catch (Exception $invoiceError) {
            // Log error but don't fail the sale
            error_log("Failed to update invoice status: " . $invoiceError->getMessage());
        }
    }
    
    // Handle laybye completion - update laybye status to completed and link sale to laybye
    if (isset($_SESSION['laybye_to_sale']) && !empty($_SESSION['laybye_to_sale']['laybye_id'])) {
        $laybyeId = intval($_SESSION['laybye_to_sale']['laybye_id']);
        try {
            $primaryDb = Database::getPrimaryInstance();
            
            // Update laybye status to completed
            $primaryDb->update('laybyes', [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'sale_id' => $saleId
            ], ['id' => $laybyeId]);
            
            // Link sale to laybye (if laybye_id column exists in sales table)
            try {
                $db->update('sales', ['laybye_id' => $laybyeId, 'is_laybye' => 1], ['id' => $saleId]);
            } catch (Exception $e) {
                // Column might not exist, that's okay
                error_log("Could not link sale to laybye (column may not exist): " . $e->getMessage());
            }
            
            error_log("PROCESS SALE: Laybye $laybyeId marked as Completed and linked to sale $saleId");
            
            // Clear laybye conversion session data
            unset($_SESSION['laybye_to_sale']);
        } catch (Exception $laybyeError) {
            // Log error but don't fail the sale
            error_log("Failed to update laybye status: " . $laybyeError->getMessage());
        }
    }
    
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
    
    // Skip fiscalization if sale contains trade-in products OR if skip_fiscalization flag is set
    if ($hasTradeInProducts) {
        error_log("FISCALIZATION: ✗ Sale contains trade-in products, skipping fiscalization (trade-ins should not be fiscalized)");
    } else if ($skipFiscalization) {
        error_log("FISCALIZATION: ✗ skip_fiscalization flag is set, skipping fiscalization (non-fiscal sale requested)");
    } else if ($branchId) {
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
        if (!$hasTradeInProducts) {
            error_log("FISCALIZATION: ✗ branchId is null or empty (" . var_export($branchId, true) . "), skipping fiscalization");
            error_log("FISCALIZATION: Session branch_id = " . ($_SESSION['branch_id'] ?? 'NOT SET'));
            error_log("FISCALIZATION: This is why fiscalization was not called!");
        }
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
    
    // Log error with full details
    $errorMsg = "POS sale error: " . $e->getMessage();
    $errorMsg .= " | File: " . $e->getFile() . " | Line: " . $e->getLine();
    $errorMsg .= " | Trace: " . $e->getTraceAsString();
    error_log($errorMsg);
    if (function_exists('logError')) {
        logError($errorMsg);
    }
    
    // Clear any output and send JSON error
    ob_clean();
    $response = json_encode(['success' => false, 'message' => 'Failed to process sale: ' . $e->getMessage()]);
    
    // End output buffering and send response
    ob_end_clean();
    echo $response;
    exit;
} catch (Error $e) {
    // Catch fatal errors too
    $errorMsg = "POS sale FATAL error: " . $e->getMessage();
    $errorMsg .= " | File: " . $e->getFile() . " | Line: " . $e->getLine();
    error_log($errorMsg);
    if (function_exists('logError')) {
        logError($errorMsg);
    }
    
    ob_clean();
    $response = json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
    ob_end_clean();
    echo $response;
    exit;
}
