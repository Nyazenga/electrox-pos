<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

// Suppress errors for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json');

try {
    $auth = Auth::getInstance();
    if (!$auth->isLoggedIn()) {
        throw new Exception('Not authenticated');
    }
    
    $auth->requirePermission('invoices.edit');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['invoice_id']) || !isset($input['status'])) {
        throw new Exception('Missing required parameters');
    }
    
    $invoiceId = intval($input['invoice_id']);
    $status = $input['status'];
    
    $validStatuses = ['Draft', 'Sent', 'Viewed', 'Paid', 'Overdue', 'Void'];
    if (!in_array($status, $validStatuses)) {
        throw new Exception('Invalid status');
    }
    
    $db = Database::getInstance();
    $userId = $_SESSION['user_id'] ?? null;
    
    // Ensure POS tables exist
    @ensurePOSTables($db);
    
    // Add invoice_id column to sales table if it doesn't exist
    try {
        $columnCheck = $db->getRow("SHOW COLUMNS FROM sales WHERE Field = 'invoice_id'");
        if (!$columnCheck) {
            $db->executeQuery("ALTER TABLE sales ADD COLUMN invoice_id int(11) DEFAULT NULL AFTER customer_id, ADD KEY idx_invoice_id (invoice_id)");
        }
    } catch (Exception $e) {
        // Column might already exist, continue
        error_log("Invoice ID column check: " . $e->getMessage());
    }
    
    // Get full invoice details
    $invoice = $db->getRow("SELECT * FROM invoices WHERE id = :id", [':id' => $invoiceId]);
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    
    $oldStatus = $invoice['status'] ?? 'Draft';
    $branchId = $invoice['branch_id'] ?? $_SESSION['branch_id'] ?? null;
    $invoiceUserId = $invoice['user_id'] ?? $userId;
    
    // Begin transaction for status update, stock operations, and sale creation
    $db->beginTransaction();
    
    try {
        // Update status
        $result = $db->update('invoices', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $invoiceId]);
        
        if ($result === false) {
            throw new Exception('Failed to update invoice status');
        }
        
        // If status changed to "Paid", create sale record and process everything
        if ($status === 'Paid' && $oldStatus !== 'Paid') {
            // Check if sale already exists for this invoice
            $existingSale = $db->getRow("SELECT id FROM sales WHERE invoice_id = :invoice_id", [':invoice_id' => $invoiceId]);
            
            if ($existingSale) {
                // Sale already exists, skip creation but still process stock if needed
                logError("Sale already exists for invoice {$invoice['invoice_number']}, skipping sale creation");
            } else {
                // Get invoice items with product_id (only create sale for items with products)
                $invoiceItems = $db->getRows("SELECT ii.*, p.brand, p.model, p.quantity_in_stock
                    FROM invoice_items ii 
                    LEFT JOIN products p ON ii.product_id = p.id 
                    WHERE ii.invoice_id = :id AND ii.product_id IS NOT NULL", 
                    [':id' => $invoiceId]);
                
                if ($invoiceItems !== false && !empty($invoiceItems)) {
                    // VERIFY STOCK AVAILABILITY BEFORE ALLOWING PAYMENT
                    $insufficientStock = [];
                    foreach ($invoiceItems as $item) {
                        $productId = intval($item['product_id']);
                        $quantity = intval($item['quantity'] ?? 1);
                        $availableStock = intval($item['quantity_in_stock'] ?? 0);
                        
                        if ($productId > 0 && $quantity > 0) {
                            // Check stock for this branch
                            if ($branchId !== null) {
                                $product = $db->getRow("SELECT quantity_in_stock, brand, model FROM products WHERE id = :id AND branch_id = :branch_id", 
                                    [':id' => $productId, ':branch_id' => $branchId]);
                            } else {
                                $product = $db->getRow("SELECT SUM(quantity_in_stock) as quantity_in_stock, brand, model FROM products WHERE id = :id GROUP BY id", 
                                    [':id' => $productId]);
                            }
                            
                            $availableStock = $product ? intval($product['quantity_in_stock'] ?? 0) : 0;
                            $productName = trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? '')) ?: "Product #{$productId}";
                            
                            if ($availableStock < $quantity) {
                                $insufficientStock[] = "{$productName} (Available: {$availableStock}, Required: {$quantity})";
                            }
                        }
                    }
                    
                    if (!empty($insufficientStock)) {
                        throw new Exception('Cannot mark invoice as paid. Insufficient stock for the following products: ' . implode(', ', $insufficientStock));
                    }
                    // Get or create shift
                    $shift = null;
                    if ($branchId !== null) {
                        $shift = $db->getRow("SELECT * FROM shifts WHERE branch_id = :branch_id AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
                            ':branch_id' => $branchId,
                            ':user_id' => $invoiceUserId
                        ]);
                    } else {
                        $shift = $db->getRow("SELECT * FROM shifts WHERE (branch_id IS NULL OR branch_id = 0) AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
                            ':user_id' => $invoiceUserId
                        ]);
                    }
                    
                    // If no shift, create one
                    if (!$shift) {
                        if ($branchId !== null) {
                            $lastShift = $db->getRow("SELECT shift_number FROM shifts WHERE branch_id = :branch_id ORDER BY id DESC LIMIT 1", [':branch_id' => $branchId]);
                        } else {
                            $lastShift = $db->getRow("SELECT shift_number FROM shifts WHERE (branch_id IS NULL OR branch_id = 0) ORDER BY id DESC LIMIT 1");
                        }
                        $shiftNumber = ($lastShift ? intval($lastShift['shift_number']) : 0) + 1;
                        
                        $shiftData = [
                            'shift_number' => $shiftNumber,
                            'branch_id' => $branchId ?? 0,
                            'user_id' => $invoiceUserId,
                            'opened_at' => date('Y-m-d H:i:s'),
                            'opened_by' => $invoiceUserId,
                            'starting_cash' => 0.00,
                            'expected_cash' => 0.00,
                            'status' => 'open'
                        ];
                        
                        $shiftId = $db->insert('shifts', $shiftData);
                        if ($shiftId) {
                            $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [':id' => $shiftId]);
                        }
                    }
                    
                    if (!$shift) {
                        throw new Exception('Failed to get or create shift for invoice sale');
                    }
                    
                    // Generate receipt number (format: BRANCH-DATE-SEQ)
                    $datePart = date('ymd');
                    $branchPrefix = $branchId ?? 0;
                    $maxRetries = 20;
                    $receiptNumber = null;
                    
                    for ($retry = 0; $retry < $maxRetries; $retry++) {
                        $pattern = $branchPrefix . '-' . $datePart . '-%';
                        $maxReceipt = $db->getRow("SELECT receipt_number FROM sales WHERE receipt_number LIKE :pattern ORDER BY receipt_number DESC LIMIT 1", 
                            [':pattern' => $pattern]);
                        
                        $seq = 1;
                        if ($maxReceipt && isset($maxReceipt['receipt_number'])) {
                            $receiptNum = $maxReceipt['receipt_number'];
                            $prefix = $branchPrefix . '-' . $datePart . '-';
                            
                            if (strpos($receiptNum, $prefix) === 0) {
                                $seqPart = substr($receiptNum, strlen($prefix));
                                if (preg_match('/^(\d+)/', $seqPart, $matches)) {
                                    $seq = intval($matches[1]) + 1;
                                }
                            }
                        }
                        
                        $seq += $retry;
                        $seqPadded = str_pad($seq, 4, '0', STR_PAD_LEFT);
                        $receiptNumber = $branchPrefix . '-' . $datePart . '-' . $seqPadded;
                        
                        // Check if receipt number already exists
                        $existing = $db->getRow("SELECT id FROM sales WHERE receipt_number = :receipt_number", [
                            ':receipt_number' => $receiptNumber
                        ]);
                        
                        if (!$existing) {
                            break;
                        }
                        
                        if ($retry < $maxRetries - 1) {
                            usleep(rand(10000, 50000));
                        }
                    }
                    
                    if (!$receiptNumber || $retry >= $maxRetries) {
                        // Last resort: use random suffix
                        $randomSuffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 2));
                        $seq = ($seq ?? 9999);
                        $seqPadded = str_pad($seq, 4, '0', STR_PAD_LEFT);
                        $receiptNumber = $branchPrefix . '-' . $datePart . '-' . $seqPadded . '-' . $randomSuffix;
                    }
                    
                    // Calculate totals from invoice
                    $subtotal = floatval($invoice['subtotal'] ?? 0);
                    $discountAmount = floatval($invoice['discount_amount'] ?? 0);
                    $taxAmount = floatval($invoice['tax_amount'] ?? 0);
                    $total = floatval($invoice['total_amount'] ?? 0);
                    
                    // Determine discount type
                    $discountType = null;
                    if ($discountAmount > 0) {
                        // Check if it's a percentage (if discount_amount matches percentage calculation)
                        $percentageCheck = ($subtotal * ($discountAmount / $subtotal * 100)) / 100;
                        if (abs($percentageCheck - $discountAmount) < 0.01) {
                            $discountType = 'percentage';
                        } else {
                            $discountType = 'value';
                        }
                    }
                    
                    // Create sale record
                    $saleData = [
                        'receipt_number' => $receiptNumber,
                        'shift_id' => $shift['id'],
                        'branch_id' => $branchId,
                        'user_id' => $invoiceUserId,
                        'customer_id' => $invoice['customer_id'] ?? null,
                        'invoice_id' => $invoiceId, // Link to invoice
                        'sale_date' => $invoice['invoice_date'] ?? date('Y-m-d H:i:s'),
                        'subtotal' => $subtotal,
                        'discount_type' => $discountType,
                        'discount_amount' => $discountAmount,
                        'tax_amount' => $taxAmount,
                        'total_amount' => $total,
                        'payment_status' => 'paid',
                        'notes' => 'Created from invoice: ' . $invoice['invoice_number'],
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $saleId = $db->insert('sales', $saleData);
                    
                    if (!$saleId) {
                        throw new Exception('Failed to create sale record: ' . $db->getLastError());
                    }
                    
                    // Create sale items from invoice items
                    foreach ($invoiceItems as $item) {
                        $productId = intval($item['product_id']);
                        $quantity = intval($item['quantity'] ?? 1);
                        $unitPrice = floatval($item['unit_price'] ?? 0);
                        $lineTotal = floatval($item['line_total'] ?? ($unitPrice * $quantity));
                        
                        // Get product name
                        $productName = '';
                        if (!empty($item['brand']) || !empty($item['model'])) {
                            $productName = trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''));
                        }
                        if (empty($productName)) {
                            $product = $db->getRow("SELECT brand, model FROM products WHERE id = :id", [':id' => $productId]);
                            if ($product) {
                                $productName = trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? ''));
                            }
                        }
                        if (empty($productName)) {
                            $productName = 'Product #' . $productId;
                        }
                        
                        $itemData = [
                            'sale_id' => $saleId,
                            'product_id' => $productId,
                            'product_name' => $productName,
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'total_price' => $lineTotal,
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        $itemId = $db->insert('sale_items', $itemData);
                        if (!$itemId) {
                            throw new Exception('Failed to create sale item: ' . $db->getLastError());
                        }
                        
                        // Deduct stock
                        if (function_exists('updateStock')) {
                            $GLOBALS['current_transaction_db'] = $db;
                            updateStock($productId, -$quantity, $branchId, 'Sale', true);
                        } else {
                            // Manual stock update
                            $product = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id", [':id' => $productId]);
                            
                            if ($product !== false) {
                                $previousQuantity = (int)($product['quantity_in_stock'] ?? 0);
                                $newQuantity = max(0, $previousQuantity - $quantity);
                                
                                $db->update('products', ['quantity_in_stock' => $newQuantity], ['id' => $productId]);
                                
                                $db->insert('stock_movements', [
                                    'product_id' => $productId,
                                    'branch_id' => $branchId,
                                    'movement_type' => 'Sale',
                                    'quantity' => -$quantity,
                                    'previous_quantity' => $previousQuantity,
                                    'new_quantity' => $newQuantity,
                                    'reference_id' => $invoiceId,
                                    'reference_type' => 'Invoice',
                                    'user_id' => $userId,
                                    'notes' => 'Invoice: ' . $invoice['invoice_number'],
                                    'created_at' => date('Y-m-d H:i:s')
                                ]);
                            }
                        }
                    }
                    
                    // Get base currency
                    $baseCurrency = getBaseCurrency($db);
                    $baseCurrencyId = $baseCurrency ? $baseCurrency['id'] : null;
                    
                    // Create payment records from invoice payments table
                    $invoicePayments = $db->getRows("SELECT * FROM payments WHERE invoice_id = :invoice_id AND status = 'Completed'", 
                        [':invoice_id' => $invoiceId]);
                    
                    if ($invoicePayments !== false && !empty($invoicePayments)) {
                        // Use payments from payments table
                        foreach ($invoicePayments as $invPayment) {
                            $paymentMethod = $invPayment['payment_method'] ?? 'cash';
                            $originalAmount = floatval($invPayment['amount'] ?? 0);
                            $currencyCode = $invPayment['currency'] ?? 'USD';
                            $exchangeRate = floatval($invPayment['exchange_rate'] ?? 1.0);
                            
                            // Get currency ID
                            $currencyId = null;
                            if ($currencyCode && $currencyCode !== 'USD') {
                                $currency = $db->getRow("SELECT id FROM currencies WHERE code = :code", [':code' => $currencyCode]);
                                if ($currency) {
                                    $currencyId = $currency['id'];
                                }
                            }
                            if (!$currencyId) {
                                $currencyId = $baseCurrencyId;
                            }
                            
                            // Calculate base amount
                            $baseAmount = $originalAmount * $exchangeRate;
                            
                            $paymentData = [
                                'sale_id' => $saleId,
                                'payment_method' => strtolower($paymentMethod),
                                'currency_id' => $currencyId,
                                'exchange_rate' => $exchangeRate,
                                'original_amount' => $originalAmount,
                                'base_amount' => $baseAmount,
                                'amount' => $baseAmount,
                                'reference' => $invPayment['reference_number'] ?? $invPayment['transaction_id'] ?? null,
                                'created_at' => date('Y-m-d H:i:s')
                            ];
                            
                            $paymentId = $db->insert('sale_payments', $paymentData);
                            if (!$paymentId) {
                                throw new Exception('Failed to create payment record: ' . $db->getLastError());
                            }
                        }
                    } else {
                        // No payments in payments table, create default payment
                        // Try to get payment method from invoice payment_methods field
                        $paymentMethod = 'cash';
                        if (!empty($invoice['payment_methods'])) {
                            $paymentMethods = json_decode($invoice['payment_methods'], true);
                            if (is_array($paymentMethods) && !empty($paymentMethods)) {
                                $firstMethod = $paymentMethods[0];
                                // Extract method name (e.g., "USD Cash" -> "cash")
                                if (is_string($firstMethod)) {
                                    $parts = explode(' ', $firstMethod);
                                    $paymentMethod = strtolower(end($parts));
                                }
                            }
                        }
                        
                        $paymentData = [
                            'sale_id' => $saleId,
                            'payment_method' => $paymentMethod,
                            'currency_id' => $baseCurrencyId,
                            'exchange_rate' => 1.0,
                            'original_amount' => $total,
                            'base_amount' => $total,
                            'amount' => $total,
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
                    
                    // Update shift expected cash for cash payments
                    $cashPayments = $db->getRows("SELECT base_amount, amount FROM sale_payments WHERE sale_id = :sale_id AND LOWER(payment_method) = 'cash'", 
                        [':sale_id' => $saleId]);
                    
                    $totalCashPaid = 0;
                    if ($cashPayments !== false) {
                        foreach ($cashPayments as $cp) {
                            $totalCashPaid += floatval($cp['base_amount'] ?? $cp['amount'] ?? 0);
                        }
                    }
                    
                    if ($totalCashPaid > 0) {
                        $db->update('shifts', [
                            'expected_cash' => $shift['expected_cash'] + $totalCashPaid
                        ], ['id' => $shift['id']]);
                    }
                }
            }
        }
        
        // If status changed from "Paid" to something else, reverse sale and restore stock
        if ($oldStatus === 'Paid' && $status !== 'Paid') {
            // Find sale linked to this invoice
            $sale = $db->getRow("SELECT * FROM sales WHERE invoice_id = :invoice_id", [':invoice_id' => $invoiceId]);
            
            if ($sale) {
                // Get sale items
                $saleItems = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :sale_id", [':sale_id' => $sale['id']]);
                
                if ($saleItems !== false && !empty($saleItems)) {
                    // Restore stock for each item
                    foreach ($saleItems as $item) {
                        $productId = intval($item['product_id']);
                        $quantity = intval($item['quantity'] ?? 1);
                        
                        if ($productId > 0 && $quantity > 0) {
                            if (function_exists('updateStock')) {
                                $GLOBALS['current_transaction_db'] = $db;
                                updateStock($productId, $quantity, $branchId, 'Return', true);
                            } else {
                                // Manual stock update
                                $product = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id", [':id' => $productId]);
                                
                                if ($product !== false) {
                                    $previousQuantity = (int)($product['quantity_in_stock'] ?? 0);
                                    $newQuantity = $previousQuantity + $quantity;
                                    
                                    $db->update('products', ['quantity_in_stock' => $newQuantity], ['id' => $productId]);
                                    
                                    $db->insert('stock_movements', [
                                        'product_id' => $productId,
                                        'branch_id' => $branchId,
                                        'movement_type' => 'Return',
                                        'quantity' => $quantity,
                                        'previous_quantity' => $previousQuantity,
                                        'new_quantity' => $newQuantity,
                                        'reference_id' => $invoiceId,
                                        'reference_type' => 'Invoice',
                                        'user_id' => $userId,
                                        'notes' => 'Invoice Status Reversal: ' . $invoice['invoice_number'],
                                        'created_at' => date('Y-m-d H:i:s')
                                    ]);
                                }
                            }
                        }
                    }
                    
                    // Reverse shift expected cash for cash payments
                    $cashPayments = $db->getRows("SELECT base_amount, amount FROM sale_payments WHERE sale_id = :sale_id AND LOWER(payment_method) = 'cash'", 
                        [':sale_id' => $sale['id']]);
                    
                    $totalCashPaid = 0;
                    if ($cashPayments !== false) {
                        foreach ($cashPayments as $cp) {
                            $totalCashPaid += floatval($cp['base_amount'] ?? $cp['amount'] ?? 0);
                        }
                    }
                    
                    if ($totalCashPaid > 0 && $sale['shift_id']) {
                        $shift = $db->getRow("SELECT expected_cash FROM shifts WHERE id = :id", [':id' => $sale['shift_id']]);
                        if ($shift) {
                            $newExpectedCash = max(0, $shift['expected_cash'] - $totalCashPaid);
                            $db->update('shifts', ['expected_cash' => $newExpectedCash], ['id' => $sale['shift_id']]);
                        }
                    }
                    
                    // Delete sale payments
                    $db->executeQuery("DELETE FROM sale_payments WHERE sale_id = :sale_id", [':sale_id' => $sale['id']]);
                    
                    // Delete sale items
                    $db->executeQuery("DELETE FROM sale_items WHERE sale_id = :sale_id", [':sale_id' => $sale['id']]);
                    
                    // Delete sale
                    $db->executeQuery("DELETE FROM sales WHERE id = :sale_id", [':sale_id' => $sale['id']]);
                }
            }
            
            // Also restore stock for invoice items (in case sale wasn't created)
            $invoiceItems = $db->getRows("SELECT * FROM invoice_items WHERE invoice_id = :id AND product_id IS NOT NULL", [':id' => $invoiceId]);
            
            if ($invoiceItems !== false && !empty($invoiceItems)) {
                foreach ($invoiceItems as $item) {
                    $productId = intval($item['product_id']);
                    $quantity = intval($item['quantity'] ?? 1);
                    
                    if ($productId > 0 && $quantity > 0) {
                        if (function_exists('updateStock')) {
                            $GLOBALS['current_transaction_db'] = $db;
                            updateStock($productId, $quantity, $branchId, 'Return', true);
                        }
                    }
                }
            }
        }
        
        $db->commitTransaction();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        throw $e;
    }
    
    // Log activity
    try {
        logActivity($userId, 'invoice.status_changed', [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice['invoice_number'],
            'old_status' => $oldStatus,
            'new_status' => $status
        ]);
    } catch (Exception $e) {
        // Log error but don't fail the request
        error_log("Failed to log activity: " . $e->getMessage());
    }
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Invoice status updated successfully'
    ]);
    exit;
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(400);
    $errorMessage = $e->getMessage();
    // Log the full error for debugging
    logError("Invoice status update error: " . $errorMessage . " | Invoice ID: " . ($invoiceId ?? 'unknown'));
    echo json_encode([
        'success' => false,
        'message' => $errorMessage ?: 'An error occurred while updating the status'
    ]);
    exit;
}
