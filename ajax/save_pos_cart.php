<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';

initSession();

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

$_SESSION['pos_cart'] = $data['cart'] ?? [];
$_SESSION['pos_customer'] = $data['customer'] ?? null;
$_SESSION['pos_discount'] = $data['discount'] ?? ['type' => null, 'amount' => 0];

echo json_encode(['success' => true]);

