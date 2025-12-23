<?php
/**
 * Currencies API Endpoint
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$db = Database::getInstance(); // Currencies are in tenant database
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
    requirePermission('settings.view');
    
    if ($id) {
        $currency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $id]);
        if (!$currency) {
            sendError('Currency not found', 404);
        }
        sendSuccess($currency);
    } else {
        $currencies = $db->getRows("SELECT * FROM currencies ORDER BY is_base DESC, code ASC LIMIT :limit OFFSET :offset",
                                   [
                                       ':limit' => $pagination['limit'],
                                       ':offset' => $pagination['offset']
                                   ]);
        
        if ($currencies === false) {
            $currencies = [];
        }
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM currencies");
        $totalCount = $total ? intval($total['count']) : 0;
        
        $response = formatPaginatedResponse($currencies, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('settings.edit');
    
    $input = getRequestBody();
    
    if (!isset($input['code']) || !isset($input['name']) || !isset($input['symbol'])) {
        sendError('Code, name, and symbol are required', 400);
    }
    
    $currencyData = [
        'code' => strtoupper($input['code']),
        'name' => $input['name'],
        'symbol' => $input['symbol'],
        'exchange_rate' => floatval($input['exchange_rate'] ?? 1.0),
        'is_base' => isset($input['is_base']) ? intval($input['is_base']) : 0,
        'status' => $input['status'] ?? 'Active',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    // If this is base currency, unset others
    if ($currencyData['is_base'] == 1) {
        $db->update('currencies', ['is_base' => 0], ['is_base' => 1]);
    }
    
    $currencyId = $db->insert('currencies', $currencyData);
    
    if (!$currencyId) {
        sendError('Failed to create currency: ' . $db->getLastError(), 500);
    }
    
    $currency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $currencyId]);
    sendSuccess($currency, 'Currency created successfully', 201);
    
} elseif ($method === 'PUT') {
    requirePermission('settings.edit');
    
    if (!$id) {
        sendError('Currency ID is required', 400);
    }
    
    $input = getRequestBody();
    
    $updateData = [];
    $allowedFields = ['code', 'name', 'symbol', 'exchange_rate', 'is_base', 'status'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            if ($field === 'code') {
                $updateData[$field] = strtoupper($input[$field]);
            } elseif ($field === 'is_base') {
                $updateData[$field] = intval($input[$field]);
            } else {
                $updateData[$field] = $input[$field];
            }
        }
    }
    
    // If setting as base, unset others
    if (isset($updateData['is_base']) && $updateData['is_base'] == 1) {
        $db->update('currencies', ['is_base' => 0], ['is_base' => 1]);
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    $result = $db->update('currencies', $updateData, ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to update currency', 500);
    }
    
    $currency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $id]);
    sendSuccess($currency, 'Currency updated successfully');
    
} elseif ($method === 'DELETE') {
    requirePermission('settings.edit');
    
    if (!$id) {
        sendError('Currency ID is required', 400);
    }
    
    $currency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $id]);
    if (!$currency) {
        sendError('Currency not found', 404);
    }
    
    if ($currency['is_base'] == 1) {
        sendError('Cannot delete base currency', 400);
    }
    
    $result = $db->update('currencies', ['status' => 'Inactive'], ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to delete currency', 500);
    }
    
    sendSuccess([], 'Currency deleted successfully');
} else {
    sendError('Method not allowed', 405);
}


