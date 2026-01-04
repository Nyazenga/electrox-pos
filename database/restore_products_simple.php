<?php
/**
 * Simple restore script - imports products_clean.sql and adds source column
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

echo "Restoring products...\n\n";

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
    $stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
    if (!$stmt->fetch()) {
        echo "Adding source column...\n";
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
        $pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
        echo "✓ Source column added\n";
    } else {
        echo "✓ Source column exists\n";
    }
    
    // Read and execute SQL file
    $sqlFile = APP_PATH . '/database/products_clean.sql';
    if (!file_exists($sqlFile)) {
        echo "❌ File not found: $sqlFile\n";
        exit(1);
    }
    
    echo "Reading SQL file...\n";
    $sqlContent = file_get_contents($sqlFile);
    
    // Remove BOM
    if (substr($sqlContent, 0, 3) === "\xEF\xBB\xBF") {
        $sqlContent = substr($sqlContent, 3);
    }
    
    // Modify CREATE TABLE to include source column if not present
    if (stripos($sqlContent, "`source`") === false && stripos($sqlContent, 'CREATE TABLE') !== false) {
        echo "Adding source column to CREATE TABLE statement...\n";
        $sqlContent = preg_replace(
            "/(`created_by`[^,)]+)(,|\))/i",
            "$1,\n  `source` enum('manual','bulk_upload') DEFAULT 'manual'$2",
            $sqlContent
        );
    }
    
    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sqlContent)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^(--|SET|START|COMMIT)/i', $stmt);
        }
    );
    
    echo "Executing SQL statements...\n";
    $pdo->beginTransaction();
    
    try {
        foreach ($statements as $statement) {
            if (stripos($statement, 'DROP TABLE') !== false) {
                // Skip DROP TABLE - we want to keep existing structure
                continue;
            }
            
            if (stripos($statement, 'CREATE TABLE') !== false) {
                // Skip CREATE TABLE - table already exists with source column
                continue;
            }
            
            if (stripos($statement, 'INSERT INTO') !== false) {
                // Modify INSERT to include source column
                // Get current columns
                $stmt = $pdo->query("SHOW COLUMNS FROM products");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $columnCount = count($columns);
                $sourceIndex = array_search('source', $columns);
                
                // Extract VALUES part
                if (preg_match('/VALUES\s*\((.*)\)/is', $statement, $matches)) {
                    $valuesPart = $matches[1];
                    
                    // Count values in first row
                    $firstRow = preg_split('/\)\s*,\s*\(/', $valuesPart)[0];
                    $firstRow = trim($firstRow, '()');
                    $valueCount = substr_count($firstRow, ',') + 1;
                    
                    if ($valueCount < $columnCount && $sourceIndex !== false) {
                        // Need to add source value
                        // Build column list
                        $columnList = '`' . implode('`, `', $columns) . '`';
                        
                        // Modify INSERT to include all columns
                        $statement = preg_replace(
                            '/INSERT\s+INTO\s+`?products`?\s*\([^)]+\)/i',
                            "INSERT INTO `products` ($columnList)",
                            $statement
                        );
                        
                        // Add 'manual' to each row's values
                        $statement = preg_replace_callback(
                            '/VALUES\s*\(([^)]+)\)/i',
                            function($match) use ($sourceIndex, $valueCount, $columnCount) {
                                $values = $match[1];
                                // Split rows
                                $rows = preg_split('/\)\s*,\s*\(/', $values);
                                $newRows = [];
                                foreach ($rows as $row) {
                                    $row = trim($row, '()');
                                    $rowValues = explode(',', $row);
                                    // Insert 'manual' at source position
                                    if (count($rowValues) <= $sourceIndex) {
                                        while (count($rowValues) < $sourceIndex) {
                                            $rowValues[] = 'NULL';
                                        }
                                        $rowValues[] = "'manual'";
                                    } else {
                                        array_splice($rowValues, $sourceIndex, 0, "'manual'");
                                    }
                                    $newRows[] = '(' . implode(',', $rowValues) . ')';
                                }
                                return 'VALUES ' . implode(', ', $newRows);
                            },
                            $statement
                        );
                    }
                }
                
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // Skip duplicates
                    if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                        echo "  ⚠ Error: " . substr($e->getMessage(), 0, 100) . "\n";
                    }
                }
            } else {
                // Execute other statements
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // Ignore errors for non-critical statements
                }
            }
        }
        
        $pdo->commit();
        echo "✓ SQL executed\n";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
    // Update source values
    echo "Updating source values...\n";
    $updated = $pdo->exec("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
    echo "✓ Updated $updated product(s)\n";
    
    // Verify
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $total = $stmt->fetch()['total'];
    echo "\n✅ Restored $total product(s)!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
