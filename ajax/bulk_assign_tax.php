<?php
/**
 * Bulk Assign Tax to Products
 * Assigns a tax to multiple products at once
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$auth->requirePermission('products.edit');

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['product_ids']) || !is_array($input['product_ids']) || empty($input['product_ids'])) {
    echo json_encode(['success' => false, 'message' => 'No products selected']);
    exit;
}

$productIds = array_map('intval', $input['product_ids']);
$taxId = isset($input['tax_id']) && $input['tax_id'] !== '' ? intval($input['tax_id']) : null;

try {
    $db = Database::getInstance();
    $updated = 0;
    
    foreach ($productIds as $productId) {
        $updateData = ['tax_id' => $taxId];
        $result = $db->update('products', $updateData, ['id' => $productId]);
        
        if ($result !== false) {
            $updated++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'updated' => $updated,
        'message' => "Tax assigned to $updated product(s)"
    ]);
    
} catch (Exception $e) {
    error_log("Bulk assign tax error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to assign tax: ' . $e->getMessage()
    ]);
}

