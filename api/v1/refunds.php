<?php
/**
 * Refunds API Endpoint
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$db = Database::getInstance();
$pagination = getPaginationParams();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$id = null;
foreach ($pathParts as $part) {
    if (is_numeric($part)) {
        $id = intval($part);
        break;
    }
}

if ($method === 'GET') {
    requirePermission('pos.access');
    
    if ($id) {
        $refund = $db->getRow("SELECT r.*, s.receipt_number, c.first_name, c.last_name FROM refunds r LEFT JOIN sales s ON r.sale_id = s.id LEFT JOIN customers c ON s.customer_id = c.id WHERE r.id = :id", [':id' => $id]);
        if (!$refund) {
            sendError('Refund not found', 404);
        }
        sendSuccess($refund);
    } else {
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        $whereConditions = ["DATE(r.refund_date) BETWEEN :start_date AND :end_date"];
        $params = [':start_date' => $startDate, ':end_date' => $endDate];
        
        if ($user['branch_id']) {
            $whereConditions[] = "s.branch_id = :branch_id";
            $params[':branch_id'] = $user['branch_id'];
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $refunds = $db->getRows("SELECT r.*, s.receipt_number, c.first_name, c.last_name FROM refunds r LEFT JOIN sales s ON r.sale_id = s.id LEFT JOIN customers c ON s.customer_id = c.id WHERE $whereClause ORDER BY r.refund_date DESC LIMIT :limit OFFSET :offset",
                                array_merge($params, [
                                    ':limit' => $pagination['limit'],
                                    ':offset' => $pagination['offset']
                                ]));
        
        if ($refunds === false) {
            $refunds = [];
        }
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM refunds r LEFT JOIN sales s ON r.sale_id = s.id WHERE $whereClause", $params);
        $totalCount = $total ? intval($total['count']) : 0;
        
        $response = formatPaginatedResponse($refunds, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('pos.access');
    
    $input = getRequestBody();
    
    if (!isset($input['sale_id']) || !isset($input['items']) || empty($input['items'])) {
        sendError('Sale ID and items are required', 400);
    }
    
    @ensurePOSTables($db);
    
    $db->beginTransaction();
    
    try {
        $saleId = intval($input['sale_id']);
        $sale = $db->getRow("SELECT * FROM sales WHERE id = :id", [':id' => $saleId]);
        
        if (!$sale) {
            throw new Exception('Sale not found');
        }
        
        if ($sale['payment_status'] === 'refunded') {
            throw new Exception('This sale has already been refunded');
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
                'quantity' => $refundQty,
                'unit_price' => $saleItem['unit_price'],
                'total_price' => $refundAmount
            ];
        }
        
        // Calculate proportional discount
        $refundDiscount = 0;
        if ($sale['discount_amount'] > 0 && $refundSubtotal < $sale['subtotal']) {
            $refundDiscount = ($refundSubtotal / $sale['subtotal']) * $sale['discount_amount'];
        } else if (isset($input['refund_type']) && $input['refund_type'] === 'full') {
            $refundDiscount = $sale['discount_amount'];
        }
        
        $refundTotal = $refundSubtotal - $refundDiscount;
        
        // Generate refund number
        $datePart = date('ymd');
        $maxRetries = 10;
        $refundNumber = null;
        
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $lastRefund = $db->getRow("SELECT refund_number FROM refunds WHERE refund_number LIKE :pattern ORDER BY id DESC LIMIT 1", [
                ':pattern' => 'REF-' . $datePart . '-%'
            ]);
            
            if ($lastRefund) {
                $lastNumber = $lastRefund['refund_number'];
                $parts = explode('-', $lastNumber);
                $lastSeq = isset($parts[2]) ? intval($parts[2]) : 0;
                $seq = $lastSeq + 1 + $attempt;
            } else {
                $seq = 1 + $attempt;
            }
            
            $refundNumber = 'REF-' . $datePart . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            
            $exists = $db->getRow("SELECT id FROM refunds WHERE refund_number = :number", [':number' => $refundNumber]);
            if (!$exists) {
                break;
            }
        }
        
        // Create refund record
        $refundData = [
            'refund_number' => $refundNumber,
            'sale_id' => $saleId,
            'refund_date' => date('Y-m-d H:i:s'),
            'refunded_by' => $user['id'],
            'subtotal' => $refundSubtotal,
            'discount_amount' => $refundDiscount,
            'total_amount' => $refundTotal,
            'reason' => $input['reason'] ?? 'Customer request',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $refundId = $db->insert('refunds', $refundData);
        
        if (!$refundId) {
            throw new Exception('Failed to create refund record');
        }
        
        // Create refund items and restore stock
        foreach ($refundItems as $refundItem) {
            $refundItemData = [
                'refund_id' => $refundId,
                'sale_item_id' => $refundItem['sale_item_id'],
                'product_id' => $refundItem['product_id'],
                'quantity' => $refundItem['quantity'],
                'unit_price' => $refundItem['unit_price'],
                'total_price' => $refundItem['total_price']
            ];
            
            $db->insert('refund_items', $refundItemData);
            
            // Restore stock
            if ($refundItem['product_id']) {
                $product = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id", [':id' => $refundItem['product_id']]);
                if ($product) {
                    $newStock = intval($product['quantity_in_stock']) + $refundItem['quantity'];
                    $db->update('products', ['quantity_in_stock' => $newStock], ['id' => $refundItem['product_id']]);
                }
            }
        }
        
        // Update sale payment status if full refund
        if ($refundTotal >= $sale['total_amount']) {
            $db->update('sales', ['payment_status' => 'refunded'], ['id' => $saleId]);
        }
        
        // Reverse shift cash if applicable
        if ($sale['shift_id']) {
            $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [':id' => $sale['shift_id']]);
            if ($shift && $shift['status'] === 'open') {
                // Get cash payments for this sale
                $cashPayments = $db->getRows("SELECT SUM(amount) as total FROM sale_payments WHERE sale_id = :sale_id AND payment_method = 'cash'", [':sale_id' => $saleId]);
                $cashAmount = $cashPayments && isset($cashPayments[0]['total']) ? floatval($cashPayments[0]['total']) : 0;
                
                if ($cashAmount > 0) {
                    $newExpectedCash = max(0, $shift['expected_cash'] - $cashAmount);
                    $db->update('shifts', ['expected_cash' => $newExpectedCash], ['id' => $shift['id']]);
                }
            }
        }
        
        $db->commitTransaction();
        
        $refund = $db->getRow("SELECT * FROM refunds WHERE id = :id", [':id' => $refundId]);
        sendSuccess($refund, 'Refund processed successfully', 201);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        sendError('Failed to process refund: ' . $e->getMessage(), 500);
    }
    
} else {
    sendError('Method not allowed', 405);
}
