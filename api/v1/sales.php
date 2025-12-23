<?php
/**
 * @OA\Get(
 *     path="/sales",
 *     tags={"Sales"},
 *     summary="Get all sales/receipts",
 *     security={{"bearerAuth": {}}},
 *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer")),
 *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer")),
 *     @OA\Parameter(name="start_date", in="query", @OA\Schema(type="string", format="date")),
 *     @OA\Parameter(name="end_date", in="query", @OA\Schema(type="string", format="date")),
 *     @OA\Response(response=200, description="List of sales")
 * )
 * @OA\Get(
 *     path="/sales/{id}",
 *     tags={"Sales"},
 *     summary="Get sale by ID",
 *     security={{"bearerAuth": {}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Sale details")
 * )
 * @OA\Post(
 *     path="/sales",
 *     tags={"Sales"},
 *     summary="Create new sale (POS transaction)",
 *     security={{"bearerAuth": {}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(
 *             required={"cart", "payments"},
 *             @OA\Property(property="cart", type="array", @OA\Items(type="object")),
 *             @OA\Property(property="payments", type="array", @OA\Items(type="object")),
 *             @OA\Property(property="customer_id", type="integer"),
 *             @OA\Property(property="discount", type="object")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Sale created")
 * )
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$db = Database::getInstance();
$pagination = getPaginationParams();

// Get ID from URL
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$id = null;

// Find numeric ID in path
foreach ($pathParts as $part) {
    if (is_numeric($part)) {
        $id = intval($part);
        break;
    }
}

if ($method === 'GET') {
    requirePermission('pos.access');
    
    if ($id) {
        // Get single sale
        $sale = $db->getRow("SELECT s.*, c.first_name, c.last_name, c.email, c.phone,
                            u.first_name as cashier_first, u.last_name as cashier_last,
                            b.branch_name
                            FROM sales s
                            LEFT JOIN customers c ON s.customer_id = c.id
                            LEFT JOIN users u ON s.user_id = u.id
                            LEFT JOIN branches b ON s.branch_id = b.id
                            WHERE s.id = :id", [':id' => $id]);
        
        if (!$sale) {
            sendError('Sale not found', 404);
        }
        
        // Get sale items
        $items = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :id", [':id' => $id]);
        $sale['items'] = $items ?: [];
        
        // Get payments
        $payments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $id]);
        $sale['payments'] = $payments ?: [];
        
        sendSuccess($sale);
    } else {
        // Get all sales
        $whereConditions = ["1=1"];
        $params = [];
        
        $startDate = $_GET['start_date'] ?? date('Y-m-d');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        $whereConditions[] = "DATE(s.sale_date) BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate;
        
        if ($user['branch_id']) {
            $whereConditions[] = "s.branch_id = :branch_id";
            $params[':branch_id'] = $user['branch_id'];
        }
        
        // Check for deleted_at column
        $hasDeletedAtColumn = false;
        try {
            $colCheck = $db->getRow("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                                    WHERE TABLE_SCHEMA = DATABASE() 
                                    AND TABLE_NAME = 'sales' 
                                    AND COLUMN_NAME = 'deleted_at'");
            $hasDeletedAtColumn = ($colCheck && $colCheck['count'] > 0);
        } catch (Exception $e) {
            $hasDeletedAtColumn = false;
        }
        
        if ($hasDeletedAtColumn) {
            $whereConditions[] = "s.deleted_at IS NULL";
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM sales s WHERE $whereClause", $params);
        $totalCount = $total ? intval($total['count']) : 0;
        
        $sales = $db->getRows("SELECT s.*, c.first_name, c.last_name,
                               u.first_name as cashier_first, u.last_name as cashier_last
                               FROM sales s
                               LEFT JOIN customers c ON s.customer_id = c.id
                               LEFT JOIN users u ON s.user_id = u.id
                               WHERE $whereClause
                               ORDER BY s.sale_date DESC
                               LIMIT :limit OFFSET :offset",
                               array_merge($params, [
                                   ':limit' => $pagination['limit'],
                                   ':offset' => $pagination['offset']
                               ]));
        
        if ($sales === false) {
            $sales = [];
        }
        
        $response = formatPaginatedResponse($sales, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    // Create sale (process POS transaction)
    requirePermission('pos.access');
    
    $input = getRequestBody();
    
    if (!isset($input['cart']) || empty($input['cart'])) {
        sendError('Cart is required', 400);
    }
    
    if (!isset($input['payments']) || empty($input['payments'])) {
        sendError('Payments are required', 400);
    }
    
    // Ensure POS tables exist
    @ensurePOSTables($db);
    
    $branchId = $user['branch_id'] ?? null;
    $userId = $user['id'];
    
    // Get or create shift
    $shift = null;
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
        sendError('No open shift found. Please start a shift first.', 400);
    }
    
    // Generate receipt number
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
        
        $existing = $db->getRow("SELECT id FROM sales WHERE receipt_number = :receipt_number", [
            ':receipt_number' => $receiptNumber
        ]);
        
        if (!$existing) {
            break;
        }
    }
    
    if (!$receiptNumber) {
        $randomSuffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 2));
        $seqPadded = str_pad(9999, 4, '0', STR_PAD_LEFT);
        $receiptNumber = $branchPrefix . '-' . $datePart . '-' . $seqPadded . '-' . $randomSuffix;
    }
    
    // Calculate totals
    $subtotal = 0;
    foreach ($input['cart'] as $item) {
        $subtotal += floatval($item['price']) * intval($item['quantity']);
    }
    
    $discount = $input['discount'] ?? ['type' => null, 'amount' => 0];
    $discountAmount = 0;
    if ($discount['type'] === 'value') {
        $discountAmount = floatval($discount['amount']);
    } else if ($discount['type'] === 'percentage') {
        $discountAmount = ($subtotal * floatval($discount['amount'])) / 100;
    }
    
    $total = $subtotal - $discountAmount;
    
    $customerId = null;
    if (isset($input['customer_id'])) {
        $customerId = intval($input['customer_id']);
    }
    
    $db->beginTransaction();
    
    try {
        // Create sale record
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
        
        $saleId = $db->insert('sales', $saleData);
        
        if (!$saleId) {
            throw new Exception('Failed to create sale record: ' . $db->getLastError());
        }
        
        // Create sale items and deduct stock
        foreach ($input['cart'] as $item) {
            $productId = intval($item['id']);
            $quantity = intval($item['quantity']);
            $price = floatval($item['price']);
            
            $product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $productId]);
            
            if (!$product) {
                throw new Exception("Product ID $productId not found");
            }
            
            // Get product name
            $productName = $item['name'] ?? '';
            if (empty($productName)) {
                if (!empty($product['product_name'])) {
                    $productName = $product['product_name'];
                } else {
                    $productName = trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? ''));
                }
            }
            
            $itemData = [
                'sale_id' => $saleId,
                'product_id' => $productId,
                'product_name' => $productName,
                'quantity' => $quantity,
                'unit_price' => $price,
                'total_price' => $price * $quantity,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $itemId = $db->insert('sale_items', $itemData);
            if (!$itemId) {
                throw new Exception('Failed to create sale item');
            }
            
            // Deduct stock
            $newStock = max(0, intval($product['quantity_in_stock']) - $quantity);
            $db->update('products', ['quantity_in_stock' => $newStock], ['id' => $productId]);
        }
        
        // Create payments
        $totalCashPaid = 0;
        foreach ($input['payments'] as $payment) {
            $paymentMethod = strtolower($payment['method'] ?? 'cash');
            $amount = floatval($payment['amount'] ?? 0);
            
            if ($amount <= 0) {
                continue;
            }
            
            $paymentData = [
                'sale_id' => $saleId,
                'payment_method' => $paymentMethod,
                'amount' => $amount,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $paymentId = $db->insert('sale_payments', $paymentData);
            if (!$paymentId) {
                throw new Exception('Failed to create payment record');
            }
            
            if ($paymentMethod === 'cash') {
                $totalCashPaid += $amount;
            }
        }
        
        // Update shift expected cash
        if ($totalCashPaid > 0) {
            $db->update('shifts', [
                'expected_cash' => $shift['expected_cash'] + $totalCashPaid
            ], ['id' => $shift['id']]);
        }
        
        $db->commitTransaction();
        
        // Fiscalize sale if fiscalization is enabled for branch
        error_log("API SALES: Sale created, ID: $saleId, branchId: " . ($branchId ?? 'NULL'));
        if ($branchId) {
            error_log("API SALES: Attempting fiscalization for sale $saleId, branch $branchId");
            require_once APP_PATH . '/includes/fiscal_helper.php';
            try {
                // Create a temporary invoice-like structure for fiscalization
                // Sales are fiscalized similar to invoices
                $result = fiscalizeSale($saleId, $branchId, $db);
                if ($result) {
                    error_log("API SALES: Fiscalization successful for sale $saleId");
                } else {
                    error_log("API SALES: Fiscalization returned false for sale $saleId");
                }
            } catch (Exception $e) {
                // Log error but don't fail the sale
                error_log("API SALES: Fiscalization error for sale $saleId: " . $e->getMessage());
                error_log("API SALES: Stack trace: " . $e->getTraceAsString());
            }
        } else {
            error_log("API SALES: branchId is NULL, skipping fiscalization");
        }
        
        // Get created sale with details
        $sale = $db->getRow("SELECT * FROM sales WHERE id = :id", [':id' => $saleId]);
        $items = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :id", [':id' => $saleId]);
        $payments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $saleId]);
        
        $sale['items'] = $items ?: [];
        $sale['payments'] = $payments ?: [];
        
        sendSuccess($sale, 'Sale created successfully', 201);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        sendError('Failed to create sale: ' . $e->getMessage(), 500);
    }
    
} else {
    sendError('Method not allowed', 405);
}
