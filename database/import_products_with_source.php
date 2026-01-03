<?php
/**
 * Import products from backup and set source = 'manual'
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
    
    echo "Connected to $dbname\n";
    
    // Read SQL file
    $sqlFile = dirname(dirname(__FILE__)) . '/products_backup.sql';
    if (!file_exists($sqlFile)) {
        die("Backup file not found: $sqlFile\n");
    }
    
    echo "Reading SQL file...\n";
    $sql = file_get_contents($sqlFile);
    
    // Extract INSERT statement
    if (preg_match('/INSERT INTO `products` VALUES\s*(.*?);/s', $sql, $matches)) {
        $valuesString = $matches[1];
        
        // Split by ),( to get individual rows
        $rows = preg_split('/\),\s*\(/', $valuesString);
        
        // Clean up first and last rows
        $rows[0] = ltrim($rows[0], '(');
        $lastIndex = count($rows) - 1;
        $rows[$lastIndex] = rtrim($rows[$lastIndex], ')');
        
        echo "Found " . count($rows) . " product rows\n";
        echo "Starting import...\n\n";
        
        $pdo->beginTransaction();
        
        try {
            $inserted = 0;
            $skipped = 0;
            
            // Get column count to determine where to insert source
            $stmt = $pdo->query("SHOW COLUMNS FROM products");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $columnCount = count($columns);
            $sourceIndex = array_search('source', $columns);
            
            echo "Table has $columnCount columns, source is at index $sourceIndex\n";
            
            foreach ($rows as $row) {
                // Parse the row values
                $values = [];
                $current = '';
                $inQuotes = false;
                $quoteChar = null;
                
                // Remove leading/trailing parentheses if any
                $row = trim($row, '()');
                
                // Simple CSV-like parsing
                $parts = [];
                $current = '';
                $inQuotes = false;
                $quote = null;
                
                for ($i = 0; $i < strlen($row); $i++) {
                    $char = $row[$i];
                    
                    if (!$inQuotes && ($char === '"' || $char === "'")) {
                        $inQuotes = true;
                        $quote = $char;
                        $current .= $char;
                    } elseif ($inQuotes && $char === $quote && ($i == 0 || $row[$i-1] !== '\\')) {
                        $inQuotes = false;
                        $quote = null;
                        $current .= $char;
                    } elseif (!$inQuotes && $char === ',' && ($i == 0 || $row[$i-1] !== '\\')) {
                        $parts[] = trim($current);
                        $current = '';
                    } else {
                        $current .= $char;
                    }
                }
                if (!empty($current)) {
                    $parts[] = trim($current);
                }
                
                // If source column doesn't exist in the row, add it
                // The source column should be after created_by
                // Let's count the current parts and see if we need to add source
                $currentPartCount = count($parts);
                
                // The backup has a certain number of columns, but the table now has source
                // We need to insert 'manual' at the right position
                // Based on the table structure, source comes after created_by
                // Let's just append 'manual' if the count is less than expected
                if ($currentPartCount < $columnCount) {
                    // Find where to insert source (after created_by which is typically near the end)
                    // For simplicity, let's just append 'manual' at the end before the last few columns
                    // Actually, let's check the actual column order
                    // The safest approach: if we're missing the source column, add it
                    // Source should be after created_by
                    // Let's insert 'manual' at position $sourceIndex
                    $newParts = [];
                    for ($i = 0; $i < $columnCount; $i++) {
                        if ($i == $sourceIndex) {
                            $newParts[] = "'manual'";
                        } elseif ($i < $currentPartCount) {
                            $newParts[] = $parts[$i];
                        } else {
                            $newParts[] = 'NULL';
                        }
                    }
                    $parts = $newParts;
                } else {
                    // Source already exists, just update it if it's NULL
                    if (isset($parts[$sourceIndex]) && ($parts[$sourceIndex] === 'NULL' || $parts[$sourceIndex] === "''" || empty(trim($parts[$sourceIndex], "'\"")))) {
                        $parts[$sourceIndex] = "'manual'";
                    }
                }
                
                // Build INSERT statement
                $valuesStr = '(' . implode(',', $parts) . ')';
                $insertSql = "INSERT INTO `products` VALUES $valuesStr";
                
                try {
                    $pdo->exec($insertSql);
                    $inserted++;
                    
                    if ($inserted % 10 == 0) {
                        echo "Inserted $inserted products...\n";
                    }
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $skipped++;
                    } else {
                        echo "Error inserting row: " . $e->getMessage() . "\n";
                        // Continue
                    }
                }
            }
            
            // Update any NULL source values
            echo "\nUpdating source values...\n";
            $updated = $pdo->exec("UPDATE products SET source = 'manual' WHERE source IS NULL OR source = ''");
            echo "Updated $updated products\n";
            
            $pdo->commit();
            
            echo "\n✅ Import completed!\n";
            echo "Inserted: $inserted products\n";
            echo "Skipped (duplicates): $skipped products\n";
            
            // Verify
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
            $count = $stmt->fetch();
            echo "Total products in database: " . $count['total'] . "\n";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } else {
        die("No INSERT statement found in backup file.\n");
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

