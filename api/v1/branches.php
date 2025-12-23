<?php
/**
 * Branches API Endpoint
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
    requirePermission('branches.view');
    
    if ($id) {
        $branch = $db->getRow("SELECT b.*, u.first_name, u.last_name FROM branches b LEFT JOIN users u ON b.manager_id = u.id WHERE b.id = :id", [':id' => $id]);
        if (!$branch) {
            sendError('Branch not found', 404);
        }
        sendSuccess($branch);
    } else {
        $branches = $db->getRows("SELECT b.*, u.first_name, u.last_name FROM branches b LEFT JOIN users u ON b.manager_id = u.id ORDER BY b.created_at DESC LIMIT :limit OFFSET :offset",
                                 [
                                     ':limit' => $pagination['limit'],
                                     ':offset' => $pagination['offset']
                                 ]);
        
        if ($branches === false) {
            $branches = [];
        }
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM branches");
        $totalCount = $total ? intval($total['count']) : 0;
        
        $response = formatPaginatedResponse($branches, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('branches.create');
    
    $input = getRequestBody();
    
    $branchData = [
        'branch_code' => $input['branch_code'] ?? '',
        'branch_name' => $input['branch_name'] ?? '',
        'address' => $input['address'] ?? null,
        'city' => $input['city'] ?? null,
        'country' => $input['country'] ?? null,
        'phone' => $input['phone'] ?? null,
        'email' => $input['email'] ?? null,
        'manager_id' => $input['manager_id'] ?? null,
        'status' => $input['status'] ?? 'Active',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $branchId = $db->insert('branches', $branchData);
    
    if (!$branchId) {
        sendError('Failed to create branch: ' . $db->getLastError(), 500);
    }
    
    $branch = $db->getRow("SELECT * FROM branches WHERE id = :id", [':id' => $branchId]);
    sendSuccess($branch, 'Branch created successfully', 201);
    
} elseif ($method === 'PUT') {
    requirePermission('branches.edit');
    
    if (!$id) {
        sendError('Branch ID is required', 400);
    }
    
    $input = getRequestBody();
    
    $updateData = [];
    $allowedFields = ['branch_code', 'branch_name', 'address', 'city', 'country', 'phone', 'email', 'manager_id', 'status'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateData[$field] = $input[$field];
        }
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    $result = $db->update('branches', $updateData, ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to update branch', 500);
    }
    
    $branch = $db->getRow("SELECT * FROM branches WHERE id = :id", [':id' => $id]);
    sendSuccess($branch, 'Branch updated successfully');
    
} else {
    sendError('Method not allowed', 405);
}


