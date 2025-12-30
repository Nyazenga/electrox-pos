<?php
require 'config.php';
require 'includes/db.php';

$db = Database::getInstance();
$user = $db->getRow("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.email = ?", ['admin@electrox.co.zw']);

echo "Admin User:\n";
print_r($user);

$perms = $db->getRows("SELECT COUNT(*) as count FROM role_permissions WHERE role_id = ?", [$user['role_id']]);
echo "\nPermissions for role_id {$user['role_id']}: " . ($perms[0]['count'] ?? 0) . "\n";

