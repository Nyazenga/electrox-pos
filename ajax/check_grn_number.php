<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

initSession();
$auth = Auth::getInstance();
$auth->requireLogin();

header('Content-Type: application/json');

$number = $_GET['number'] ?? '';

if (empty($number)) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $db = Database::getInstance();
    $existing = $db->getRow("SELECT id FROM goods_received_notes WHERE grn_number = :number", [':number' => $number]);
    echo json_encode(['exists' => $existing !== false]);
} catch (Exception $e) {
    echo json_encode(['exists' => false]);
}


