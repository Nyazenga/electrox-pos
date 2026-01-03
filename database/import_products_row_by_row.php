<?php
/**
 * Import products row by row to avoid large INSERT statement issues
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

// Get INSERT line to extract column names
$insertLine = trim($lines[78]);
preg_match_all('/`([^`]+)`/', $insertLine, $colMatches);
$columns = array_slice($colMatches[1], 1); // Skip 'products' table name
$columnList = '`' . implode('`, `', $columns) . '`';

echo "Columns: " . count($columns) . "\n";

// Truncate table
$count = $db->getOne("SELECT COUNT(*) FROM products");
echo "Current products count: $count\n";

if ($count > 0) {
    echo "⚠️  Truncating table...\n";
    $db->query("TRUNCATE TABLE products");
    echo "✓ Table truncated\n";
}

// Parse and insert rows one by one (lines 80-132, indices 79-131)
$inserted = 0;
$errors = 0;

for ($i = 79; $i <= 131; $i++) {
    $line = trim($lines[$i]);
    if (empty($line) || !preg_match('/^\(/', $line)) {
        continue;
    }
    
    // Remove trailing comma or semicolon
    $line = rtrim($line, ',;');
    
    // Build INSERT statement
    $sql = "INSERT INTO `products` ($columnList) VALUES $line";
    
    try {
        $result = $db->query($sql);
        if ($result === false) {
            throw new Exception($db->getLastError());
        }
        $inserted++;
        if ($inserted % 10 == 0) {
            echo "  Inserted $inserted rows...\n";
        }
    } catch (Exception $e) {
        $errors++;
        $rowNum = $i - 78;
        echo "❌ Error on row $rowNum: " . $e->getMessage() . "\n";
        echo "  SQL: " . substr($sql, 0, 100) . "...\n";
    }
}

echo "\n✅ Import complete!\n";
echo "  Inserted: $inserted\n";
echo "  Errors: $errors\n";

$newCount = $db->getOne("SELECT COUNT(*) FROM products");
echo "\nTotal products in database: $newCount\n";

// Show source distribution
echo "\nSource distribution:\n";
$sourceCounts = $db->getRows("SELECT source, COUNT(*) as count FROM products GROUP BY source");
foreach ($sourceCounts as $row) {
    echo "  - " . ($row['source'] ?: 'NULL/Empty') . ": " . $row['count'] . "\n";
}

