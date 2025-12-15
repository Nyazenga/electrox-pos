<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';

initSession();

// Handle POST data if sent (for explicit clearing)
$data = json_decode(file_get_contents('php://input'), true);
if ($data) {
    $_SESSION['pos_cart'] = $data['cart'] ?? [];
    $_SESSION['pos_customer'] = $data['customer'] ?? null;
    $_SESSION['pos_discount'] = $data['discount'] ?? ['type' => null, 'amount' => 0];
} else {
    // Clear everything
    unset($_SESSION['pos_cart']);
    unset($_SESSION['pos_customer']);
    unset($_SESSION['pos_discount']);
}

// Ensure discount is always cleared if cart is empty
if (empty($_SESSION['pos_cart'])) {
    $_SESSION['pos_discount'] = ['type' => null, 'amount' => 0];
    $_SESSION['pos_customer'] = null;
}

echo json_encode(['success' => true]);

