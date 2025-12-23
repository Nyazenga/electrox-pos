<?php
/**
 * Roles API Endpoint
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
    requirePermission('roles.view');
    
    if ($id) {
        $role = $db->getRow("SELECT r.*, COUNT(DISTINCT rp.permission_id) as permission_count, COUNT(DISTINCT u.id) as user_count 
                            FROM roles r 
                            LEFT JOIN role_permissions rp ON r.id = rp.role_id 
                            LEFT JOIN users u ON r.id = u.role_id AND u.deleted_at IS NULL
                            WHERE r.id = :id
                            GROUP BY r.id", [':id' => $id]);
        
        if (!$role) {
            sendError('Role not found', 404);
        }
        
        // Get permissions
        $permissions = $db->getRows("SELECT p.* FROM permissions p 
                                    INNER JOIN role_permissions rp ON p.id = rp.permission_id 
                                    WHERE rp.role_id = :id", [':id' => $id]);
        $role['permissions'] = $permissions ?: [];
        
        sendSuccess($role);
    } else {
        $roles = $db->getRows("SELECT r.*, COUNT(DISTINCT rp.permission_id) as permission_count, COUNT(DISTINCT u.id) as user_count 
                              FROM roles r 
                              LEFT JOIN role_permissions rp ON r.id = rp.role_id 
                              LEFT JOIN users u ON r.id = u.role_id AND u.deleted_at IS NULL
                              GROUP BY r.id 
                              ORDER BY r.name
                              LIMIT :limit OFFSET :offset",
                              [
                                  ':limit' => $pagination['limit'],
                                  ':offset' => $pagination['offset']
                              ]);
        
        if ($roles === false) {
            $roles = [];
        }
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM roles");
        $totalCount = $total ? intval($total['count']) : 0;
        
        $response = formatPaginatedResponse($roles, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('roles.create');
    
    $input = getRequestBody();
    
    if (!isset($input['name']) || empty($input['name'])) {
        sendError('Role name is required', 400);
    }
    
    $db->beginTransaction();
    
    try {
        $roleData = [
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'is_system_role' => isset($input['is_system_role']) ? intval($input['is_system_role']) : 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $roleId = $db->insert('roles', $roleData);
        
        if (!$roleId) {
            throw new Exception('Failed to create role: ' . $db->getLastError());
        }
        
        // Assign permissions if provided
        if (isset($input['permissions']) && is_array($input['permissions'])) {
            foreach ($input['permissions'] as $permissionId) {
                $db->insert('role_permissions', [
                    'role_id' => $roleId,
                    'permission_id' => intval($permissionId)
                ]);
            }
        }
        
        $db->commitTransaction();
        
        $role = $db->getRow("SELECT * FROM roles WHERE id = :id", [':id' => $roleId]);
        sendSuccess($role, 'Role created successfully', 201);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        sendError('Failed to create role: ' . $e->getMessage(), 500);
    }
    
} elseif ($method === 'PUT') {
    requirePermission('roles.edit');
    
    if (!$id) {
        sendError('Role ID is required', 400);
    }
    
    $input = getRequestBody();
    
    $db->beginTransaction();
    
    try {
        $updateData = [];
        if (isset($input['name'])) {
            $updateData['name'] = $input['name'];
        }
        if (isset($input['description'])) {
            $updateData['description'] = $input['description'];
        }
        
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        
        $result = $db->update('roles', $updateData, ['id' => $id]);
        
        if ($result === false) {
            throw new Exception('Failed to update role');
        }
        
        // Update permissions if provided
        if (isset($input['permissions']) && is_array($input['permissions'])) {
            // Delete existing permissions
            $db->delete('role_permissions', ['role_id' => $id]);
            
            // Add new permissions
            foreach ($input['permissions'] as $permissionId) {
                $db->insert('role_permissions', [
                    'role_id' => $id,
                    'permission_id' => intval($permissionId)
                ]);
            }
        }
        
        $db->commitTransaction();
        
        $role = $db->getRow("SELECT * FROM roles WHERE id = :id", [':id' => $id]);
        sendSuccess($role, 'Role updated successfully');
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        sendError('Failed to update role: ' . $e->getMessage(), 500);
    }
    
} elseif ($method === 'DELETE') {
    requirePermission('roles.delete');
    
    if (!$id) {
        sendError('Role ID is required', 400);
    }
    
    // Check if role has users
    $userCount = $db->getRow("SELECT COUNT(*) as count FROM users WHERE role_id = :id AND deleted_at IS NULL", [':id' => $id]);
    if ($userCount && intval($userCount['count']) > 0) {
        sendError('Cannot delete role with assigned users', 400);
    }
    
    $db->beginTransaction();
    
    try {
        // Delete role permissions
        $db->delete('role_permissions', ['role_id' => $id]);
        
        // Delete role
        $result = $db->delete('roles', ['id' => $id]);
        
        if ($result === false) {
            throw new Exception('Failed to delete role');
        }
        
        $db->commitTransaction();
        
        sendSuccess([], 'Role deleted successfully');
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        sendError('Failed to delete role: ' . $e->getMessage(), 500);
    }
} else {
    sendError('Method not allowed', 405);
}


