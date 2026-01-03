<?php
/**
 * Import products from products.sql (INSERT statements only)
 * This script extracts and executes only the INSERT statement
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance(); // Connects to electrox_primary

echo "Connected to " . PRIMARY_DB_NAME . "\n";

$sqlFile = dirname(__FILE__) . '/products.sql';
if (!file_exists($sqlFile)) {
    die("❌ SQL file not found: $sqlFile\n");
}

echo "Reading SQL file...\n";
$content = file_get_contents($sqlFile);

// Extract only the INSERT statement (skip CREATE TABLE)
$insertStart = strpos($content, 'INSERT INTO');
if ($insertStart === false) {
    die("❌ Could not find INSERT statement in SQL file\n");
}

$insertEnd = strpos($content, ';', $insertStart);
if ($insertEnd === false) {
    die("❌ Could not find end of INSERT statement\n");
}

$insertStatement = substr($content, $insertStart, $insertEnd - $insertStart + 1);
$insertStatement = trim($insertStatement);

echo "Extracted INSERT statement (length: " . strlen($insertStatement) . " chars)\n";
echo "First 100 chars: " . substr($insertStatement, 0, 100) . "...\n\n";

// Check if products table is empty
$count = $db->getOne("SELECT COUNT(*) FROM products");
echo "Current products count: $count\n";

if ($count > 0) {
    echo "⚠️  Products table is not empty. Truncating...\n";
    $db->query("TRUNCATE TABLE products");
    echo "✓ Table truncated\n";
}

echo "Executing INSERT statement...\n";
try {
    $db->query($insertStatement);
    echo "✓ Products imported successfully\n";
    
    $newCount = $db->getOne("SELECT COUNT(*) FROM products");
    echo "\n✅ Import complete! Total products: $newCount\n";
    
    // Show source distribution
    echo "\nSource distribution:\n";
    $sourceCounts = $db->getRows("SELECT source, COUNT(*) as count FROM products GROUP BY source");
    foreach ($sourceCounts as $row) {
        echo "  - " . ($row['source'] ?: 'NULL/Empty') . ": " . $row['count'] . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error importing products: " . $e->getMessage() . "\n";
    exit(1);
}

