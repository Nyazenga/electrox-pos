<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

initSession();

header('Content-Type: application/json; charset=utf-8');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    $auth->requirePermission('products.stock_take');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$db = Database::getInstance();
$branchId = $input['branch_id'] ?? $_SESSION['branch_id'] ?? null;
$categoryId = $input['category_id'] ?? null;
$search = $input['search'] ?? '';

$whereConditions = ["p.status = 'Active'"];
$params = [];

if ($branchId) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($categoryId) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $categoryId;
}

if (!empty($search)) {
    $whereConditions[] = "(p.product_name LIKE :search OR p.product_code LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$whereClause = implode(' AND ', $whereConditions);

$products = $db->getRows("SELECT p.id, p.product_code, 
                          COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as display_name,
                          p.quantity_in_stock,
                          pc.name as category_name
                          FROM products p
                          LEFT JOIN product_categories pc ON p.category_id = pc.id
                          WHERE $whereClause
                          ORDER BY p.product_code
                          LIMIT 1000", $params);

if ($products === false) {
    $products = [];
}

echo json_encode([
    'success' => true,
    'products' => $products
]);

