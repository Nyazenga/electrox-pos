<?php
/**
 * Direct restore - modifies SQL file and imports using mysql command
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

echo "Restoring products...\n\n";

$sqlFile = APP_PATH . '/database/products_clean.sql';

if (!file_exists($sqlFile)) {
    echo "❌ File not found: $sqlFile\n";
    exit(1);
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Ensure source column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
        $pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
        echo "✓ Source column added\n";
    }
    
    // Read SQL file
    $sql = file_get_contents($sqlFile);
    
    // Remove BOM
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") {
        $sql = substr($sql, 3);
    }
    
    // Extract INSERT statement
    if (preg_match('/INSERT\s+INTO\s+`?products`?\s*\(([^)]+)\)\s*VALUES\s*(.+);/is', $sql, $matches)) {
        $columns = $matches[1];
        $values = $matches[2];
        
        // Parse column list
        $columnList = array_map('trim', explode(',', preg_replace('/`/', '', $columns)));
        
        // Find created_by index - source should be AFTER created_by
        $createdByIndex = array_search('created_by', $columnList);
        if ($createdByIndex === false) {
            // If created_by not found, add source at the end
            $sourceIndex = count($columnList);
        } else {
            // Insert source AFTER created_by
            $sourceIndex = $createdByIndex + 1;
        }
        
        // Add source to column list
        array_splice($columnList, $sourceIndex, 0, 'source');
        
        // Parse and modify values
        // Split by ),( to get rows
        $rows = preg_split('/\)\s*,\s*\(/', trim($values, '()'));
        
        $newRows = [];
        foreach ($rows as $row) {
            $row = trim($row, '()');
            $rowValues = [];
            
            // Parse values (handle quoted strings)
            $current = '';
            $inQuotes = false;
            $quoteChar = null;
            
            for ($i = 0; $i < strlen($row); $i++) {
                $char = $row[$i];
                
                if (!$inQuotes && ($char === '"' || $char === "'")) {
                    $inQuotes = true;
                    $quoteChar = $char;
                    $current .= $char;
                } elseif ($inQuotes && $char === $quoteChar) {
                    if ($i > 0 && $row[$i-1] === '\\') {
                        $current .= $char;
                    } else {
                        $inQuotes = false;
                        $quoteChar = null;
                        $current .= $char;
                    }
                } elseif (!$inQuotes && $char === ',') {
                    $rowValues[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
            if (!empty($current)) {
                $rowValues[] = trim($current);
            }
            
            // Insert 'manual' at source position (which is AFTER created_by)
            // Count how many values we have vs how many columns (excluding source)
            $originalColumnCount = count($columnList) - 1; // -1 because we added source
            
            if (count($rowValues) < $originalColumnCount) {
                // Pad with NULLs up to created_by position, then add source
                while (count($rowValues) < $createdByIndex + 1) {
                    $rowValues[] = 'NULL';
                }
                // Now insert source after created_by
                array_splice($rowValues, $createdByIndex + 1, 0, "'manual'");
                // Pad remaining if needed
                while (count($rowValues) < count($columnList)) {
                    $rowValues[] = 'NULL';
                }
            } else {
                // We have enough values, insert 'manual' at source position
                array_splice($rowValues, $sourceIndex, 0, "'manual'");
            }
            
            $newRows[] = '(' . implode(',', $rowValues) . ')';
        }
        
        // Build new INSERT
        $newColumns = '`' . implode('`, `', $columnList) . '`';
        $newValues = implode(', ', $newRows);
        $newInsert = "INSERT INTO `products` ($newColumns) VALUES $newValues;";
        
        // Execute
        echo "Inserting products...\n";
        $pdo->beginTransaction();
        
        try {
            $pdo->exec($newInsert);
            $pdo->commit();
            echo "✓ Products inserted\n";
        } catch (PDOException $e) {
            $pdo->rollBack();
            // Try inserting row by row
            echo "Bulk insert failed, trying row by row...\n";
            $pdo->beginTransaction();
            $inserted = 0;
            $skipped = 0;
            
            foreach ($newRows as $row) {
                $rowValues = trim($row, '()');
                $valuesList = explode(',', $rowValues);
                $rowInsert = "INSERT INTO `products` ($newColumns) VALUES ($rowValues)";
                
                try {
                    $pdo->exec($rowInsert);
                    $inserted++;
                } catch (PDOException $e2) {
                    if (strpos($e2->getMessage(), 'Duplicate') === false) {
                        $skipped++;
                        if ($skipped <= 5) {
                            echo "  ⚠ " . substr($e2->getMessage(), 0, 80) . "\n";
                        }
                    }
                }
            }
            
            $pdo->commit();
            echo "✓ Inserted $inserted product(s)\n";
            if ($skipped > 0) {
                echo "  ⚠ Skipped $skipped row(s)\n";
            }
        }
        
        // Update source
        $pdo->exec("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
        
        // Verify
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
        $total = $stmt->fetch()['total'];
        echo "\n✅ Restored $total product(s)!\n";
        
    } else {
        echo "❌ Could not parse INSERT statement from SQL file\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
