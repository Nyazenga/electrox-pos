<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$auth->requirePermission('receipts.refund');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['sale_id']) || !isset($input['items']) || empty($input['items'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Ensure tables exist
    @ensurePOSTables($db);
    
    $db->beginTransaction();
    
    $saleId = intval($input['sale_id']);
    $userId = $_SESSION['user_id'];
    $branchId = $_SESSION['branch_id'] ?? null;
    
    // Get sale details
    $sale = $db->getRow("SELECT * FROM sales WHERE id = :id", [':id' => $saleId]);
    
    if (!$sale) {
        throw new Exception('Sale not found');
    }
    
    // Check if already refunded
    if ($sale['payment_status'] === 'refunded') {
        throw new Exception('This sale has already been refunded');
    }
    
    // Get shift
    $shift = null;
    if ($sale['shift_id']) {
        $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [':id' => $sale['shift_id']]);
    }
    
    // Calculate refund amounts
    $refundSubtotal = 0;
    $refundItems = [];
    
    foreach ($input['items'] as $item) {
        $saleItem = $db->getRow("SELECT * FROM sale_items WHERE id = :id AND sale_id = :sale_id", [
            ':id' => intval($item['sale_item_id']),
            ':sale_id' => $saleId
        ]);
        
        if (!$saleItem) {
            throw new Exception('Sale item not found');
        }
        
        $refundQty = intval($item['quantity']);
        if ($refundQty > $saleItem['quantity']) {
            throw new Exception('Refund quantity cannot exceed original quantity');
        }
        
        $refundAmount = $refundQty * floatval($saleItem['unit_price']);
        $refundSubtotal += $refundAmount;
        
        $refundItems[] = [
            'sale_item_id' => $saleItem['id'],
            'product_id' => $saleItem['product_id'],
            'product_name' => $saleItem['product_name'],
            'quantity' => $refundQty,
            'unit_price' => $saleItem['unit_price'],
            'total_price' => $refundAmount
        ];
    }
    
    // Calculate proportional discount
    $refundDiscount = 0;
    if ($sale['discount_amount'] > 0 && $refundSubtotal < $sale['subtotal']) {
        $refundDiscount = ($refundSubtotal / $sale['subtotal']) * $sale['discount_amount'];
    } else if ($input['refund_type'] === 'full') {
        $refundDiscount = $sale['discount_amount'];
    }
    
    $refundTotal = $refundSubtotal - $refundDiscount;
    
    // Generate refund number with retry logic to handle race conditions
    $datePart = date('ymd');
    $branchPrefix = $branchId ?? 0;
    
    // Generate unique refund number with retry logic
    $maxRetries = 20;
    $refundNumber = null;
    
    for ($retry = 0; $retry < $maxRetries; $retry++) {
        // Get last refund number for today
        $pattern = 'REF-' . $branchPrefix . '-' . $datePart . '%';
        $lastRefund = $db->getRow("SELECT refund_number FROM refunds WHERE refund_number LIKE :pattern ORDER BY refund_number DESC LIMIT 1", [
            ':pattern' => $pattern
        ]);
        
        $seq = 1;
        if ($lastRefund) {
            $parts = explode('-', $lastRefund['refund_number']);
            if (count($parts) >= 4) {
                $seq = intval($parts[3]) + 1;
            }
        }
        
        // Add retry offset to handle concurrent requests
        $seq += $retry;
        
        $refundNumber = 'REF-' . $branchPrefix . '-' . $datePart . $seq;
        
        // Check if this refund number already exists (race condition check)
        $existing = $db->getRow("SELECT id FROM refunds WHERE refund_number = :refund_number", [
            ':refund_number' => $refundNumber
        ]);
        
        if (!$existing) {
            // Refund number is unique, break out of loop
            break;
        }
        
        // Small random delay to avoid rapid retries
        if ($retry < $maxRetries - 1) {
            usleep(rand(10000, 50000)); // 10-50ms random delay
        }
    }
    
    if (!$refundNumber || $retry >= $maxRetries) {
        // Last resort: add microtime to ensure uniqueness
        $microtime = substr(str_replace('.', '', microtime(true)), -6);
        $random = rand(100, 999);
        $refundNumber = 'REF-' . $branchPrefix . '-' . $datePart . $microtime . $random;
    }
    
    // Determine refund type
    $refundType = ($refundTotal >= $sale['total_amount']) ? 'full' : 'partial';
    
    // Create refund record
    $refundData = [
        'refund_number' => $refundNumber,
        'sale_id' => $saleId,
        'shift_id' => $sale['shift_id'],
        'branch_id' => $branchId,
        'user_id' => $userId,
        'customer_id' => $sale['customer_id'],
        'refund_date' => date('Y-m-d H:i:s'),
        'refund_type' => $refundType,
        'subtotal' => $refundSubtotal,
        'discount_amount' => $refundDiscount,
        'tax_amount' => 0,
        'total_amount' => $refundTotal,
        'reason' => $input['reason'] ?? null,
        'notes' => $input['notes'] ?? null,
        'status' => 'completed'
    ];
    
    // Try to insert with retry logic for duplicate refund numbers
    $refundId = false;
    $insertRetries = 10;
    $currentRefundNumber = $refundNumber;
    
    for ($insertRetry = 0; $insertRetry < $insertRetries; $insertRetry++) {
        try {
            $refundData['refund_number'] = $currentRefundNumber;
            $refundId = $db->insert('refunds', $refundData);
            
            if ($refundId) {
                // Success, break out of retry loop
                $refundNumber = $currentRefundNumber; // Update the refund number used
                break;
            } else {
                $error = $db->getLastError();
                
                // Check if it's a duplicate key error
                if (strpos($error, 'Duplicate entry') !== false || strpos($error, '1062') !== false) {
                    // Generate a new refund number with microtime to ensure uniqueness
                    $microtime = substr(str_replace('.', '', microtime(true)), -6);
                    $random = rand(100, 999);
                    $currentRefundNumber = 'REF-' . $branchPrefix . '-' . $datePart . $microtime . $random;
                    
                    // Small delay before retry
                    usleep(rand(10000, 50000));
                    continue;
                } else {
                    // Different error, throw exception
                    throw new Exception('Failed to create refund record: ' . $error);
                }
            }
        } catch (PDOException $e) {
            // Check if it's a duplicate key error
            if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), '1062') !== false) {
                // Generate a new refund number with microtime to ensure uniqueness
                $microtime = substr(str_replace('.', '', microtime(true)), -6);
                $random = rand(100, 999);
                $currentRefundNumber = 'REF-' . $branchPrefix . '-' . $datePart . $microtime . $random;
                
                // Small delay before retry
                usleep(rand(10000, 50000));
                continue;
            } else {
                // Different error, re-throw
                throw $e;
            }
        }
    }
    
    if (!$refundId) {
        throw new Exception('Failed to create refund record after ' . $insertRetries . ' attempts. Last refund number tried: ' . $currentRefundNumber);
    }
    
    // Create refund items
    foreach ($refundItems as $item) {
        $refundItemData = [
            'refund_id' => $refundId,
            'sale_item_id' => $item['sale_item_id'],
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'total_price' => $item['total_price']
        ];
        
        $itemId = $db->insert('refund_items', $refundItemData);
        if (!$itemId) {
            throw new Exception('Failed to create refund item: ' . $db->getLastError());
        }
        
        // Restore stock
        if (function_exists('updateStock')) {
            try {
                updateStock($item['product_id'], $item['quantity'], $branchId, 'Return');
            } catch (Exception $stockError) {
                error_log("Stock update error during refund: " . $stockError->getMessage());
                // Don't fail the refund if stock update fails
            }
        }
    }
    
    // Create refund payments (mirror original payment methods)
    $originalPayments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $saleId]);
    if ($originalPayments === false) {
        $originalPayments = [];
    }
    
    $totalOriginalPaid = array_sum(array_column($originalPayments, 'amount'));
    $cashRefundAmount = 0;
    
    foreach ($originalPayments as $payment) {
        // Calculate proportional refund for each payment method
        $paymentRefundAmount = ($payment['amount'] / $totalOriginalPaid) * $refundTotal;
        
        $refundPaymentData = [
            'refund_id' => $refundId,
            'payment_method' => $payment['payment_method'],
            'amount' => $paymentRefundAmount,
            'reference' => null
        ];
        
        $db->insert('refund_payments', $refundPaymentData);
        
        // Track cash refunds for shift adjustment
        if (strtolower($payment['payment_method']) === 'cash') {
            $cashRefundAmount += $paymentRefundAmount;
        }
    }
    
    // Update sale status
    $newStatus = ($refundType === 'full') ? 'refunded' : 'paid';
    $db->update('sales', [
        'payment_status' => $newStatus
    ], ['id' => $saleId]);
    
    // Update shift expected cash (reduce for cash refunds)
    if ($shift && $cashRefundAmount > 0) {
        $newExpectedCash = max(0, $shift['expected_cash'] - $cashRefundAmount);
        $db->update('shifts', [
            'expected_cash' => $newExpectedCash
        ], ['id' => $shift['id']]);
    }
    
    // Log activity
    try {
        logActivity($userId, 'pos_refund', [
            'refund_id' => $refundId,
            'sale_id' => $saleId,
            'refund_number' => $refundNumber,
            'amount' => $refundTotal
        ]);
    } catch (Exception $logError) {
        error_log("Failed to log refund activity: " . $logError->getMessage());
    }
    
    $db->commitTransaction();
    
    echo json_encode([
        'success' => true,
        'message' => 'Refund processed successfully',
        'refund_id' => $refundId,
        'refund_number' => $refundNumber,
        'refund_amount' => $refundTotal
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        try {
            $pdo = $db->getPdo();
            if ($pdo && $pdo->inTransaction()) {
                $db->rollbackTransaction();
            }
        } catch (Exception $rollbackError) {
            error_log("Rollback error during refund: " . $rollbackError->getMessage());
        }
    }
    
    logError("Refund error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to process refund: ' . $e->getMessage()]);
}

