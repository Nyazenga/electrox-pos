<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/auth.php';

initSession();

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check permission to access POS
try {
    $auth->requirePermission('pos.access');
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied: ' . $e->getMessage()]);
    exit;
}

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['cart'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Cart data is required']);
    exit;
}

// Update cart in session
$_SESSION['pos_cart'] = $data['cart'] ?? [];

// Optionally update customer, discount, and delivery cost if provided
if (isset($data['customer'])) {
    $_SESSION['pos_customer'] = $data['customer'];
}
if (isset($data['discount'])) {
    $_SESSION['pos_discount'] = $data['discount'];
}
if (isset($data['delivery_cost'])) {
    $_SESSION['pos_delivery_cost'] = floatval($data['delivery_cost']);
}

echo json_encode(['success' => true, 'message' => 'Cart updated successfully']);
