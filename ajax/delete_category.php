<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.delete');

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$id = intval($input['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid category ID']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Check if category has products
    $productCount = $db->getCount("SELECT COUNT(*) FROM products WHERE category_id = :id", [':id' => $id]);
    
    if ($productCount > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete category with existing products']);
        exit;
    }
    
    $db->delete('product_categories', ['id' => $id]);
    
    echo json_encode(['success' => true, 'message' => 'Category deleted successfully']);
    
} catch (Exception $e) {
    logError("Delete category error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to delete category: ' . $e->getMessage()]);
}

