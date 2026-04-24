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
// Use session branch_id (set from top nav bar) - consistent with POS and other pages
$branchId = $_SESSION['branch_id'] ?? null;

if (!$branchId) {
    echo json_encode(['success' => false, 'message' => 'Branch not selected']);
    exit;
}

$whereConditions = ["p.status = 'Active'", "p.branch_id = :branch_id"];
$params = [':branch_id' => $branchId];

// Use the same search pattern as products/index.php - search across multiple fields with concatenated combinations
$whereConditions[] = "(p.brand LIKE :search1 
                       OR p.model LIKE :search2 
                       OR p.product_name LIKE :search5 
                       OR p.product_code LIKE :search3 
                       OR p.description LIKE :search4
                       OR CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, '')) LIKE :search6
                       OR CONCAT(COALESCE(p.product_name, ''), ' ', COALESCE(p.brand, ''), ' ', COALESCE(p.model, '')) LIKE :search7
                       OR EXISTS (
                           SELECT 1 FROM product_specific_list psl
                           WHERE psl.product_id = p.id
                           AND psl.status = 'available'
                           AND (psl.branch_id = p.branch_id OR psl.branch_id IS NULL)
                           AND (
                               psl.serial_number LIKE :search8_serial
                               OR psl.imei LIKE :search8_imei
                           )
                       ))";
$searchTerm = "%$q%";
$params[':search1'] = $searchTerm;
$params[':search2'] = $searchTerm;
$params[':search3'] = $searchTerm;
$params[':search4'] = $searchTerm;
$params[':search5'] = $searchTerm;
$params[':search6'] = $searchTerm;
$params[':search7'] = $searchTerm;
$params[':search8_serial'] = $searchTerm;
$params[':search8_imei'] = $searchTerm;

$whereClause = implode(' AND ', $whereConditions);

$products = $db->getRows("SELECT p.id, p.product_code, p.product_name, p.brand, p.model,
                          COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as display_name,
                          p.quantity_in_stock, p.selling_price,
                          COALESCE(pc.is_specific, 0) as is_specific,
                          (SELECT MIN(psl.selling_price) FROM product_specific_list psl 
                           WHERE psl.product_id = p.id AND psl.status = 'available' AND psl.selling_price > 0) as specific_min_price
                          FROM products p
                          LEFT JOIN product_categories pc ON p.category_id = pc.id
                          WHERE $whereClause
                          ORDER BY p.product_code
                          LIMIT 20", $params);

if ($products === false) {
    $products = [];
}

// For specific products with no base price, use specific item price
foreach ($products as &$p) {
    if (!empty($p['is_specific']) && (empty($p['selling_price']) || $p['selling_price'] <= 0) && !empty($p['specific_min_price'])) {
        $p['selling_price'] = $p['specific_min_price'];
    }
}
unset($p);

echo json_encode([
    'success' => true,
    'products' => $products
]);


