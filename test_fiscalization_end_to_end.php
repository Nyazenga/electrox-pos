<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_helper.php';

echo "=== End-to-End Fiscalization Test ===\n\n";

// Get HEAD OFFICE branch
$primaryDb = Database::getPrimaryInstance();
$branch = $primaryDb->getRow("SELECT id, branch_name FROM branches WHERE branch_name LIKE '%HEAD%' OR id = 1 LIMIT 1");

if (!$branch) {
    die("Branch not found\n");
}

$branchId = $branch['id'];
echo "Branch: {$branch['branch_name']} (ID: $branchId)\n\n";

// Get the most recent sale from tenant database
$db = Database::getInstance();
$sale = $db->getRow("SELECT * FROM sales WHERE branch_id = :branch_id ORDER BY id DESC LIMIT 1", [':branch_id' => $branchId]);

if (!$sale) {
    echo "No sales found for branch $branchId\n";
    echo "Checking all sales...\n";
    $allSales = $db->getRows("SELECT id, branch_id, total_amount, payment_status FROM sales ORDER BY id DESC LIMIT 5");
    if (empty($allSales)) {
        die("No sales found at all. Please make a sale first.\n");
    }
    echo "Found sales:\n";
    foreach ($allSales as $s) {
        echo "  Sale ID: {$s['id']}, Branch: {$s['branch_id']}, Total: {$s['total_amount']}\n";
    }
    // Use the most recent one
    $sale = $allSales[0];
    echo "\nUsing Sale ID: {$sale['id']}\n\n";
}

echo "Sale Details:\n";
echo "  Sale ID: {$sale['id']}\n";
echo "  Branch ID: {$sale['branch_id']}\n";
echo "  Total: {$sale['total_amount']}\n";
echo "  Payment Status: {$sale['payment_status']}\n";
echo "  Created: {$sale['created_at']}\n\n";

// Check if already fiscalized
$fiscalReceipt = $primaryDb->getRow(
    "SELECT * FROM fiscal_receipts WHERE sale_id = :sale_id",
    [':sale_id' => $sale['id']]
);

if ($fiscalReceipt) {
    echo "⚠ Sale is already fiscalized:\n";
    echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
    echo "  Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
    echo "  Has QR Code: " . (!empty($fiscalReceipt['receipt_qr_code']) ? 'Yes' : 'No') . "\n";
    exit;
}

// Try to fiscalize
echo "Attempting to fiscalize sale {$sale['id']}...\n\n";

try {
    $result = fiscalizeSale($sale['id'], $sale['branch_id'], $db);
    
    if ($result) {
        echo "✓ Fiscalization successful!\n";
        
        // Check fiscal receipt
        $fiscalReceipt = $primaryDb->getRow(
            "SELECT * FROM fiscal_receipts WHERE sale_id = :sale_id",
            [':sale_id' => $sale['id']]
        );
        
        if ($fiscalReceipt) {
            echo "\n✓ Fiscal receipt created:\n";
            echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
            echo "  Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
            echo "  Has QR Code: " . (!empty($fiscalReceipt['receipt_qr_code']) ? 'Yes' : 'No') . "\n";
            echo "  Device ID: {$fiscalReceipt['device_id']}\n";
        } else {
            echo "\n⚠ Fiscal receipt not found in database\n";
        }
        
        // Check sale record
        $updatedSale = $db->getRow("SELECT fiscalized, fiscal_details FROM sales WHERE id = :id", [':id' => $sale['id']]);
        if ($updatedSale) {
            echo "\nSale record updated:\n";
            echo "  Fiscalized: {$updatedSale['fiscalized']}\n";
            echo "  Fiscal Details: " . ($updatedSale['fiscal_details'] ?? 'None') . "\n";
        }
    } else {
        echo "✗ Fiscalization returned false\n";
    }
} catch (Exception $e) {
    echo "✗ Fiscalization failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

