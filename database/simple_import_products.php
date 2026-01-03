<?php
/**
 * Simple product import - handles column mismatch by adding source column
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
    
    echo "Connected to $dbname\n\n";
    
    // Read SQL file line by line
    $sqlFile = dirname(dirname(__FILE__)) . '/products_backup.sql';
    if (!file_exists($sqlFile)) {
        die("❌ Backup file not found: $sqlFile\n");
    }
    
    echo "Reading SQL file...\n";
    $lines = file($sqlFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        die("❌ Could not read SQL file\n");
    }
    
    $insertLine = null;
    foreach ($lines as $lineNum => $line) {
        // Check if this line contains INSERT INTO products VALUES
        if (stripos($line, 'INSERT INTO') !== false && 
            stripos($line, 'products') !== false && 
            stripos($line, 'VALUES') !== false) {
            $insertLine = trim($line);
            echo "Found INSERT on line " . ($lineNum + 1) . "\n";
            break;
        }
    }
    
    if (!$insertLine) {
        die("❌ INSERT statement not found\n");
    }
    
    // Remove comment at the end if present
    if (strpos($insertLine, '/*!40000') !== false) {
        $insertLine = substr($insertLine, 0, strpos($insertLine, '/*!40000'));
        $insertLine = trim($insertLine);
    }
    
    // Extract just the VALUES part
    if (preg_match('/VALUES\s*(.+)/', $insertLine, $matches)) {
        $valuesPart = trim($matches[1]);
        // Remove trailing ); or );
        $valuesPart = rtrim($valuesPart, ');');
        $valuesPart = rtrim($valuesPart, ')');
        
        echo "Extracted VALUES part (length: " . strlen($valuesPart) . " chars)\n";
        echo "Starting import...\n\n";
        
        // Get column count
        $stmt = $pdo->query("SHOW COLUMNS FROM products");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $columnCount = count($columns);
        $sourceIndex = array_search('source', $columns);
        
        echo "Table has $columnCount columns, source is at index $sourceIndex\n\n";
        
        // Split into individual rows by ),( pattern
        // Use a simple approach: split by '),('
        $rows = preg_split('/\)\s*,\s*\(/', $valuesPart);
        
        // Clean first and last rows
        if (count($rows) > 0) {
            $rows[0] = ltrim($rows[0], '(');
            $lastIdx = count($rows) - 1;
            $rows[$lastIdx] = rtrim($rows[$lastIdx], ')');
        }
        
        echo "Found " . count($rows) . " product rows\n";
        
        $pdo->beginTransaction();
        
        try {
            $inserted = 0;
            $skipped = 0;
            $columnList = '`' . implode('`, `', $columns) . '`';
            
            foreach ($rows as $idx => $row) {
                // Parse values - simple CSV-like parsing
                $values = [];
                $current = '';
                $inQuotes = false;
                $quoteChar = null;
                
                for ($i = 0; $i < strlen($row); $i++) {
                    $char = $row[$i];
                    $prevChar = $i > 0 ? $row[$i-1] : null;
                    
                    if (!$inQuotes && ($char === '"' || $char === "'")) {
                        $inQuotes = true;
                        $quoteChar = $char;
                        $current .= $char;
                    } elseif ($inQuotes && $char === $quoteChar && $prevChar !== '\\') {
                        $inQuotes = false;
                        $quoteChar = null;
                        $current .= $char;
                    } elseif (!$inQuotes && $char === ',') {
                        $values[] = trim($current);
                        $current = '';
                    } else {
                        $current .= $char;
                    }
                }
                if (!empty($current)) {
                    $values[] = trim($current);
                }
                
                // Add source column if missing
                $valueCount = count($values);
                if ($valueCount < $columnCount) {
                    // Insert 'manual' at source position
                    if ($sourceIndex !== false) {
                        array_splice($values, $sourceIndex, 0, "'manual'");
                    } else {
                        $values[] = "'manual'";
                    }
                } elseif ($valueCount == $columnCount && isset($values[$sourceIndex])) {
                    // Replace NULL or empty with 'manual'
                    if ($values[$sourceIndex] === 'NULL' || trim($values[$sourceIndex], "'\"") === '') {
                        $values[$sourceIndex] = "'manual'";
                    }
                }
                
                // Build INSERT
                $valuesStr = '(' . implode(',', $values) . ')';
                $sql = "INSERT INTO `products` ($columnList) VALUES $valuesStr";
                
                try {
                    $pdo->exec($sql);
                    $inserted++;
                    if ($inserted % 10 == 0) {
                        echo "Inserted $inserted products...\n";
                    }
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $skipped++;
                    } else {
                        echo "Error on row " . ($idx + 1) . ": " . substr($e->getMessage(), 0, 100) . "\n";
                    }
                }
            }
            
            // Update any remaining NULL sources
            $updated = $pdo->exec("UPDATE products SET source = 'manual' WHERE source IS NULL OR source = ''");
            
            $pdo->commit();
            
            echo "\n✅ Import completed!\n";
            echo "Inserted: $inserted products\n";
            echo "Skipped: $skipped products\n";
            echo "Updated source: $updated products\n";
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
            $count = $stmt->fetch();
            echo "Total products: " . $count['total'] . "\n";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } else {
        die("❌ Could not extract VALUES from INSERT\n");
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

