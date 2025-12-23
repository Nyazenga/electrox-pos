<?php
/**
 * @OA\Get(
 *     path="/inventory",
 *     tags={"Inventory"},
 *     summary="Get inventory/stock levels",
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(response=200, description="Inventory data")
 * )
 * @OA\Get(
 *     path="/inventory/grn",
 *     tags={"Inventory"},
 *     summary="Get GRN (Goods Received Notes)",
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(response=200, description="List of GRNs")
 * )
 * @OA\Post(
 *     path="/inventory/grn",
 *     tags={"Inventory"},
 *     summary="Create GRN",
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(response=201, description="GRN created")
 * )
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$db = Database::getInstance();
$pagination = getPaginationParams();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));

if ($method === 'GET') {
    requirePermission('inventory.view');
    
    if (isset($pathParts[count($pathParts) - 1]) && $pathParts[count($pathParts) - 1] === 'grn') {
        // Get GRNs
        $grns = $db->getRows("SELECT grn.*, s.name as supplier_name, b.branch_name
                             FROM goods_received_notes grn
                             LEFT JOIN suppliers s ON grn.supplier_id = s.id
                             LEFT JOIN branches b ON grn.branch_id = b.id
                             ORDER BY grn.created_at DESC
                             LIMIT :limit OFFSET :offset",
                             [
                                 ':limit' => $pagination['limit'],
                                 ':offset' => $pagination['offset']
                             ]);
        
        if ($grns === false) {
            $grns = [];
        }
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM goods_received_notes");
        $totalCount = $total ? intval($total['count']) : 0;
        
        $response = formatPaginatedResponse($grns, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    } else {
        // Get inventory/stock levels
        $whereConditions = ["1=1"];
        $params = [];
        
        if (isset($_GET['category_id']) && $_GET['category_id'] !== 'all') {
            $whereConditions[] = "p.category_id = :category_id";
            $params[':category_id'] = intval($_GET['category_id']);
        }
        
        if ($user['branch_id']) {
            $whereConditions[] = "p.branch_id = :branch_id";
            $params[':branch_id'] = $user['branch_id'];
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $inventory = $db->getRows("SELECT p.id, p.product_code,
                                   COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
                                   pc.name as category_name, b.branch_name,
                                   p.quantity_in_stock, p.reorder_level, p.status
                                   FROM products p
                                   LEFT JOIN product_categories pc ON p.category_id = pc.id
                                   LEFT JOIN branches b ON p.branch_id = b.id
                                   WHERE $whereClause
                                   ORDER BY p.product_code
                                   LIMIT :limit OFFSET :offset",
                                   array_merge($params, [
                                       ':limit' => $pagination['limit'],
                                       ':offset' => $pagination['offset']
                                   ]));
        
        if ($inventory === false) {
            $inventory = [];
        }
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM products p WHERE $whereClause", $params);
        $totalCount = $total ? intval($total['count']) : 0;
        
        $response = formatPaginatedResponse($inventory, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST' && isset($pathParts[count($pathParts) - 1]) && $pathParts[count($pathParts) - 1] === 'grn') {
    requirePermission('inventory.create');
    
    $input = getRequestBody();
    
    if (empty($input['grn_number'])) {
        sendError('GRN number is required', 400);
    }
    
    if (empty($input['items']) || !is_array($input['items'])) {
        sendError('At least one item is required', 400);
    }
    
    // Check if GRN number already exists
    $existing = $db->getRow("SELECT id FROM goods_received_notes WHERE grn_number = :number", [':number' => $input['grn_number']]);
    if ($existing) {
        sendError('GRN number already exists', 400);
    }
    
    // Calculate total value
    $totalValue = 0;
    foreach ($input['items'] as $item) {
        $totalValue += floatval($item['cost_price'] ?? 0) * intval($item['quantity'] ?? 0);
    }
    
    $db->beginTransaction();
    
    try {
        // Create GRN
        $grnData = [
            'grn_number' => $input['grn_number'],
            'supplier_id' => isset($input['supplier_id']) && $input['supplier_id'] > 0 ? intval($input['supplier_id']) : null,
            'branch_id' => $user['branch_id'] ?? intval($input['branch_id'] ?? 0),
            'received_date' => $input['received_date'] ?? date('Y-m-d'),
            'received_by' => $user['id'],
            'total_value' => $totalValue,
            'status' => $input['status'] ?? 'Draft',
            'notes' => $input['notes'] ?? null
        ];
        
        $grnId = $db->insert('goods_received_notes', $grnData);
        
        if (!$grnId) {
            throw new Exception('Failed to create GRN: ' . $db->getLastError());
        }
        
        // Create GRN items
        foreach ($input['items'] as $item) {
            $productId = intval($item['product_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            $costPrice = floatval($item['cost_price'] ?? 0);
            $sellingPrice = floatval($item['selling_price'] ?? 0);
            
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            
            $itemData = [
                'grn_id' => $grnId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'cost_price' => $costPrice,
                'selling_price' => $sellingPrice,
                'serial_numbers' => $item['serial_numbers'] ?? null
            ];
            
            $db->insert('grn_items', $itemData);
            
            // Update product stock and prices if status is Approved
            if (($input['status'] ?? 'Draft') === 'Approved') {
                $product = $db->getRow("SELECT quantity_in_stock, cost_price, selling_price FROM products WHERE id = :id", [':id' => $productId]);
                if ($product) {
                    $newStock = intval($product['quantity_in_stock']) + $quantity;
                    $updateData = ['quantity_in_stock' => $newStock];
                    
                    if ($costPrice > 0) {
                        $updateData['cost_price'] = $costPrice;
                    }
                    if ($sellingPrice > 0) {
                        $updateData['selling_price'] = $sellingPrice;
                    }
                    
                    $db->update('products', $updateData, ['id' => $productId]);
                }
            }
        }
        
        $db->commitTransaction();
        
        $grn = $db->getRow("SELECT * FROM goods_received_notes WHERE id = :id", [':id' => $grnId]);
        sendSuccess($grn, 'GRN created successfully', 201);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        sendError('Failed to create GRN: ' . $e->getMessage(), 500);
    }
    
} else {
    sendError('Method not allowed', 405);
}
