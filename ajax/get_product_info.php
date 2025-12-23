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
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

$db = Database::getInstance();
$product = $db->getRow("SELECT p.*, pc.name as category_name FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE p.id = :id", [':id' => $id]);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

// Get stock in other branches if user has permission
$stockInOtherBranches = [];
if ($auth->hasPermission('inventory.view_other_branches')) {
    $currentBranchId = $_SESSION['branch_id'] ?? null;
    $stockInOtherBranches = $db->getRows(
        "SELECT b.id, b.branch_name, 
                COALESCE(SUM(CASE WHEN p2.id = :product_id THEN p2.quantity_in_stock ELSE 0 END), 0) as stock
         FROM branches b
         LEFT JOIN products p2 ON p2.branch_id = b.id AND p2.id = :product_id
         WHERE b.status = 'Active' AND b.id != :current_branch_id
         GROUP BY b.id, b.branch_name
         ORDER BY b.branch_name",
        [
            ':product_id' => $id,
            ':current_branch_id' => $currentBranchId
        ]
    );
    if ($stockInOtherBranches === false) {
        $stockInOtherBranches = [];
    }
}

$result = ['success' => true, 'product' => $product];
if (!empty($stockInOtherBranches)) {
    $result['stock_in_other_branches'] = $stockInOtherBranches;
}

echo json_encode($result);

