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

// Handle POST data if sent (for explicit clearing)
$data = json_decode(file_get_contents('php://input'), true);
if ($data) {
    $_SESSION['pos_cart'] = $data['cart'] ?? [];
    $_SESSION['pos_customer'] = $data['customer'] ?? null;
    $_SESSION['pos_discount'] = $data['discount'] ?? ['type' => null, 'amount' => 0];
    $_SESSION['pos_delivery_cost'] = $data['delivery_cost'] ?? 0;
} else {
    // Clear everything
    unset($_SESSION['pos_cart']);
    unset($_SESSION['pos_customer']);
    unset($_SESSION['pos_discount']);
    unset($_SESSION['pos_delivery_cost']);
}

// Ensure discount and delivery cost are always cleared if cart is empty
if (empty($_SESSION['pos_cart'])) {
    $_SESSION['pos_discount'] = ['type' => null, 'amount' => 0];
    $_SESSION['pos_customer'] = null;
    $_SESSION['pos_delivery_cost'] = 0;
}

echo json_encode(['success' => true]);

