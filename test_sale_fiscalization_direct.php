<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_helper.php';

// Get the most recent sale
$db = Database::getInstance();
$sale = $db->getRow("SELECT * FROM sales ORDER BY id DESC LIMIT 1");

if (!$sale) {
    die("No sales found\n");
}

echo "=== Testing Fiscalization for Sale ID: {$sale['id']} ===\n\n";
echo "Sale Details:\n";
echo "  Branch ID: {$sale['branch_id']}\n";
echo "  Total: {$sale['total']}\n";
echo "  Fiscalized: {$sale['fiscalized']}\n";
echo "  Fiscal Details: " . ($sale['fiscal_details'] ?? 'None') . "\n\n";

// Check if fiscalization is enabled
$primaryDb = Database::getPrimaryInstance();
$branch = $primaryDb->getRow(
    "SELECT id, fiscalization_enabled FROM branches WHERE id = :id",
    [':id' => $sale['branch_id']]
);

if (!$branch) {
    die("Branch not found\n");
}

echo "Branch Status:\n";
echo "  Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'Yes' : 'No') . "\n\n";

if (!$branch['fiscalization_enabled']) {
    die("Fiscalization is not enabled for this branch\n");
}

// Try to fiscalize
echo "Attempting to fiscalize sale...\n";
try {
    $result = fiscalizeSale($sale['id'], $sale['branch_id'], $db);
    
    if ($result) {
        echo "✓ Fiscalization successful!\n";
        print_r($result);
    } else {
        echo "✗ Fiscalization returned false\n";
    }
} catch (Exception $e) {
    echo "✗ Fiscalization failed: " . $e->getMessage() . "\n";
    echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
}

// Check fiscal receipt
echo "\nChecking fiscal receipt...\n";
$fiscalReceipt = $primaryDb->getRow(
    "SELECT * FROM fiscal_receipts WHERE sale_id = :sale_id",
    [':sale_id' => $sale['id']]
);

if ($fiscalReceipt) {
    echo "✓ Fiscal receipt found:\n";
    echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
    echo "  Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
    echo "  Has QR Code: " . (!empty($fiscalReceipt['receipt_qr_code']) ? 'Yes' : 'No') . "\n";
} else {
    echo "✗ No fiscal receipt found\n";
}

