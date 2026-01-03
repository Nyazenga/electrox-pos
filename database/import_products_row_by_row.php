<?php
/**
 * Import products row by row to avoid large INSERT statement issues
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

// Use PDO directly for better error handling
$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Connected to " . PRIMARY_DB_NAME . "\n";

$sqlFile = dirname(__FILE__) . '/products.sql';
if (!file_exists($sqlFile)) {
    die("❌ SQL file not found: $sqlFile\n");
}

echo "Reading SQL file...\n";
$lines = file($sqlFile);

// Get INSERT line to extract column names
$insertLine = trim($lines[78]);
// Extract column list directly from the INSERT statement
if (preg_match('/INSERT INTO `products` \((.+?)\) VALUES/i', $insertLine, $matches)) {
    $columnList = $matches[1]; // Use the column list as-is from the SQL file
    // Count columns
    preg_match_all('/`([^`]+)`/', $columnList, $colMatches);
    $columns = $colMatches[1];
} else {
    // Fallback
    preg_match_all('/`([^`]+)`/', $insertLine, $colMatches);
    $columns = array_slice($colMatches[1], 1); // Skip 'products' table name
    $columnList = '`' . implode('`, `', $columns) . '`';
}

echo "Columns: " . count($columns) . "\n";

// Truncate table
$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$count = $stmt->fetchColumn();
echo "Current products count: $count\n";

if ($count > 0) {
    echo "⚠️  Truncating table...\n";
    $pdo->exec("TRUNCATE TABLE products");
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
    
    // Debug first row
    if ($i == 79) {
        // Count values in the row (same logic as verify_server_row.php)
        $inQuotes = false;
        $commaCount = 0;
        for ($j = 0; $j < strlen($line); $j++) {
            $char = $line[$j];
            if ($char === "'" && ($j === 0 || $line[$j-1] !== '\\')) {
                $inQuotes = !$inQuotes;
            } elseif ($char === ',' && !$inQuotes) {
                $commaCount++;
            }
        }
        $valueCount = $commaCount + 1;
        echo "DEBUG Row 1: Column count: " . count($columns) . ", Value count: $valueCount\n";
        echo "DEBUG Row line (first 200): " . substr($line, 0, 200) . "...\n";
        echo "DEBUG Row line (last 100): ..." . substr($line, -100) . "\n";
    }
    
    try {
        $pdo->exec($sql);
        $inserted++;
        if ($inserted % 10 == 0) {
            echo "  Inserted $inserted rows...\n";
        }
    } catch (PDOException $e) {
        $errors++;
        $rowNum = $i - 78;
        echo "❌ Error on row $rowNum: " . $e->getMessage() . "\n";
        echo "  SQL: " . substr($sql, 0, 300) . "...\n";
        break; // Stop on first error to see what's wrong
    }
}

echo "\n✅ Import complete!\n";
echo "  Inserted: $inserted\n";
echo "  Errors: $errors\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$newCount = $stmt->fetchColumn();
echo "\nTotal products in database: $newCount\n";

// Show source distribution
echo "\nSource distribution:\n";
$stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
$sourceCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($sourceCounts as $row) {
    echo "  - " . ($row['source'] ?: 'NULL/Empty') . ": " . $row['count'] . "\n";
}

