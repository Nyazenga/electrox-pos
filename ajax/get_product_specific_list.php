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
$branchId = !empty($_GET['branch_id']) ? intval($_GET['branch_id']) : null;
$status = !empty($_GET['status']) ? sanitizeInput($_GET['status']) : null; // null = all items
$quantity = !empty($_GET['quantity']) ? intval($_GET['quantity']) : null;
$showAll = !empty($_GET['show_all']) && $_GET['show_all'] == '1'; // For manage modal

try {
    $db = Database::getInstance();
    
    if ($productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        exit;
    }
    
    // If show_all is true or status is not specified, get all items
    if ($showAll || $status === null) {
        $whereConditions = ['product_id = :product_id'];
        $params = [':product_id' => $productId];
        
        if ($branchId !== null) {
            $whereConditions[] = 'branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        $limit = $quantity !== null ? "LIMIT " . intval($quantity) : '';
        
        $entries = $db->getRows("SELECT * FROM product_specific_list WHERE $whereClause ORDER BY created_at DESC $limit", $params);
        $entries = $entries !== false ? $entries : [];
    } else {
        // Use existing function for available items only
        $entries = getAvailableProductSpecificList($productId, $branchId, $quantity, $db);
    }
    
    echo json_encode([
        'success' => true,
        'entries' => $entries,
        'count' => count($entries)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
