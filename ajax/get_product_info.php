<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$productId = intval($_GET['product_id'] ?? 0);

if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

try {
$db = Database::getInstance();
    
    $product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $productId]);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

    $requiresSpecificList = productRequiresSpecificList($product, $db);
    
    echo json_encode([
        'success' => true,
        'product' => $product,
        'requires_specific_list' => $requiresSpecificList
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
