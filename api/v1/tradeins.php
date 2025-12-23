<?php
/**
 * Trade-ins API Endpoint
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
    requirePermission('tradeins.view');
    
    if ($id) {
        $tradein = $db->getRow("SELECT t.*, c.first_name, c.last_name, p.brand as new_product_brand, p.model as new_product_model 
                               FROM trade_ins t 
                               LEFT JOIN customers c ON t.customer_id = c.id 
                               LEFT JOIN products p ON t.new_product_id = p.id 
                               WHERE t.id = :id", [':id' => $id]);
        
        if (!$tradein) {
            sendError('Trade-in not found', 404);
        }
        
        // Get trade-in items
        $items = $db->getRows("SELECT * FROM trade_in_items WHERE trade_in_id = :id", [':id' => $id]);
        $tradein['items'] = $items ?: [];
        
        sendSuccess($tradein);
    } else {
        $tradeins = $db->getRows("SELECT t.*, c.first_name, c.last_name, p.brand as new_product_brand, p.model as new_product_model 
                                 FROM trade_ins t 
                                 LEFT JOIN customers c ON t.customer_id = c.id 
                                 LEFT JOIN products p ON t.new_product_id = p.id 
                                 ORDER BY t.created_at DESC
                                 LIMIT :limit OFFSET :offset",
                                 [
                                     ':limit' => $pagination['limit'],
                                     ':offset' => $pagination['offset']
                                 ]);
        
        if ($tradeins === false) {
            $tradeins = [];
        }
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM trade_ins");
        $totalCount = $total ? intval($total['count']) : 0;
        
        $response = formatPaginatedResponse($tradeins, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('tradeins.create');
    
    $input = getRequestBody();
    
    if (!isset($input['device_brand']) || !isset($input['device_model']) || !isset($input['device_condition'])) {
        sendError('Device brand, model, and condition are required', 400);
    }
    
    // Generate trade-in number
    $datePart = date('Ymd');
    $maxRetries = 10;
    $tradeInNumber = null;
    
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        $lastTradeIn = $db->getRow("SELECT trade_in_number FROM trade_ins WHERE trade_in_number LIKE :pattern ORDER BY id DESC LIMIT 1", [
            ':pattern' => 'TI-' . $datePart . '-%'
        ]);
        
        if ($lastTradeIn) {
            $lastNumber = $lastTradeIn['trade_in_number'];
            $parts = explode('-', $lastNumber);
            $lastSeq = isset($parts[2]) ? intval($parts[2]) : 0;
            $seq = $lastSeq + 1 + $attempt;
        } else {
            $seq = 1 + $attempt;
        }
        
        $tradeInNumber = 'TI-' . $datePart . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
        
        $exists = $db->getRow("SELECT id FROM trade_ins WHERE trade_in_number = :number", [':number' => $tradeInNumber]);
        if (!$exists) {
            break;
        }
        
        if ($attempt === $maxRetries - 1) {
            $microtime = substr(str_replace('.', '', microtime(true)), -6);
            $random = rand(100, 999);
            $tradeInNumber = 'TI-' . $datePart . '-' . $microtime . $random;
        }
    }
    
    $db->beginTransaction();
    
    try {
        $tradeInData = [
            'trade_in_number' => $tradeInNumber,
            'customer_id' => $input['customer_id'] ?? null,
            'branch_id' => $user['branch_id'] ?? null,
            'assessed_by' => $user['id'],
            'device_category' => $input['device_category'] ?? null,
            'device_brand' => $input['device_brand'],
            'device_model' => $input['device_model'],
            'device_color' => $input['device_color'] ?? null,
            'device_storage' => $input['device_storage'] ?? null,
            'device_condition' => $input['device_condition'],
            'battery_health' => $input['battery_health'] ?? null,
            'cosmetic_issues' => $input['cosmetic_issues'] ?? null,
            'functional_issues' => $input['functional_issues'] ?? null,
            'accessories_included' => $input['accessories_included'] ?? null,
            'valuation_amount' => floatval($input['valuation_amount'] ?? 0),
            'new_product_id' => $input['new_product_id'] ?? null,
            'status' => $input['status'] ?? 'Pending',
            'valuation_notes' => $input['valuation_notes'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $tradeInId = $db->insert('trade_ins', $tradeInData);
        
        if (!$tradeInId) {
            throw new Exception('Failed to create trade-in: ' . $db->getLastError());
        }
        
        // Create trade-in items if provided
        if (isset($input['items']) && is_array($input['items'])) {
            foreach ($input['items'] as $item) {
                $itemData = [
                    'trade_in_id' => $tradeInId,
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'] ?? '',
                    'quantity' => intval($item['quantity'] ?? 1),
                    'unit_price' => floatval($item['unit_price'] ?? 0),
                    'total_price' => floatval($item['total_price'] ?? ($item['unit_price'] * $item['quantity']))
                ];
                
                $db->insert('trade_in_items', $itemData);
            }
        }
        
        $db->commitTransaction();
        
        $tradein = $db->getRow("SELECT * FROM trade_ins WHERE id = :id", [':id' => $tradeInId]);
        sendSuccess($tradein, 'Trade-in created successfully', 201);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        sendError('Failed to create trade-in: ' . $e->getMessage(), 500);
    }
    
} elseif ($method === 'PUT') {
    requirePermission('tradeins.edit');
    
    if (!$id) {
        sendError('Trade-in ID is required', 400);
    }
    
    $input = getRequestBody();
    
    $updateData = [];
    $allowedFields = ['device_category', 'device_brand', 'device_model', 'device_color', 'device_storage', 
                      'device_condition', 'battery_health', 'cosmetic_issues', 'functional_issues', 
                      'accessories_included', 'valuation_amount', 'new_product_id', 'status', 'valuation_notes'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateData[$field] = $input[$field];
        }
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    $result = $db->update('trade_ins', $updateData, ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to update trade-in', 500);
    }
    
    $tradein = $db->getRow("SELECT * FROM trade_ins WHERE id = :id", [':id' => $id]);
    sendSuccess($tradein, 'Trade-in updated successfully');
    
} else {
    sendError('Method not allowed', 405);
}
