<?php
/**
 * Import products from products.sql (INSERT statements only)
 * Uses MySQL command line for better performance
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

echo "Connected to " . PRIMARY_DB_NAME . "\n";

$sqlFile = dirname(__FILE__) . '/products.sql';
if (!file_exists($sqlFile)) {
    die("❌ SQL file not found: $sqlFile\n");
}

echo "Reading SQL file...\n";
$lines = file($sqlFile);

// Find INSERT statement (starts at line 79, ends at line 132)
$insertLines = [];
$inInsert = false;

foreach ($lines as $lineNum => $line) {
    if (strpos($line, 'INSERT INTO') !== false) {
        $inInsert = true;
    }
    
    if ($inInsert) {
        $insertLines[] = $line;
        // Check if this is the end of the INSERT (ends with ');')
        if (preg_match('/\);$/', trim($line))) {
            break;
        }
    }
}

if (empty($insertLines)) {
    die("❌ Could not find INSERT statement\n");
}

$insertStatement = implode('', $insertLines);
$insertStatement = trim($insertStatement);

echo "Extracted INSERT statement (" . count($insertLines) . " lines, " . strlen($insertStatement) . " chars)\n";

// Check current count
$count = $db->getOne("SELECT COUNT(*) FROM products");
echo "Current products count: $count\n";

if ($count > 0) {
    echo "⚠️  Truncating table...\n";
    $db->query("TRUNCATE TABLE products");
    echo "✓ Table truncated\n";
}

echo "Executing INSERT...\n";
try {
    // Split into chunks if needed, or execute directly
    $result = $db->query($insertStatement);
    
    if ($result === false) {
        throw new Exception($db->getLastError());
    }
    
    echo "✓ INSERT executed\n";
    
    $newCount = $db->getOne("SELECT COUNT(*) FROM products");
    echo "\n✅ Import complete! Total products: $newCount\n";
    
    // Show source distribution
    echo "\nSource distribution:\n";
    $sourceCounts = $db->getRows("SELECT source, COUNT(*) as count FROM products GROUP BY source");
    foreach ($sourceCounts as $row) {
        echo "  - " . ($row['source'] ?: 'NULL/Empty') . ": " . $row['count'] . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Last error: " . $db->getLastError() . "\n";
    exit(1);
}

