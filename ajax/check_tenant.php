<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once dirname(dirname(__FILE__)) . '/includes/functions.php';

header('Content-Type: application/json');

$tenant_name = strtolower(trim($_POST['tenant_name'] ?? ''));

if (empty($tenant_name)) {
    echo json_encode(['available' => false, 'message' => 'Tenant name is required']);
    exit;
}

if (!preg_match('/^[a-z0-9]+$/', $tenant_name)) {
    echo json_encode(['available' => false, 'message' => 'Invalid format']);
    exit;
}

if (strlen($tenant_name) < 3 || strlen($tenant_name) > 20) {
    echo json_encode(['available' => false, 'message' => 'Length must be 3-20 characters']);
    exit;
}

$exists = checkTenantExists($tenant_name);

if ($exists) {
    $suggestions = generateTenantSuggestions($tenant_name);
    echo json_encode([
        'available' => false,
        'message' => 'Tenant name already taken',
        'suggestions' => $suggestions
    ]);
} else {
    echo json_encode([
        'available' => true,
        'message' => 'Tenant name is available'
    ]);
}

