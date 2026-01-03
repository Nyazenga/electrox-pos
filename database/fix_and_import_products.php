<?php
/**
 * Import products from backup, handling column mismatch for source column
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
    
    // Read SQL file
    $sqlFile = dirname(dirname(__FILE__)) . '/products_backup.sql';
    if (!file_exists($sqlFile)) {
        die("❌ Backup file not found: $sqlFile\n");
    }
    
    echo "Reading SQL file...\n";
    $sql = file_get_contents($sqlFile);
    
    // Extract the INSERT statement - it's on a single very long line ending with ); followed by /*!40000
    $insertLine = null;
    
    // The INSERT statement ends with ); followed by /*!40000
    if (preg_match('/INSERT INTO `products` VALUES\s*(.*?)\)\s*;?\s*\/\*!40000/s', $sql, $matches)) {
        $valuesPart = trim($matches[1]);
        // Remove any trailing ); if present
        $valuesPart = rtrim($valuesPart, ');');
        $valuesPart = rtrim($valuesPart, ')');
        $insertLine = 'INSERT INTO `products` VALUES (' . $valuesPart . ');';
    } elseif (preg_match('/INSERT INTO `products` VALUES\s*(.*?)\)\s*;/s', $sql, $matches)) {
        // Found INSERT with VALUES ending with );
        $insertLine = 'INSERT INTO `products` VALUES (' . trim($matches[1]) . ');';
    } else {
        // Try line by line - the INSERT is on line 23
        $lines = explode("\n", $sql);
        foreach ($lines as $lineNum => $line) {
            if (strpos($line, 'INSERT INTO `products` VALUES') !== false) {
                $insertLine = $line;
                // Remove comment if present
                if (strpos($insertLine, '/*!40000') !== false) {
                    $insertLine = substr($insertLine, 0, strpos($insertLine, '/*!40000'));
                    $insertLine = rtrim($insertLine);
                    // Ensure it ends with );
                    if (substr($insertLine, -2) !== ');') {
                        if (substr($insertLine, -1) === ')') {
                            $insertLine .= ';';
                        } else {
                            $insertLine .= ');';
                        }
                    }
                }
                break;
            }
        }
    }
    
    if (!$insertLine) {
        die("❌ No INSERT statement found in backup file.\n");
    }
    
    echo "Extracted INSERT statement (length: " . strlen($insertLine) . " chars)\n";
    
    echo "Found INSERT statement\n";
    
    // Get current table structure to know column count
    $stmt = $pdo->query("SHOW COLUMNS FROM products");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $columnCount = count($columns);
    $sourceIndex = array_search('source', $columns);
    
    echo "Table has $columnCount columns\n";
    echo "Source column is at index: " . ($sourceIndex !== false ? $sourceIndex : 'NOT FOUND') . "\n\n";
    
    // Extract the VALUES part
    if (preg_match('/INSERT INTO `products` VALUES\s*(.*?)\s*\/\*!40000/', $insertLine, $matches)) {
        $valuesString = trim($matches[1]);
        
        // Remove trailing ); if present
        $valuesString = rtrim($valuesString, ');');
        $valuesString = rtrim($valuesString, ')');
        
        // Split into individual row values by ),( pattern
        // But we need to be careful with nested parentheses in values
        $rows = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = null;
        
        for ($i = 0; $i < strlen($valuesString); $i++) {
            $char = $valuesString[$i];
            $nextChar = $i < strlen($valuesString) - 1 ? $valuesString[$i + 1] : null;
            
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
            } elseif ($inString && $char === $stringChar && ($i == 0 || $valuesString[$i-1] !== '\\')) {
                $inString = false;
                $stringChar = null;
                $current .= $char;
            } elseif (!$inString && $char === '(') {
                $depth++;
                if ($depth > 1) {
                    $current .= $char;
                }
            } elseif (!$inString && $char === ')') {
                $depth--;
                if ($depth == 0) {
                    // End of a row
                    $rows[] = $current;
                    $current = '';
                    // Skip comma and space if present
                    if ($nextChar === ',' || $nextChar === ' ') {
                        $i++;
                        if ($nextChar === ',' && $valuesString[$i + 1] === ' ') {
                            $i++;
                        }
                    }
                } else {
                    $current .= $char;
                }
            } else {
                $current .= $char;
            }
        }
        
        if (!empty($current)) {
            $rows[] = $current;
        }
        
        echo "Found " . count($rows) . " product rows\n";
        echo "Starting import...\n\n";
        
        $pdo->beginTransaction();
        
        try {
            $inserted = 0;
            $skipped = 0;
            $errors = 0;
            
            // Get column names for INSERT with column list
            $columnList = '`' . implode('`, `', $columns) . '`';
            
            foreach ($rows as $rowIndex => $row) {
                // Clean up the row
                $row = trim($row);
                if (empty($row)) continue;
                
                // Remove leading ( if present
                $row = ltrim($row, '(');
                
                // Parse the row to get individual values
                $values = [];
                $current = '';
                $depth = 0;
                $inString = false;
                $stringChar = null;
                
                for ($i = 0; $i < strlen($row); $i++) {
                    $char = $row[$i];
                    $prevChar = $i > 0 ? $row[$i-1] : null;
                    
                    if (!$inString && ($char === '"' || $char === "'")) {
                        $inString = true;
                        $stringChar = $char;
                        $current .= $char;
                    } elseif ($inString && $char === $stringChar && $prevChar !== '\\') {
                        $inString = false;
                        $stringChar = null;
                        $current .= $char;
                    } elseif (!$inString && $char === ',' && $depth == 0) {
                        $values[] = trim($current);
                        $current = '';
                    } else {
                        $current .= $char;
                    }
                }
                if (!empty($current)) {
                    $values[] = trim($current);
                }
                
                // Count values in the row
                $valueCount = count($values);
                
                // If we have fewer values than columns, we need to add NULL or default values
                // The source column should be added as 'manual'
                if ($valueCount < $columnCount) {
                    // Insert 'manual' at the source column position
                    // We need to know where created_by is to insert source after it
                    $createdByIndex = array_search('created_by', $columns);
                    if ($createdByIndex !== false && $sourceIndex !== false) {
                        // Insert 'manual' at the source position
                        array_splice($values, $sourceIndex, 0, "'manual'");
                    } else {
                        // Fallback: append 'manual' if we're missing exactly one column
                        if ($valueCount == $columnCount - 1) {
                            $values[] = "'manual'";
                        } else {
                            // Pad with NULL for missing columns
                            while (count($values) < $columnCount) {
                                if (count($values) == $sourceIndex) {
                                    $values[] = "'manual'";
                                } else {
                                    $values[] = 'NULL';
                                }
                            }
                        }
                    }
                } elseif ($valueCount == $columnCount) {
                    // Same number of columns - check if source is NULL and replace it
                    if (isset($values[$sourceIndex]) && ($values[$sourceIndex] === 'NULL' || trim($values[$sourceIndex], "'\"") === '')) {
                        $values[$sourceIndex] = "'manual'";
                    }
                }
                
                // Build INSERT with column names to be safe
                $valuesStr = '(' . implode(',', $values) . ')';
                $insertSql = "INSERT INTO `products` ($columnList) VALUES $valuesStr";
                
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
                        $errors++;
                        if ($errors <= 5) {
                            echo "Error on row " . ($rowIndex + 1) . ": " . $e->getMessage() . "\n";
                        }
                    }
                }
            }
            
            // Update any NULL or empty source values to 'manual'
            echo "\nUpdating source values...\n";
            $updated = $pdo->exec("UPDATE products SET source = 'manual' WHERE source IS NULL OR source = ''");
            echo "Updated $updated products to have source = 'manual'\n";
            
            $pdo->commit();
            
            echo "\n✅ Import completed!\n";
            echo "Inserted: $inserted products\n";
            echo "Skipped (duplicates): $skipped products\n";
            if ($errors > 0) {
                echo "Errors: $errors products\n";
            }
            
            // Verify
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
            $count = $stmt->fetch();
            echo "Total products in database: " . $count['total'] . "\n";
            
            $stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
            $results = $stmt->fetchAll();
            echo "\nSource distribution:\n";
            foreach ($results as $row) {
                $source = $row['source'] ?? 'NULL';
                echo "  $source: " . $row['count'] . " products\n";
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } else {
        die("❌ Could not extract VALUES from INSERT statement.\n");
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

