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

$auth->requirePermission('roles.delete');

$input = json_decode(file_get_contents('php://input'), true);
$roleId = intval($input['role_id'] ?? 0);

if (!$roleId) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Role ID is required']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Get role details
    $role = $db->getRow("SELECT * FROM roles WHERE id = :id", [':id' => $roleId]);
    
    if (!$role) {
        throw new Exception('Role not found');
    }
    
    // Check if it's a system role
    if ($role['is_system_role'] ?? 0) {
        throw new Exception('Cannot delete system roles');
    }
    
    // Check if any users are assigned to this role
    $userCount = $db->getRow("SELECT COUNT(*) as count FROM users WHERE role_id = :role_id AND deleted_at IS NULL", [':role_id' => $roleId]);
    if ($userCount && $userCount['count'] > 0) {
        throw new Exception('Cannot delete role. ' . $userCount['count'] . ' user(s) are assigned to this role. Please reassign users first.');
    }
    
    // Delete role permissions (cascade should handle this, but being explicit)
    $db->executeQuery("DELETE FROM role_permissions WHERE role_id = :role_id", [':role_id' => $roleId]);
    
    // Delete role
    $result = $db->executeQuery("DELETE FROM roles WHERE id = :id", [':id' => $roleId]);
    
    if ($result !== false) {
        logActivity($_SESSION['user_id'], 'role_deleted', ['role_id' => $roleId, 'role_name' => $role['name']]);
        
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Role deleted successfully'
        ]);
    } else {
        throw new Exception('Failed to delete role');
    }
    
} catch (Exception $e) {
    ob_end_clean();
    logError("Delete role error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

exit;

