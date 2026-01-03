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
    $productId = intval($_GET['product_id'] ?? 0);
    $quantity = intval($_GET['quantity'] ?? 1);
    $branchId = $_SESSION['branch_id'] ?? null;
    
    if (!$productId) {
        throw new Exception('Product ID is required');
    }
    
    $db = Database::getInstance();
    
    // Get product to check identifier flags
    $product = $db->getRow("SELECT has_serial_number, has_imei, has_batch_number FROM products WHERE id = :id", [':id' => $productId]);
    
    if (!$product) {
        throw new Exception('Product not found');
    }
    
    $identifierTypes = [];
    if ($product['has_serial_number']) {
        $identifierTypes[] = 'serial_number';
    }
    if ($product['has_imei']) {
        $identifierTypes[] = 'imei';
    }
    if ($product['has_batch_number']) {
        $identifierTypes[] = 'batch_number';
    }
    
    if (empty($identifierTypes)) {
        echo json_encode(['success' => true, 'identifiers' => [], 'needs_identifiers' => false]);
        exit;
    }
    
    // Get available identifiers for this product
    $availableIdentifiers = [];
    foreach ($identifierTypes as $type) {
        $identifiers = $db->getRows(
            "SELECT id, identifier_value 
             FROM product_identifiers 
             WHERE product_id = :product_id 
               AND identifier_type = :type 
               AND status = 'available'
               " . ($branchId ? "AND branch_id = :branch_id" : "AND (branch_id = :branch_id OR branch_id IS NULL)") . "
             ORDER BY id ASC
             LIMIT :limit",
            array_merge(
                [':product_id' => $productId, ':type' => $type, ':limit' => $quantity],
                $branchId ? [':branch_id' => $branchId] : []
            )
        );
        
        if ($identifiers !== false) {
            $availableIdentifiers[$type] = $identifiers;
        }
    }
    
    echo json_encode([
        'success' => true,
        'needs_identifiers' => true,
        'identifier_types' => $identifierTypes,
        'available_identifiers' => $availableIdentifiers,
        'required_quantity' => $quantity
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

