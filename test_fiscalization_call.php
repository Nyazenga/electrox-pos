<?php
// Simulate a sale being processed
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [];

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_helper.php';

// Get HEAD OFFICE branch
$primaryDb = Database::getPrimaryInstance();
$branch = $primaryDb->getRow("SELECT id FROM branches WHERE branch_name LIKE '%HEAD%' OR id = 1");

if (!$branch) {
    die("Branch not found\n");
}

$branchId = $branch['id'];
echo "Testing fiscalization for branch ID: $branchId\n\n";

// Create a test sale
$db = Database::getInstance();
$db->beginTransaction();

try {
    // Get a shift
    $shift = $db->getRow("SELECT * FROM shifts WHERE branch_id = :branch_id AND status = 'open' ORDER BY id DESC LIMIT 1", [':branch_id' => $branchId]);
    
    if (!$shift) {
        die("No open shift found\n");
    }
    
    // Create sale
    $saleId = $db->insert('sales', [
        'branch_id' => $branchId,
        'shift_id' => $shift['id'],
        'user_id' => 1,
        'customer_id' => null,
        'total' => 100.00,
        'subtotal' => 100.00,
        'tax_amount' => 0.00,
        'discount_amount' => 0.00,
        'status' => 'completed',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo "Created test sale ID: $saleId\n";
    
    // Add a sale item
    $db->insert('sale_items', [
        'sale_id' => $saleId,
        'product_id' => 1,
        'quantity' => 1,
        'unit_price' => 100.00,
        'total' => 100.00
    ]);
    
    // Add payment
    $db->insert('sale_payments', [
        'sale_id' => $saleId,
        'payment_method' => 'cash',
        'amount' => 100.00,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    $db->commitTransaction();
    
    echo "Sale created successfully\n\n";
    
    // Now try to fiscalize
    echo "Attempting to fiscalize...\n";
    $result = fiscalizeSale($saleId, $branchId, $db);
    
    if ($result) {
        echo "✓ Fiscalization successful!\n";
    } else {
        echo "✗ Fiscalization returned false\n";
    }
    
    // Check fiscal receipt
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT * FROM fiscal_receipts WHERE sale_id = :sale_id",
        [':sale_id' => $saleId]
    );
    
    if ($fiscalReceipt) {
        echo "\n✓ Fiscal receipt created:\n";
        echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
        echo "  Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
    } else {
        echo "\n✗ No fiscal receipt created\n";
    }
    
} catch (Exception $e) {
    $db->rollbackTransaction();
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

