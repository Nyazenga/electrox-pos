<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Checking Latest Sale and Fiscalization ===\n\n";

// Get latest sale
$db = Database::getInstance();
$sale = $db->getRow("SELECT * FROM sales ORDER BY id DESC LIMIT 1");

if (!$sale) {
    die("No sales found\n");
}

echo "Latest Sale:\n";
echo "  Sale ID: {$sale['id']}\n";
echo "  Branch ID: {$sale['branch_id']}\n";
echo "  Total: {$sale['total_amount']}\n";
echo "  Payment Status: {$sale['payment_status']}\n";
echo "  Created: {$sale['created_at']}\n";
echo "  Fiscalized: " . (isset($sale['fiscalized']) ? $sale['fiscalized'] : 'N/A') . "\n";
echo "  Fiscal Details: " . (isset($sale['fiscal_details']) && !empty($sale['fiscal_details']) ? 'Yes' : 'No') . "\n\n";

// Check fiscal receipt
$primaryDb = Database::getPrimaryInstance();
$fiscalReceipt = $primaryDb->getRow(
    "SELECT * FROM fiscal_receipts WHERE sale_id = :sale_id",
    [':sale_id' => $sale['id']]
);

if ($fiscalReceipt) {
    echo "✓ Fiscal Receipt Found:\n";
    echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
    echo "  Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
    echo "  Device ID: {$fiscalReceipt['device_id']}\n";
    echo "  Has QR Code: " . (!empty($fiscalReceipt['receipt_qr_code']) ? 'Yes (' . strlen($fiscalReceipt['receipt_qr_code']) . ' bytes)' : 'No') . "\n";
    echo "  Has QR Data: " . (!empty($fiscalReceipt['receipt_qr_data']) ? 'Yes' : 'No') . "\n";
    echo "  Created: {$fiscalReceipt['created_at']}\n";
} else {
    echo "✗ No Fiscal Receipt Found\n";
}

// Check all fiscal receipts
$allReceipts = $primaryDb->getRows("SELECT * FROM fiscal_receipts ORDER BY id DESC LIMIT 5");
echo "\n=== All Fiscal Receipts (Last 5) ===\n";
if (empty($allReceipts)) {
    echo "No fiscal receipts found\n";
} else {
    foreach ($allReceipts as $fr) {
        echo "Sale ID: {$fr['sale_id']}, Receipt Global No: {$fr['receipt_global_no']}, Device: {$fr['device_id']}\n";
    }
}

