<?php
/**
 * Simple product restore - reads line 23 from backup and imports with source column
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;
$dbname = 'electrox_primary';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    echo "✅ Connected to $dbname\n\n";
    
    // Get columns
    $stmt = $pdo->query("SHOW COLUMNS FROM products");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $columnCount = count($columns);
    $sourceIndex = array_search('source', $columns);
    
    echo "Table has $columnCount columns, source at index: " . ($sourceIndex !== false ? $sourceIndex : 'NOT FOUND') . "\n\n";
    
    // Read line 23 from backup
    $sqlFile = dirname(dirname(__FILE__)) . '/products_backup.sql';
    if (!file_exists($sqlFile)) {
        die("❌ Backup file not found\n");
    }
    
    $lines = file($sqlFile);
    if (!isset($lines[22])) {
        die("❌ Line 23 not found in backup file\n");
    }
    
    $insertLine = trim($lines[22]);
    $insertLine = preg_replace('/\/\*!40000.*/', '', $insertLine);
    $insertLine = trim($insertLine);
    
    echo "✅ Found INSERT statement\n";
    echo "Length: " . strlen($insertLine) . " chars\n\n";
    
    // Extract VALUES part - handle case-insensitive
    if (!preg_match('/VALUES\s*(.+)/i', $insertLine, $m)) {
        die("❌ Could not extract VALUES from: " . substr($insertLine, 0, 100) . "...\n");
    }
    
    $valuesStr = trim($m[1]);
    $valuesStr = rtrim($valuesStr, ');');
    $valuesStr = rtrim($valuesStr, ')');
    
    // Split into rows
    $rows = preg_split('/\)\s*,\s*\(/', $valuesStr);
    $rows[0] = ltrim($rows[0], '(');
    $rows[count($rows)-1] = rtrim($rows[count($rows)-1], ')');
    
    echo "Found " . count($rows) . " product rows\n";
    echo "Starting import...\n\n";
    
    $pdo->beginTransaction();
    $inserted = 0;
    $skipped = 0;
    $columnList = '`' . implode('`, `', $columns) . '`';
    
    foreach ($rows as $idx => $row) {
        // Simple value parsing
        $values = [];
        $current = '';
        $inQuotes = false;
        $quote = null;
        
        for ($i = 0; $i < strlen($row); $i++) {
            $c = $row[$i];
            $prev = $i > 0 ? $row[$i-1] : null;
            
            if (!$inQuotes && ($c === '"' || $c === "'")) {
                $inQuotes = true;
                $quote = $c;
                $current .= $c;
            } elseif ($inQuotes && $c === $quote && $prev !== '\\') {
                $inQuotes = false;
                $quote = null;
                $current .= $c;
            } elseif (!$inQuotes && $c === ',') {
                $values[] = trim($current);
                $current = '';
            } else {
                $current .= $c;
            }
        }
        if ($current) $values[] = trim($current);
        
        // Add 'manual' for source if missing
        $valueCount = count($values);
        if ($valueCount < $columnCount) {
            // Insert 'manual' at source position
            if ($sourceIndex !== false && $valueCount <= $sourceIndex) {
                array_splice($values, $sourceIndex, 0, "'manual'");
            } else {
                // Pad and add at end
                while (count($values) < $columnCount - 1) {
                    $values[] = 'NULL';
                }
                $values[] = "'manual'";
            }
        } elseif ($valueCount == $columnCount - 1) {
            // Missing exactly one column (source)
            $values[] = "'manual'";
        } else {
            // Has all columns, ensure source is set
            if (isset($values[$sourceIndex]) && ($values[$sourceIndex] === 'NULL' || trim($values[$sourceIndex], "'\"") === '')) {
                $values[$sourceIndex] = "'manual'";
            }
        }
        
        $sql = "INSERT INTO `products` ($columnList) VALUES (" . implode(',', $values) . ")";
        
        try {
            $pdo->exec($sql);
            $inserted++;
            if ($inserted % 10 == 0) echo "Inserted $inserted...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                $skipped++;
            } else {
                echo "Error row " . ($idx + 1) . ": " . substr($e->getMessage(), 0, 80) . "\n";
            }
        }
    }
    
    $pdo->exec("UPDATE products SET source = 'manual' WHERE source IS NULL OR source = ''");
    $pdo->commit();
    
    echo "\n✅ Done! Inserted: $inserted, Skipped: $skipped\n";
    $stmt = $pdo->query("SELECT COUNT(*) as c FROM products");
    echo "Total products: " . $stmt->fetch()['c'] . "\n";
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

