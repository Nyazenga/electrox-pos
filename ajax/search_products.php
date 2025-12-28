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

$q = $_GET['q'] ?? '';

if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'products' => []]);
    exit;
}

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

$whereConditions = ["p.status = 'Active'"];
$params = [':search' => '%' . $q . '%'];

if ($branchId) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

$whereConditions[] = "(p.product_name LIKE :search OR p.product_code LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";

$whereClause = implode(' AND ', $whereConditions);

$products = $db->getRows("SELECT p.id, p.product_code,
                          COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as display_name,
                          p.quantity_in_stock
                          FROM products p
                          WHERE $whereClause
                          ORDER BY p.product_code
                          LIMIT 20", $params);

if ($products === false) {
    $products = [];
}

echo json_encode([
    'success' => true,
    'products' => $products
]);

