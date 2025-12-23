<?php
/**
 * Stock Transfers API Endpoint
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
    requirePermission('inventory.view');
    
    if ($id) {
        $transfer = $db->getRow("SELECT st.*, b1.branch_name as from_branch, b2.branch_name as to_branch, u.first_name, u.last_name
                                FROM stock_transfers st 
                                LEFT JOIN branches b1 ON st.from_branch_id = b1.id 
                                LEFT JOIN branches b2 ON st.to_branch_id = b2.id 
                                LEFT JOIN users u ON st.initiated_by = u.id 
                                WHERE st.id = :id", [':id' => $id]);
        
        if (!$transfer) {
            sendError('Transfer not found', 404);
        }
        
        // Get transfer items
        $items = $db->getRows("SELECT ti.*, p.product_code, 
                              COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name
                              FROM transfer_items ti
                              LEFT JOIN products p ON ti.product_id = p.id
                              WHERE ti.transfer_id = :id", [':id' => $id]);
        $transfer['items'] = $items ?: [];
        
        sendSuccess($transfer);
    } else {
        $transfers = $db->getRows("SELECT st.*, b1.branch_name as from_branch, b2.branch_name as to_branch, u.first_name, u.last_name
                                  FROM stock_transfers st 
                                  LEFT JOIN branches b1 ON st.from_branch_id = b1.id 
                                  LEFT JOIN branches b2 ON st.to_branch_id = b2.id 
                                  LEFT JOIN users u ON st.initiated_by = u.id 
                                  ORDER BY st.created_at DESC
                                  LIMIT :limit OFFSET :offset",
                                  [
                                      ':limit' => $pagination['limit'],
                                      ':offset' => $pagination['offset']
                                  ]);
        
        if ($transfers === false) {
            $transfers = [];
        }
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM stock_transfers");
        $totalCount = $total ? intval($total['count']) : 0;
        
        $response = formatPaginatedResponse($transfers, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('inventory.create');
    
    $input = getRequestBody();
    
    if (!isset($input['to_branch_id']) || !isset($input['items']) || empty($input['items'])) {
        sendError('To branch ID and items are required', 400);
    }
    
    // Generate transfer number
    $datePart = date('Ymd');
    $maxRetries = 10;
    $transferNumber = null;
    
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        $lastTransfer = $db->getRow("SELECT transfer_number FROM stock_transfers WHERE transfer_number LIKE :pattern ORDER BY id DESC LIMIT 1", [
            ':pattern' => 'TRF-' . $datePart . '-%'
        ]);
        
        if ($lastTransfer) {
            $lastNumber = $lastTransfer['transfer_number'];
            $parts = explode('-', $lastNumber);
            $lastSeq = isset($parts[2]) ? intval($parts[2]) : 0;
            $seq = $lastSeq + 1 + $attempt;
        } else {
            $seq = 1 + $attempt;
        }
        
        $transferNumber = 'TRF-' . $datePart . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
        
        $exists = $db->getRow("SELECT id FROM stock_transfers WHERE transfer_number = :number", [':number' => $transferNumber]);
        if (!$exists) {
            break;
        }
    }
    
    $db->beginTransaction();
    
    try {
        $transferData = [
            'transfer_number' => $transferNumber,
            'from_branch_id' => $user['branch_id'] ?? $input['from_branch_id'] ?? null,
            'to_branch_id' => intval($input['to_branch_id']),
            'initiated_by' => $user['id'],
            'status' => $input['status'] ?? 'Pending',
            'notes' => $input['notes'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $transferId = $db->insert('stock_transfers', $transferData);
        
        if (!$transferId) {
            throw new Exception('Failed to create transfer: ' . $db->getLastError());
        }
        
        // Create transfer items
        foreach ($input['items'] as $item) {
            $productId = intval($item['product_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            
            $itemData = [
                'transfer_id' => $transferId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'notes' => $item['notes'] ?? null
            ];
            
            $db->insert('transfer_items', $itemData);
            
            // Deduct stock from source branch if status is Completed
            if (($input['status'] ?? 'Pending') === 'Completed' && $transferData['from_branch_id']) {
                $product = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", [
                    ':id' => $productId,
                    ':branch_id' => $transferData['from_branch_id']
                ]);
                
                if ($product) {
                    $newStock = max(0, intval($product['quantity_in_stock']) - $quantity);
                    $db->update('products', ['quantity_in_stock' => $newStock], [
                        'id' => $productId,
                        'branch_id' => $transferData['from_branch_id']
                    ]);
                }
                
                // Add stock to destination branch
                $destProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", [
                    ':id' => $productId,
                    ':branch_id' => $transferData['to_branch_id']
                ]);
                
                if ($destProduct) {
                    $newStock = intval($destProduct['quantity_in_stock']) + $quantity;
                    $db->update('products', ['quantity_in_stock' => $newStock], [
                        'id' => $productId,
                        'branch_id' => $transferData['to_branch_id']
                    ]);
                } else {
                    // Create product entry for destination branch if it doesn't exist
                    $sourceProduct = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $productId]);
                    if ($sourceProduct) {
                        $newProductData = $sourceProduct;
                        unset($newProductData['id']);
                        $newProductData['branch_id'] = $transferData['to_branch_id'];
                        $newProductData['quantity_in_stock'] = $quantity;
                        $db->insert('products', $newProductData);
                    }
                }
            }
        }
        
        $db->commitTransaction();
        
        $transfer = $db->getRow("SELECT * FROM stock_transfers WHERE id = :id", [':id' => $transferId]);
        sendSuccess($transfer, 'Transfer created successfully', 201);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        sendError('Failed to create transfer: ' . $e->getMessage(), 500);
    }
    
} elseif ($method === 'PUT') {
    requirePermission('inventory.edit');
    
    if (!$id) {
        sendError('Transfer ID is required', 400);
    }
    
    $input = getRequestBody();
    
    // Update status
    if (isset($input['status'])) {
        $transfer = $db->getRow("SELECT * FROM stock_transfers WHERE id = :id", [':id' => $id]);
        
        if (!$transfer) {
            sendError('Transfer not found', 404);
        }
        
        $db->beginTransaction();
        
        try {
            $db->update('stock_transfers', [
                'status' => $input['status'],
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);
            
            // If status changed to Completed, process stock movement
            if ($input['status'] === 'Completed' && $transfer['status'] !== 'Completed') {
                $items = $db->getRows("SELECT * FROM transfer_items WHERE transfer_id = :id", [':id' => $id]);
                
                foreach ($items as $item) {
                    // Deduct from source
                    if ($transfer['from_branch_id']) {
                        $product = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", [
                            ':id' => $item['product_id'],
                            ':branch_id' => $transfer['from_branch_id']
                        ]);
                        
                        if ($product) {
                            $newStock = max(0, intval($product['quantity_in_stock']) - $item['quantity']);
                            $db->update('products', ['quantity_in_stock' => $newStock], [
                                'id' => $item['product_id'],
                                'branch_id' => $transfer['from_branch_id']
                            ]);
                        }
                    }
                    
                    // Add to destination
                    $destProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", [
                        ':id' => $item['product_id'],
                        ':branch_id' => $transfer['to_branch_id']
                    ]);
                    
                    if ($destProduct) {
                        $newStock = intval($destProduct['quantity_in_stock']) + $item['quantity'];
                        $db->update('products', ['quantity_in_stock' => $newStock], [
                            'id' => $item['product_id'],
                            'branch_id' => $transfer['to_branch_id']
                        ]);
                    }
                }
            }
            
            $db->commitTransaction();
            
            $updatedTransfer = $db->getRow("SELECT * FROM stock_transfers WHERE id = :id", [':id' => $id]);
            sendSuccess($updatedTransfer, 'Transfer updated successfully');
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollbackTransaction();
            }
            sendError('Failed to update transfer: ' . $e->getMessage(), 500);
        }
    } else {
        sendError('Status update required', 400);
    }
    
} else {
    sendError('Method not allowed', 405);
}


