<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_helper.php';

echo "=== Complete Fiscalization Test ===\n\n";

// Get HEAD OFFICE branch
$primaryDb = Database::getPrimaryInstance();
$branch = $primaryDb->getRow("SELECT id, branch_name FROM branches WHERE branch_name LIKE '%HEAD%' OR id = 1 LIMIT 1");

if (!$branch) {
    die("Branch not found\n");
}

$branchId = $branch['id'];
echo "Branch: {$branch['branch_name']} (ID: $branchId)\n\n";

// Get the most recent sale
$db = Database::getInstance();
$sale = $db->getRow("SELECT * FROM sales ORDER BY id DESC LIMIT 1");

if (!$sale) {
    die("No sales found. Please make a sale first.\n");
}

echo "Most Recent Sale:\n";
echo "  Sale ID: {$sale['id']}\n";
echo "  Branch ID: {$sale['branch_id']}\n";
echo "  Total: {$sale['total_amount']}\n";
echo "  Payment Status: {$sale['payment_status']}\n";
echo "  Created: {$sale['created_at']}\n\n";

// Check if sale has fiscalized field
$fiscalized = $db->getRow("SELECT fiscalized, fiscal_details FROM sales WHERE id = :id", [':id' => $sale['id']]);
if ($fiscalized) {
    echo "  Fiscalized: " . ($fiscalized['fiscalized'] ?? 0) . "\n";
    echo "  Fiscal Details: " . ($fiscalized['fiscal_details'] ?? 'None') . "\n\n";
}

// Check if already fiscalized
$fiscalReceipt = $primaryDb->getRow(
    "SELECT * FROM fiscal_receipts WHERE sale_id = :sale_id",
    [':sale_id' => $sale['id']]
);

if ($fiscalReceipt) {
    echo "⚠ Sale is already fiscalized:\n";
    echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
    echo "  Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
    exit;
}

// Try to fiscalize
echo "Attempting to fiscalize sale {$sale['id']}...\n\n";

try {
    $result = fiscalizeSale($sale['id'], $branchId, $db);
    
    if ($result) {
        echo "✓ Fiscalization successful!\n";
        print_r($result);
        
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
        }
    } else {
        echo "✗ Fiscalization returned false\n";
    }
} catch (Exception $e) {
    echo "✗ Fiscalization failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

