<?php
/**
 * Restore products from backup SQL file, ensuring source column is included
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

echo "Restoring products from backup...\n\n";

$sqlFile = APP_PATH . '/database/products_clean.sql';

if (!file_exists($sqlFile)) {
    echo "❌ Backup file not found: $sqlFile\n";
    exit(1);
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    echo "Connected to database: " . PRIMARY_DB_NAME . "\n";
    
    // Ensure source column exists
    echo "Checking source column...\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
    $columnExists = $stmt->fetch() !== false;
    
    if (!$columnExists) {
        echo "Adding source column...\n";
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
        $pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
        echo "✓ Source column added\n";
    } else {
        echo "✓ Source column exists\n";
    }
    
    // Read SQL file
    echo "\nReading backup file...\n";
    $sqlContent = file_get_contents($sqlFile);
    
    if (empty($sqlContent)) {
        echo "❌ SQL file is empty\n";
        exit(1);
    }
    
    // Remove BOM if present
    if (substr($sqlContent, 0, 3) === "\xEF\xBB\xBF") {
        $sqlContent = substr($sqlContent, 3);
    }
    
    // Check if file contains CREATE TABLE - if so, we need to extract only INSERT statements
    if (stripos($sqlContent, 'CREATE TABLE') !== false) {
        echo "File contains CREATE TABLE - extracting INSERT statements only...\n";
        
        // Extract INSERT statements
        preg_match_all('/INSERT\s+INTO\s+[^`]*`?products`?[^`]*\s+VALUES\s*\([^;]+\)/is', $sqlContent, $matches);
        
        if (empty($matches[0])) {
            echo "❌ No INSERT statements found in SQL file\n";
            exit(1);
        }
        
        echo "Found " . count($matches[0]) . " INSERT statement(s)\n";
        
        // Get current column structure
        $stmt = $pdo->query("SHOW COLUMNS FROM products");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $columnCount = count($columns);
        $sourceIndex = array_search('source', $columns);
        
        echo "Table has $columnCount columns\n";
        echo "Source column is at index: " . ($sourceIndex !== false ? $sourceIndex : 'NOT FOUND') . "\n";
        
        // Process each INSERT statement
        $pdo->beginTransaction();
        
        try {
            $inserted = 0;
            $skipped = 0;
            
            foreach ($matches[0] as $insertSQL) {
                // Parse the INSERT statement to extract values
                if (preg_match('/VALUES\s*\((.*)\)/is', $insertSQL, $valueMatch)) {
                    $valuesStr = $valueMatch[1];
                    
                    // Split by ),( to get individual rows
                    $rows = preg_split('/\)\s*,\s*\(/', $valuesStr);
                    
                    foreach ($rows as $rowIndex => $row) {
                        // Clean up row string
                        $row = trim($row);
                        if (empty($row)) continue;
                        
                        // Remove leading/trailing parentheses
                        $row = trim($row, '()');
                        
                        // Parse values (handling quoted strings and NULL)
                        $values = [];
                        $currentValue = '';
                        $inQuotes = false;
                        $quoteChar = null;
                        
                        for ($i = 0; $i < strlen($row); $i++) {
                            $char = $row[$i];
                            
                            if (!$inQuotes && ($char === '"' || $char === "'")) {
                                $inQuotes = true;
                                $quoteChar = $char;
                                $currentValue .= $char;
                            } elseif ($inQuotes && $char === $quoteChar) {
                                // Check if escaped
                                if ($i > 0 && $row[$i-1] === '\\') {
                                    $currentValue .= $char;
                                } else {
                                    $inQuotes = false;
                                    $quoteChar = null;
                                    $currentValue .= $char;
                                }
                            } elseif (!$inQuotes && $char === ',') {
                                $values[] = trim($currentValue);
                                $currentValue = '';
                            } else {
                                $currentValue .= $char;
                            }
                        }
                        if (!empty($currentValue)) {
                            $values[] = trim($currentValue);
                        }
                        
                        $valueCount = count($values);
                        
                        // Adjust values to include source column if needed
                        if ($sourceIndex !== false && $valueCount < $columnCount) {
                            // Insert 'manual' at the source position
                            if ($valueCount <= $sourceIndex) {
                                // Pad with NULLs up to source position
                                while (count($values) < $sourceIndex) {
                                    $values[] = 'NULL';
                                }
                                $values[] = "'manual'";
                            } else {
                                // Insert 'manual' at the correct position
                                array_splice($values, $sourceIndex, 0, "'manual'");
                            }
                        } elseif ($sourceIndex !== false && $valueCount == $columnCount) {
                            // Replace value at source position with 'manual'
                            $values[$sourceIndex] = "'manual'";
                        }
                        
                        // Build INSERT statement with all columns
                        $columnList = '`' . implode('`, `', $columns) . '`';
                        $valueList = implode(', ', $values);
                        
                        $insertQuery = "INSERT INTO `products` ($columnList) VALUES ($valueList)";
                        
                        try {
                            $pdo->exec($insertQuery);
                            $inserted++;
                        } catch (PDOException $e) {
                            // Skip duplicates or errors
                            $skipped++;
                            if ($skipped <= 5) {
                                echo "  ⚠ Skipped row: " . substr($e->getMessage(), 0, 100) . "\n";
                            }
                        }
                    }
                }
            }
            
            $pdo->commit();
            echo "\n✓ Restored $inserted product(s)\n";
            if ($skipped > 0) {
                echo "  ⚠ Skipped $skipped row(s) (duplicates or errors)\n";
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } else {
        // File doesn't contain CREATE TABLE, assume it's INSERT statements only
        echo "Executing SQL file directly...\n";
        $pdo->exec($sqlContent);
        echo "✓ SQL executed\n";
    }
    
    // Update all products to have source = 'manual' if NULL
    echo "\nUpdating source values...\n";
    $updated = $pdo->exec("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
    echo "✓ Updated $updated product(s) to have source = 'manual'\n";
    
    // Verify
    echo "\nVerification:\n";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $total = $stmt->fetch()['total'];
    echo "  - Total products: $total\n";
    
    $stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
    $results = $stmt->fetchAll();
    foreach ($results as $row) {
        echo "  - {$row['source']}: {$row['count']} product(s)\n";
    }
    
    echo "\n✅ Products restored successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

