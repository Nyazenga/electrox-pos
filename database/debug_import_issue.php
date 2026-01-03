<?php
/**
 * Debug script to compare table structure with SQL file
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== Database Table Columns ===\n";
$stmt = $pdo->query("SHOW COLUMNS FROM products");
$dbColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Count: " . count($dbColumns) . "\n";
echo "Columns: " . implode(", ", $dbColumns) . "\n\n";

echo "=== SQL File Columns ===\n";
$sqlFile = __DIR__ . '/products.sql';
$lines = file($sqlFile);
$insertLine = trim($lines[78]);
if (preg_match('/INSERT INTO `products` \((.+?)\) VALUES/i', $insertLine, $matches)) {
    $columnList = $matches[1];
    preg_match_all('/`([^`]+)`/', $columnList, $colMatches);
    $sqlColumns = $colMatches[1];
    echo "Count: " . count($sqlColumns) . "\n";
    echo "Columns: " . implode(", ", $sqlColumns) . "\n\n";
    
    echo "=== Comparison ===\n";
    if (count($dbColumns) !== count($sqlColumns)) {
        echo "❌ Column count mismatch: DB=" . count($dbColumns) . ", SQL=" . count($sqlColumns) . "\n";
    } else {
        echo "✓ Column counts match\n";
    }
    
    $diff = array_diff($dbColumns, $sqlColumns);
    if (!empty($diff)) {
        echo "❌ Columns in DB but not in SQL: " . implode(", ", $diff) . "\n";
    }
    
    $diff2 = array_diff($sqlColumns, $dbColumns);
    if (!empty($diff2)) {
        echo "❌ Columns in SQL but not in DB: " . implode(", ", $diff2) . "\n";
    }
    
    if (empty($diff) && empty($diff2) && count($dbColumns) === count($sqlColumns)) {
        echo "✓ Column lists match!\n";
        
        // Check first row value count
        echo "\n=== First Row Value Count ===\n";
        $firstRow = trim($lines[79]);
        $inQuotes = false;
        $escapeNext = false;
        $commaCount = 0;
        for ($i = 0; $i < strlen($firstRow); $i++) {
            $char = $firstRow[$i];
            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }
            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }
            if ($char === "'" && !$escapeNext) {
                $inQuotes = !$inQuotes;
            } elseif ($char === ',' && !$inQuotes) {
                $commaCount++;
            }
        }
        $valueCount = $commaCount + 1;
        echo "First row has $valueCount values\n";
        echo "Expected " . count($dbColumns) . " values\n";
        if ($valueCount === count($dbColumns)) {
            echo "✓ Value count matches!\n";
        } else {
            echo "❌ Value count mismatch!\n";
        }
    }
}

