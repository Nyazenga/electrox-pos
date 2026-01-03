<?php
require_once dirname(__FILE__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/session.php';

initSession();

$db = Database::getInstance();

// Check if source column exists
echo "Checking source column...\n";
$columns = $db->getRows("SHOW COLUMNS FROM products WHERE Field = 'source'");
if (empty($columns)) {
    echo "❌ Source column does NOT exist!\n";
    exit(1);
} else {
    echo "✓ Source column exists\n";
    print_r($columns[0]);
}

// Check source values
echo "\nChecking source values...\n";
$sourceValues = $db->getRows("SELECT source, COUNT(*) as count FROM products GROUP BY source");
echo "Source value distribution:\n";
foreach ($sourceValues as $row) {
    $source = $row['source'] ?? 'NULL';
    echo "  $source: " . $row['count'] . " products\n";
}

// Test filter query
echo "\nTesting filter query with source='manual'...\n";
$params = [':source' => 'manual'];
$products = $db->getRows("SELECT p.* FROM products p WHERE p.source = :source LIMIT 5", $params);
echo "Found " . count($products) . " products with source='manual'\n";

// Test with NULL
echo "\nTesting with NULL source values...\n";
$nullProducts = $db->getRows("SELECT COUNT(*) as count FROM products WHERE source IS NULL");
echo "Products with NULL source: " . ($nullProducts[0]['count'] ?? 0) . "\n";

