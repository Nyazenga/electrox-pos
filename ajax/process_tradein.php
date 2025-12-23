<?php
// Start output buffering and suppress errors BEFORE any includes
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

// Suppress any output from includes
@require_once dirname(dirname(__FILE__)) . '/config.php';
@require_once APP_PATH . '/includes/db.php';
@require_once APP_PATH . '/includes/auth.php';
@require_once APP_PATH . '/includes/functions.php';

// Clear any output that might have been generated
ob_clean();
header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    $auth->requirePermission('tradeins.create');
} catch (Exception $permError) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Permission denied: ' . $permError->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$tradeInId = intval($input['trade_in_id'] ?? 0);

if (!$tradeInId) {
    error_log("Process trade-in: Invalid trade-in ID. Input: " . json_encode($input));
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid trade-in ID']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // First check if trade-in exists at all
    $tradeInCheck = $db->getRow("SELECT * FROM trade_ins WHERE id = :id", [':id' => $tradeInId]);
    if (!$tradeInCheck) {
        error_log("Process trade-in: Trade-in ID $tradeInId not found in database");
        throw new Exception("Trade-in with ID $tradeInId not found");
    }
    
    // Check if it's already processed
    if ($tradeInCheck['status'] === 'Processed') {
        throw new Exception('This trade-in has already been processed');
    }
    
    $db->beginTransaction();
    
    // Get trade-in with status check (should be 'Accepted' from POS)
    $tradeIn = $db->getRow("SELECT * FROM trade_ins WHERE id = :id AND status = 'Accepted'", [':id' => $tradeInId]);
    
    if (!$tradeIn) {
        error_log("Process trade-in: Trade-in ID $tradeInId exists but status is '{$tradeInCheck['status']}', not 'Accepted'");
        throw new Exception("Trade-in found but status is '{$tradeInCheck['status']}'. Expected 'Accepted'.");
    }
    
    $branchId = $tradeIn['branch_id'] ?? $_SESSION['branch_id'];
    $userId = $_SESSION['user_id'];
    
    // Get current shift
    $shift = $db->getRow("SELECT * FROM shifts WHERE branch_id = :branch_id AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
        ':branch_id' => $branchId,
        ':user_id' => $userId
    ]);
    
    if (!$shift) {
        // Create shift if needed
        $lastShift = $db->getRow("SELECT shift_number FROM shifts WHERE branch_id = :branch_id ORDER BY id DESC LIMIT 1", [':branch_id' => $branchId]);
        $shiftNumber = ($lastShift ? $lastShift['shift_number'] : 0) + 1;
        
        $shiftId = $db->insert('shifts', [
            'shift_number' => $shiftNumber,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'opened_at' => date('Y-m-d H:i:s'),
            'opened_by' => $userId,
            'starting_cash' => 0.00,
            'status' => 'open'
        ]);
        
        $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [':id' => $shiftId]);
    }
    
    // Generate receipt number with retry logic to handle race conditions (format: BRANCH-DATE-SEQ)
    $datePart = date('ymd');
    $branchPrefix = $branchId ?? 0;
    $maxRetries = 20;
    $receiptNumber = null;
    
    for ($retry = 0; $retry < $maxRetries; $retry++) {
        // Get the maximum sequence number for today
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
            $receiptNum = $maxReceipt['receipt_number'];
            $prefix = $branchPrefix . '-' . $datePart . '-';
            
            if (strpos($receiptNum, $prefix) === 0) {
                $seqPart = substr($receiptNum, strlen($prefix));
                // Remove any suffix (e.g., "-A12") if present
                if (preg_match('/^(\d+)/', $seqPart, $matches)) {
                    $seq = intval($matches[1]) + 1;
                }
            }
        }
        
        // Add retry offset to handle concurrent requests
        $seq += $retry;
        
        // Pad sequence to 4 digits
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
        
        // Small random delay to avoid rapid retries
        if ($retry < $maxRetries - 1) {
            usleep(rand(10000, 50000)); // 10-50ms random delay
        }
    }
    
    // Fallback: if all retries failed, use random suffix
    if (!$receiptNumber || $retry >= $maxRetries) {
        $randomSuffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 2));
        $seq = ($seq ?? 9999);
        $seqPadded = str_pad($seq, 4, '0', STR_PAD_LEFT);
        $receiptNumber = $branchPrefix . '-' . $datePart . '-' . $seqPadded . '-' . $randomSuffix;
    }
    
    // Create sale for trade-in value
    $saleData = [
        'receipt_number' => $receiptNumber,
        'shift_id' => $shift['id'],
        'branch_id' => $branchId,
        'user_id' => $userId,
        'customer_id' => $tradeIn['customer_id'],
        'sale_date' => date('Y-m-d H:i:s'),
        'subtotal' => $tradeIn['final_valuation'],
        'discount_type' => null,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => $tradeIn['final_valuation'],
        'payment_status' => 'paid',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $saleId = $db->insert('sales', $saleData);
    
    // Extract product details from valuation_notes if stored as JSON
    $productDetails = [];
    if (!empty($tradeIn['valuation_notes']) && strpos($tradeIn['valuation_notes'], 'PRODUCT_DETAILS_JSON:') !== false) {
        $jsonPart = substr($tradeIn['valuation_notes'], strpos($tradeIn['valuation_notes'], 'PRODUCT_DETAILS_JSON:') + strlen('PRODUCT_DETAILS_JSON:'));
        $productDetails = json_decode($jsonPart, true) ?? [];
    }
    
    // Get category ID from device category name
    $categoryId = null;
    $deviceCategory = $tradeIn['device_category'] ?? ($productDetails['device_category'] ?? null);
    if (!empty($deviceCategory)) {
        $category = $db->getRow("SELECT id FROM product_categories WHERE name = :name", [':name' => $deviceCategory]);
        $categoryId = $category ? $category['id'] : null;
    }
    
    // Generate unique product code with retry logic
    $maxRetries = 10;
    $productCode = null;
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        $code = generateProductCode();
        $exists = $db->getRow("SELECT id FROM products WHERE product_code = :code", [':code' => $code]);
        if (!$exists) {
            $productCode = $code;
            break;
        }
        if ($attempt === $maxRetries - 1) {
            // Fallback: use microtime + random
            $productCode = 'PROD-' . date('Ymd') . '-' . substr(str_replace('.', '', microtime(true)), -8) . rand(100, 999);
        }
    }
    
    // Create product from traded-in device and add to stock
    $productData = [
        'product_code' => $productCode,
        'category_id' => $categoryId,
        'brand' => $tradeIn['device_brand'],
        'model' => $tradeIn['device_model'],
        'color' => $tradeIn['device_color'] ?? ($productDetails['device_color'] ?? null),
        'storage' => $tradeIn['device_storage'] ?? ($productDetails['device_storage'] ?? null),
        'sim_configuration' => $productDetails['sim_configuration'] ?? null,
        'serial_number' => $productDetails['serial_number'] ?? null,
        'imei' => $productDetails['imei'] ?? null,
        'cost_price' => $productDetails['cost_price'] ?? $tradeIn['final_valuation'],
        'selling_price' => $productDetails['selling_price'] ?? ($tradeIn['final_valuation'] * 1.2), // Default 20% markup
        'condition' => 'Used', // Trade-in devices are used
        'status' => 'Active',
        'quantity_in_stock' => 1, // Add 1 to stock
        'branch_id' => $branchId,
        'description' => $productDetails['description'] ?? 'Trade-in device: ' . $tradeIn['device_brand'] . ' ' . $tradeIn['device_model'],
        'specifications' => $productDetails['specifications'] ?? null,
        'is_trade_in' => 1, // Flag this product as coming from a trade-in
        'created_by' => $userId,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    // Check if is_trade_in column exists, if not, remove it from data
    $columns = $db->getRows("SHOW COLUMNS FROM products WHERE Field = 'is_trade_in'");
    if (empty($columns) && isset($productData['is_trade_in'])) {
        unset($productData['is_trade_in']);
    }
    
    // Remove null values that might cause issues (but keep empty strings for text fields)
    foreach ($productData as $key => $value) {
        if ($value === null && in_array($key, ['sim_configuration', 'serial_number', 'imei', 'specifications', 'description'])) {
            // Keep null for these optional text fields
        } elseif ($value === null && !in_array($key, ['category_id', 'branch_id', 'created_by'])) {
            unset($productData[$key]);
        }
    }
    
    $tradedInProductId = $db->insert('products', $productData);
    
    if (!$tradedInProductId || $tradedInProductId === false) {
        $error = $db->getLastError();
        error_log("Failed to create product from trade-in. Error: " . $error);
        error_log("Product data: " . json_encode($productData));
        throw new Exception('Failed to create product from trade-in device. ' . ($error ?: 'Database error'));
    }
    
    // Create stock movement record for the traded-in device (added to stock)
    try {
        // Try 'Trade-In' first, fallback to 'Purchase' if enum doesn't support it
        try {
            $db->insert('stock_movements', [
                'product_id' => $tradedInProductId,
                'branch_id' => $branchId,
                'movement_type' => 'Trade-In',
                'quantity' => 1,
                'previous_quantity' => 0,
                'new_quantity' => 1,
                'user_id' => $userId,
                'notes' => 'Trade-In Device',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            // Fallback to 'Purchase' if 'Trade-In' is not in enum
            $db->insert('stock_movements', [
                'product_id' => $tradedInProductId,
                'branch_id' => $branchId,
                'movement_type' => 'Purchase',
                'quantity' => 1,
                'previous_quantity' => 0,
                'new_quantity' => 1,
                'user_id' => $userId,
                'notes' => 'Trade-In Device',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    } catch (Exception $stockError) {
        error_log("Stock movement creation error: " . $stockError->getMessage());
        // Don't fail the transaction if stock movement fails - stock is already set in product
    }
    
    // Create sale item for trade-in device (now linked to product)
    $itemData = [
        'sale_id' => $saleId,
        'product_id' => $tradedInProductId,
        'product_name' => 'Trade-In: ' . $tradeIn['device_brand'] . ' ' . $tradeIn['device_model'],
        'quantity' => 1,
        'unit_price' => $tradeIn['final_valuation'],
        'total_price' => $tradeIn['final_valuation'],
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $db->insert('sale_items', $itemData);
    
    // Create payment record
    $paymentData = [
        'sale_id' => $saleId,
        'payment_method' => 'trade_in',
        'amount' => $tradeIn['final_valuation'],
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $db->insert('sale_payments', $paymentData);
    
    // Update trade-in status
    $db->update('trade_ins', [
        'status' => 'Processed'
    ], ['id' => $tradeInId]);
    
    // If they're getting a new product, create that sale too
    if ($tradeIn['new_product_id']) {
        $newProduct = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $tradeIn['new_product_id']]);
        
        if ($newProduct) {
            $productPrice = $newProduct['selling_price'];
            $tradeInValue = $tradeIn['final_valuation'];
            $balance = $productPrice - $tradeInValue;
            
            if ($balance > 0) {
                // Create sale for new product with trade-in discount
                $productSaleData = [
                    'receipt_number' => '1-' . $datePart . ($seq + 1),
                    'shift_id' => $shift['id'],
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                    'customer_id' => $tradeIn['customer_id'],
                    'sale_date' => date('Y-m-d H:i:s'),
                    'subtotal' => $productPrice,
                    'discount_type' => 'value',
                    'discount_amount' => $tradeInValue,
                    'tax_amount' => 0,
                    'total_amount' => $balance,
                    'payment_status' => 'paid',
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $productSaleId = $db->insert('sales', $productSaleData);
                
                // Create sale item
                $productItemData = [
                    'sale_id' => $productSaleId,
                    'product_id' => $newProduct['id'],
                    'product_name' => $newProduct['brand'] . ' ' . $newProduct['model'],
                    'quantity' => 1,
                    'unit_price' => $productPrice,
                    'total_price' => $productPrice,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $db->insert('sale_items', $productItemData);
                
                // Update stock (deduct product they're getting)
                // Get current stock
                $currentProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id", [':id' => $newProduct['id']]);
                $previousQuantity = (int)($currentProduct['quantity_in_stock'] ?? 0);
                $newQuantity = max(0, $previousQuantity - 1);
                
                // Update product stock
                $db->update('products', [
                    'quantity_in_stock' => $newQuantity
                ], ['id' => $newProduct['id']]);
                
                // Create stock movement record
                try {
                    $db->insert('stock_movements', [
                        'product_id' => $newProduct['id'],
                        'branch_id' => $branchId,
                        'movement_type' => 'Sale',
                        'quantity' => -1,
                        'previous_quantity' => $previousQuantity,
                        'new_quantity' => $newQuantity,
                        'user_id' => $userId,
                        'notes' => 'Trade-In Sale',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                } catch (Exception $stockError) {
                    error_log("Stock movement creation error: " . $stockError->getMessage());
                    // Don't fail transaction if stock movement fails
                }
                
                // Create payment
                $productPaymentData = [
                    'sale_id' => $productSaleId,
                    'payment_method' => 'cash',
                    'amount' => $balance,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $db->insert('sale_payments', $productPaymentData);
            }
        }
    }
    
    $db->commitTransaction();
    
    // Log activity (wrap in try-catch to prevent errors from breaking response)
    try {
        logActivity($userId, 'tradein_processed', ['trade_in_id' => $tradeInId, 'sale_id' => $saleId]);
    } catch (Exception $logError) {
        error_log("Activity log error: " . $logError->getMessage());
        // Don't fail the response if logging fails
    }
    
    // Ensure clean output - clear any buffered output
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    
    echo json_encode([
        'success' => true, 
        'message' => 'Trade-in processed successfully',
        'sale_id' => $saleId,
        'trade_in_id' => $tradeInId
    ]);
    exit;
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollbackTransaction();
    }
    logError("Process trade-in error: " . $e->getMessage());
    
    // Ensure clean output - clear any buffered output
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    
    echo json_encode(['success' => false, 'message' => 'Failed to process trade-in: ' . $e->getMessage()]);
    exit;
}

