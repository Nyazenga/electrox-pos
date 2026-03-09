<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

// Check permission - allow if user has products view or categories permission
if (!$auth->hasPermission('products.view') && !$auth->hasPermission('products.categories')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid category ID']);
    exit;
}

$db = Database::getInstance();
$category = $db->getRow("SELECT * FROM product_categories WHERE id = :id", [':id' => $id]);

if (!$category) {
    echo json_encode(['success' => false, 'message' => 'Category not found']);
    exit;
}

// Get assigned characteristics
$characteristics = $db->getRows(
    "SELECT cc.*, cca.is_required, cca.sort_order as assignment_order
     FROM category_characteristics cc
     INNER JOIN category_characteristic_assignments cca ON cc.id = cca.characteristic_id
     WHERE cca.category_id = :category_id AND cc.is_active = 1
     ORDER BY cca.sort_order, cc.sort_order",
    [':category_id' => $id]
);

$category['characteristics'] = $characteristics ?: [];

echo json_encode(['success' => true, 'category' => $category]);
