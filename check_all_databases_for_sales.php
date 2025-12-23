<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Checking All Databases for Sales ===\n\n";

// Check tenant database
$db = Database::getInstance();
$sales = $db->getRows("SELECT id, branch_id, total_amount, payment_status, created_at FROM sales ORDER BY id DESC LIMIT 5");

echo "Tenant Database Sales:\n";
if (empty($sales)) {
    echo "  No sales found\n";
} else {
    echo "  Found " . count($sales) . " sales\n";
    foreach ($sales as $sale) {
        echo "    Sale ID: {$sale['id']}, Branch: {$sale['branch_id']}, Total: {$sale['total_amount']}, Created: {$sale['created_at']}\n";
    }
}

// Check primary database (shouldn't have sales, but check fiscal receipts)
$primaryDb = Database::getPrimaryInstance();
$fiscalReceipts = $primaryDb->getRows("SELECT * FROM fiscal_receipts ORDER BY id DESC LIMIT 5");

echo "\nPrimary Database Fiscal Receipts:\n";
if (empty($fiscalReceipts)) {
    echo "  No fiscal receipts found\n";
} else {
    echo "  Found " . count($fiscalReceipts) . " fiscal receipts\n";
    foreach ($fiscalReceipts as $fr) {
        echo "    Sale ID: {$fr['sale_id']}, Receipt Global No: {$fr['receipt_global_no']}, Device: {$fr['device_id']}\n";
    }
}

// Check what database we're connected to
echo "\nDatabase Info:\n";
$dbName = $db->getRow("SELECT DATABASE() as db");
echo "  Tenant DB: " . ($dbName['db'] ?? 'Unknown') . "\n";

$primaryDbName = $primaryDb->getRow("SELECT DATABASE() as db");
echo "  Primary DB: " . ($primaryDbName['db'] ?? 'Unknown') . "\n";

