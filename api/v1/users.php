<?php
/**
 * Users API Endpoint
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
    requirePermission('users.view');
    
    if ($id) {
        $userData = $db->getRow("SELECT u.*, r.name as role_name, b.branch_name FROM users u LEFT JOIN roles r ON u.role_id = r.id LEFT JOIN branches b ON u.branch_id = b.id WHERE u.id = :id AND u.deleted_at IS NULL", [':id' => $id]);
        if (!$userData) {
            sendError('User not found', 404);
        }
        // Remove sensitive data
        unset($userData['password']);
        sendSuccess($userData);
    } else {
        $users = $db->getRows("SELECT u.*, r.name as role_name, b.branch_name FROM users u LEFT JOIN roles r ON u.role_id = r.id LEFT JOIN branches b ON u.branch_id = b.id WHERE u.deleted_at IS NULL ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset",
                              [
                                  ':limit' => $pagination['limit'],
                                  ':offset' => $pagination['offset']
                              ]);
        
        if ($users === false) {
            $users = [];
        }
        
        // Remove passwords
        foreach ($users as &$u) {
            unset($u['password']);
        }
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM users WHERE deleted_at IS NULL");
        $totalCount = $total ? intval($total['count']) : 0;
        
        $response = formatPaginatedResponse($users, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('users.create');
    
    $input = getRequestBody();
    
    if (!isset($input['email']) || !isset($input['password'])) {
        sendError('Email and password are required', 400);
    }
    
    $userData = [
        'first_name' => $input['first_name'] ?? '',
        'last_name' => $input['last_name'] ?? '',
        'email' => $input['email'],
        'password' => password_hash($input['password'], PASSWORD_DEFAULT),
        'role_id' => $input['role_id'] ?? null,
        'branch_id' => $input['branch_id'] ?? null,
        'phone' => $input['phone'] ?? null,
        'status' => $input['status'] ?? 'active',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $userId = $db->insert('users', $userData);
    
    if (!$userId) {
        sendError('Failed to create user: ' . $db->getLastError(), 500);
    }
    
    $newUser = $db->getRow("SELECT * FROM users WHERE id = :id", [':id' => $userId]);
    unset($newUser['password']);
    sendSuccess($newUser, 'User created successfully', 201);
    
} elseif ($method === 'PUT') {
    requirePermission('users.edit');
    
    if (!$id) {
        sendError('User ID is required', 400);
    }
    
    $input = getRequestBody();
    
    $updateData = [];
    $allowedFields = ['first_name', 'last_name', 'email', 'role_id', 'branch_id', 'phone', 'status'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateData[$field] = $input[$field];
        }
    }
    
    if (isset($input['password']) && !empty($input['password'])) {
        $updateData['password'] = password_hash($input['password'], PASSWORD_DEFAULT);
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    $result = $db->update('users', $updateData, ['id' => $id]);
    
    if ($result === false) {
        sendError('Failed to update user', 500);
    }
    
    $updatedUser = $db->getRow("SELECT * FROM users WHERE id = :id", [':id' => $id]);
    unset($updatedUser['password']);
    sendSuccess($updatedUser, 'User updated successfully');
    
} else {
    sendError('Method not allowed', 405);
}


