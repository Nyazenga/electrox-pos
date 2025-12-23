<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

header('Content-Type: application/json');

// Suppress errors for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['name'])) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Role name is required']);
    exit;
}

try {
    $db = Database::getInstance();
    
    $roleName = trim($input['name']);
    $roleDescription = trim($input['description'] ?? '');
    $permissions = $input['permissions'] ?? [];
    
    // Check if role name already exists (for new roles or if name changed)
    $existingRole = $db->getRow("SELECT id FROM roles WHERE name = :name", [':name' => $roleName]);
    
    if (!empty($input['id'])) {
        // Update existing role
        $roleId = intval($input['id']);
        $role = $db->getRow("SELECT * FROM roles WHERE id = :id", [':id' => $roleId]);
        
        if (!$role) {
            throw new Exception('Role not found');
        }
        
        // Check if name is being changed and conflicts
        if ($role['name'] !== $roleName && $existingRole && $existingRole['id'] != $roleId) {
            throw new Exception('Role name already exists');
        }
        
        // Update role (ALLOW editing system roles)
        $db->update('roles', [
            'name' => $roleName,
            'description' => $roleDescription ?: null,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $roleId]);
        
        // Update permissions (ALLOW editing system roles)
        // Delete existing permissions
        $db->executeQuery("DELETE FROM role_permissions WHERE role_id = :role_id", [':role_id' => $roleId]);
        
        // Add new permissions
        if (!empty($permissions)) {
            foreach ($permissions as $permissionId) {
                $permissionId = intval($permissionId);
                if ($permissionId > 0) {
                    // Verify permission exists
                    $perm = $db->getRow("SELECT id FROM permissions WHERE id = :id", [':id' => $permissionId]);
                    if ($perm) {
                        $db->insert('role_permissions', [
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }
        }
        
        logActivity($_SESSION['user_id'], 'role_updated', ['role_id' => $roleId]);
        
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Role updated successfully'
        ]);
    } else {
        // Create new role
        if ($existingRole) {
            throw new Exception('Role name already exists');
        }
        
        // Insert role
        $roleId = $db->insert('roles', [
            'name' => $roleName,
            'description' => $roleDescription ?: null,
            'is_system_role' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        if (!$roleId) {
            throw new Exception('Failed to create role');
        }
        
        // Add permissions
        if (!empty($permissions)) {
            foreach ($permissions as $permissionId) {
                $permissionId = intval($permissionId);
                if ($permissionId > 0) {
                    // Verify permission exists
                    $perm = $db->getRow("SELECT id FROM permissions WHERE id = :id", [':id' => $permissionId]);
                    if ($perm) {
                        $db->insert('role_permissions', [
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }
        }
        
        logActivity($_SESSION['user_id'], 'role_created', ['role_id' => $roleId]);
        
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Role created successfully',
            'role_id' => $roleId
        ]);
    }
    
} catch (Exception $e) {
    ob_end_clean();
    logError("Save role error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

exit;

