<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

initSession();
$auth = Auth::getInstance();
$auth->requireLogin();

// Check permission - allow if user has inventory create or transfers create permissions
if (!$auth->hasPermission('inventory.create') && !$auth->hasPermission('transfers.create')) {
    http_response_code(403);
    echo json_encode(['exists' => false, 'error' => 'Permission denied']);
    exit;
}

header('Content-Type: application/json');

$number = $_GET['number'] ?? '';

if (empty($number)) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $db = Database::getInstance();
    $existing = $db->getRow("SELECT id FROM stock_transfers WHERE transfer_number = :number", [':number' => $number]);
    echo json_encode(['exists' => $existing !== false]);
} catch (Exception $e) {
    echo json_encode(['exists' => false]);
}


