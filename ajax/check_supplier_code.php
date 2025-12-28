<?php
require_once dirname(__FILE__) . '/../config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

initSession();
$auth = Auth::getInstance();
$auth->requireLogin();

// Check permission - allow if user has suppliers create or edit permissions
if (!$auth->hasPermission('suppliers.create') && !$auth->hasPermission('suppliers.edit')) {
    http_response_code(403);
    echo json_encode(['exists' => false, 'error' => 'Permission denied']);
    exit;
}

header('Content-Type: application/json');

$code = $_GET['code'] ?? '';

if (empty($code)) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $db = Database::getInstance();
    $existing = $db->getRow("SELECT id FROM suppliers WHERE supplier_code = :code", [':code' => $code]);
    echo json_encode(['exists' => $existing !== false]);
} catch (Exception $e) {
    echo json_encode(['exists' => false]);
}

