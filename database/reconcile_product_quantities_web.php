<?php
/**
 * Reconciliation Script: Sync Product Quantities with Product Specific List Counts
 * 
 * This script ensures that for products requiring specific_list:
 * - quantity_in_stock matches the count of 'available' product_specific_list entries
 * 
 * Access via browser: http://localhost/electrox-pos/database/reconcile_product_quantities_web.php
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

// Require admin login
$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.edit');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Product Quantity Reconciliation</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>Product Quantity Reconciliation</h1>
    <p>This script syncs product quantities with available product_specific_list items.</p>
    <hr>
    <pre>
<?php

echo "=== Product Quantity Reconciliation Script ===\n\n";

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get all products that require specific list
$products = $db->getRows(
    "SELECT id, product_code, product_name, brand, model, branch_id, quantity_in_stock, requires_specific_list 
     FROM products 
     WHERE requires_specific_list = 1 
     ORDER BY id"
);

if ($products === false) {
    $products = [];
}

echo "Found " . count($products) . " products requiring specific list.\n\n";

$fixed = 0;
$errors = 0;
$skipped = 0;

foreach ($products as $product) {
    $productId = $product['id'];
    $branchId = $product['branch_id'];
    $currentQty = (int)($product['quantity_in_stock'] ?? 0);
    
    // Get count of available items
    $availableCount = getProductSpecificListCount($productId, $branchId, 'available', $db);
    
    // Get total count (all statuses) for reporting
    $totalCount = getProductSpecificListCount($productId, $branchId, null, $db);
    
    $productName = !empty($product['product_name']) 
        ? $product['product_name'] 
        : ($product['brand'] . ' ' . $product['model']);
    
    if ($currentQty !== $availableCount) {
        echo "Product ID {$productId} ({$productName}):\n";
        echo "  Current quantity: {$currentQty}\n";
        echo "  Available items: {$availableCount}\n";
        echo "  Total items: {$totalCount}\n";
        
        // Update quantity to match available count
        $result = $db->update('products', 
            ['quantity_in_stock' => $availableCount], 
            ['id' => $productId]
        );
        
        if ($result !== false) {
            echo "  ✓ Fixed: Updated quantity to {$availableCount}\n";
            $fixed++;
            
            // Create stock movement record for audit (if table exists and has required columns)
            try {
                $movementData = [
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'movement_type' => 'Reconciliation',
                    'quantity' => $availableCount - $currentQty,
                    'previous_quantity' => $currentQty,
                    'new_quantity' => $availableCount,
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                // Try to add notes if column exists
                $db->insert('stock_movements', $movementData);
            } catch (Exception $e) {
                // Ignore stock movement errors - reconciliation is more important
            }
        } else {
            echo "  ✗ Error: Failed to update quantity - " . $db->getLastError() . "\n";
            $errors++;
        }
        echo "\n";
    } else {
        $skipped++;
    }
}

echo "\n=== Summary ===\n";
echo "Total products checked: " . count($products) . "\n";
echo "Products fixed: {$fixed}\n";
echo "Products skipped (already correct): {$skipped}\n";
echo "Errors: {$errors}\n";
echo "\nReconciliation complete!\n";

?>
    </pre>
    <hr>
    <p><a href="<?= BASE_URL ?>modules/products/index.php">Back to Products</a></p>
</body>
</html>
