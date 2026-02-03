<?php
/**
 * Reconciliation Script: Sync Product Quantities with Product Specific List Counts
 * 
 * This script ensures that for products requiring specific_list:
 * - quantity_in_stock matches the count of 'available' product_specific_list entries
 * 
 * Run this script to fix any inconsistencies in the database.
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/functions.php';

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
            
            // Create stock movement record for audit
            $db->insert('stock_movements', [
                'product_id' => $productId,
                'branch_id' => $branchId,
                'movement_type' => 'Reconciliation',
                'quantity' => $availableCount - $currentQty,
                'previous_quantity' => $currentQty,
                'new_quantity' => $availableCount,
                'user_id' => null,
                'notes' => 'Automatic reconciliation: Quantity synced with available product_specific_list items',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            echo "  ✗ Error: Failed to update quantity\n";
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
