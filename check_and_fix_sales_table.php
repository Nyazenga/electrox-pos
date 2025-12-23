<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();

echo "=== Checking Sales Table Structure ===\n\n";

// Check if fiscalized column exists
$columns = $db->getRows("SHOW COLUMNS FROM sales LIKE 'fiscalized'");
if (empty($columns)) {
    echo "✗ 'fiscalized' column does not exist\n";
    echo "Adding fiscalized column...\n";
    $db->executeQuery("ALTER TABLE sales ADD COLUMN fiscalized TINYINT(1) DEFAULT 0");
    echo "✓ Added fiscalized column\n";
} else {
    echo "✓ 'fiscalized' column exists\n";
}

// Check if fiscal_details column exists
$columns = $db->getRows("SHOW COLUMNS FROM sales LIKE 'fiscal_details'");
if (empty($columns)) {
    echo "✗ 'fiscal_details' column does not exist\n";
    echo "Adding fiscal_details column...\n";
    $db->executeQuery("ALTER TABLE sales ADD COLUMN fiscal_details TEXT DEFAULT NULL");
    echo "✓ Added fiscal_details column\n";
} else {
    echo "✓ 'fiscal_details' column exists\n";
}

// Check recent sales
$sales = $db->getRows("SELECT id, branch_id, total_amount, payment_status, created_at FROM sales ORDER BY id DESC LIMIT 5");
echo "\n=== Recent Sales ===\n";
if (empty($sales)) {
    echo "No sales found\n";
} else {
    foreach ($sales as $sale) {
        echo "Sale ID: {$sale['id']}, Branch: {$sale['branch_id']}, Total: {$sale['total_amount']}, Status: {$sale['payment_status']}\n";
    }
}

