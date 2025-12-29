<?php
/**
 * Add Shift Management Permission
 * This script adds the pos.shift_management permission and assigns it to Admin role
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();

echo "Adding Shift Management Permission...\n\n";

// Check if permission already exists
$existing = $db->getRow(
    "SELECT id FROM permissions WHERE permission_key = :key",
    [':key' => 'pos.shift_management']
);

if ($existing) {
    echo "✓ Permission 'pos.shift_management' already exists (ID: {$existing['id']})\n";
    $permissionId = $existing['id'];
} else {
    // Insert permission
    $permissionId = $db->insert('permissions', [
        'permission_key' => 'pos.shift_management',
        'permission_name' => 'Shift Management',
        'module' => 'POS',
        'description' => 'View and manage shift reports'
    ]);
    
    if ($permissionId) {
        echo "✓ Permission 'pos.shift_management' created (ID: $permissionId)\n";
    } else {
        die("✗ Failed to create permission\n");
    }
}

// Get Admin role
$adminRole = $db->getRow(
    "SELECT id FROM roles WHERE name = 'Admin' OR name = 'Administrator' LIMIT 1"
);

if (!$adminRole) {
    // Try to get role with ID 1 (usually admin)
    $adminRole = $db->getRow("SELECT id FROM roles WHERE id = 1");
}

if ($adminRole) {
    $roleId = $adminRole['id'];
    
    // Check if permission is already assigned
    $existingAssignment = $db->getRow(
        "SELECT id FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id",
        [':role_id' => $roleId, ':permission_id' => $permissionId]
    );
    
    if ($existingAssignment) {
        echo "✓ Permission already assigned to Admin role\n";
    } else {
        // Assign permission to Admin role
        $assignmentId = $db->insert('role_permissions', [
            'role_id' => $roleId,
            'permission_id' => $permissionId
        ]);
        
        if ($assignmentId) {
            echo "✓ Permission assigned to Admin role (ID: $roleId)\n";
        } else {
            echo "⚠ Failed to assign permission to Admin role\n";
        }
    }
} else {
    echo "⚠ Admin role not found. Please assign the permission manually.\n";
}

echo "\nDone!\n";

