<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();
$auth = Auth::getInstance();
$auth->requireLogin();

header('Content-Type: application/json');
ob_start();

$branchId = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : null;

if (!$branchId) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Branch ID required']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Get products for the specified branch - handle both General category (product_name) and others (brand/model)
    $products = $db->getRows("SELECT p.*, 
                             COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as display_name,
                             c.name as category_name 
                             FROM products p 
                             LEFT JOIN product_categories c ON p.category_id = c.id 
                             WHERE p.status = 'Active' 
                             AND p.branch_id = :branch_id
                             ORDER BY COALESCE(p.product_name, p.brand, ''), p.model", 
                             [':branch_id' => $branchId]);
    
    if ($products === false) {
        $products = [];
    }
    
    // Format products for response
    $formattedProducts = [];
    foreach ($products as $product) {
        $productDisplayName = $product['display_name'] ?? ($product['product_name'] ?? trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? '')));
        if (empty($productDisplayName)) {
            $productDisplayName = 'Product #' . $product['id'];
        }
        
        $formattedProducts[] = [
            'id' => intval($product['id']),
            'display_name' => $productDisplayName,
            'product_name' => $product['product_name'] ?? '',
            'brand' => $product['brand'] ?? '',
            'model' => $product['model'] ?? '',
            'product_code' => $product['product_code'] ?? '',
            'category_name' => $product['category_name'] ?? 'N/A',
            'quantity_in_stock' => intval($product['quantity_in_stock'] ?? 0)
        ];
    }
    
    ob_end_clean();
    echo json_encode(['success' => true, 'products' => $formattedProducts]);
    
} catch (Exception $e) {
    ob_end_clean();
    logError("Get products for branch error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}

