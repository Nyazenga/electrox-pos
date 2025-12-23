<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Checking All Recent Sales ===\n\n";

// Check tenant database
$db = Database::getInstance();
$dbName = $db->getRow("SELECT DATABASE() as db");
echo "Connected to DB: " . ($dbName['db'] ?? 'Unknown') . "\n\n";

// Get all sales
$sales = $db->getRows("SELECT id, branch_id, total_amount, payment_status, created_at, fiscalized FROM sales ORDER BY id DESC LIMIT 20");

echo "Recent Sales (Last 20):\n";
if (empty($sales)) {
    echo "  ✗ NO SALES FOUND in database\n";
} else {
    echo "  Found " . count($sales) . " sales:\n";
    foreach ($sales as $sale) {
        $fiscalized = isset($sale['fiscalized']) ? ($sale['fiscalized'] ? 'YES' : 'NO') : 'N/A';
        echo "    Sale ID: {$sale['id']}, Branch: {$sale['branch_id']}, Total: {$sale['total_amount']}, Fiscalized: $fiscalized, Created: {$sale['created_at']}\n";
    }
}

// Check fiscal receipts
$primaryDb = Database::getPrimaryInstance();
$fiscalReceipts = $primaryDb->getRows("SELECT sale_id, receipt_global_no, device_id, created_at FROM fiscal_receipts ORDER BY id DESC LIMIT 20");

echo "\nRecent Fiscal Receipts (Last 20):\n";
if (empty($fiscalReceipts)) {
    echo "  ✗ NO FISCAL RECEIPTS FOUND\n";
} else {
    echo "  Found " . count($fiscalReceipts) . " fiscal receipts:\n";
    foreach ($fiscalReceipts as $fr) {
        echo "    Sale ID: {$fr['sale_id']}, Receipt Global No: {$fr['receipt_global_no']}, Device: {$fr['device_id']}, Created: {$fr['created_at']}\n";
    }
}

