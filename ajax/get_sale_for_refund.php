<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$auth->requirePermission('pos.access');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Get sale with all details
    $sale = $db->getRow("SELECT s.*, c.first_name, c.last_name, u.first_name as cashier_first, u.last_name as cashier_last 
                          FROM sales s 
                          LEFT JOIN customers c ON s.customer_id = c.id 
                          LEFT JOIN users u ON s.user_id = u.id 
                          WHERE s.id = :id", [':id' => $id]);
    
    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'Sale not found']);
        exit;
    }
    
    // Check if already refunded
    if ($sale['payment_status'] === 'refunded') {
        echo json_encode(['success' => false, 'message' => 'This sale has already been refunded']);
        exit;
    }
    
    // Get sale items
    $items = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :id", [':id' => $id]);
    if ($items === false) {
        $items = [];
    }
    
    // Get payments
    $payments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $id]);
    if ($payments === false) {
        $payments = [];
    }
    
    $sale['items'] = $items;
    $sale['payments'] = $payments;
    
    echo json_encode(['success' => true, 'sale' => $sale]);
    
} catch (Exception $e) {
    logError("Error loading sale for refund: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load sale: ' . $e->getMessage()]);
}

