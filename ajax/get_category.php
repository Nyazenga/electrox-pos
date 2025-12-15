<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

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

echo json_encode(['success' => true, 'category' => $category]);

