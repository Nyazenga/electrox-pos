<?php
require_once dirname(__FILE__) . '/../config.php';
require_once APP_PATH . '/includes/db.php';

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

