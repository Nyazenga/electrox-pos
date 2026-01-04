<?php
/**
 * Replace products table structure and import data from localhost export
 * Then add source column afterwards
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Connected to " . PRIMARY_DB_NAME . "\n\n";

// Step 1: Drop existing products table
echo "Step 1: Dropping existing products table...\n";
try {
    $pdo->exec("DROP TABLE IF EXISTS `products`");
    echo "✓ Table dropped\n\n";
} catch (PDOException $e) {
    echo "❌ Error dropping table: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 2: Create table structure from products.sql (extract CREATE TABLE)
echo "Step 2: Creating table structure...\n";
$productsSqlFile = __DIR__ . '/products.sql';
if (!file_exists($productsSqlFile)) {
    die("❌ products.sql file not found: $productsSqlFile\n");
}

$sqlContent = file_get_contents($productsSqlFile);
// Extract CREATE TABLE statement (from start to first INSERT)
if (preg_match('/(CREATE TABLE[^;]+;)/is', $sqlContent, $matches)) {
    $createTableSQL = $matches[1];
    try {
        $pdo->exec($createTableSQL);
        echo "✓ Table structure created\n\n";
    } catch (PDOException $e) {
        echo "❌ Error creating table: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    die("❌ Could not extract CREATE TABLE statement from products.sql\n");
}

// Step 3: Import data (extract INSERT statement from products.sql)
echo "Step 3: Importing product data...\n";
// Extract INSERT statement (from INSERT to end of file, but remove source column from column list and values)
$lines = file($productsSqlFile);
$insertLine = trim($lines[78]); // Line 79 in file (0-indexed 78)

// Get column list from INSERT (but remove source column)
if (preg_match('/INSERT INTO `products` \((.+?)\) VALUES/i', $insertLine, $matches)) {
    $columnList = $matches[1];
    // Remove source column from column list
    $columnList = preg_replace('/`, `source`|`source`, `/', '', $columnList);
    
    // Import rows (lines 80-132, indices 79-131) but remove source value
    $inserted = 0;
    $pdo->beginTransaction();
    
    try {
        for ($i = 79; $i <= 131; $i++) {
            $line = trim($lines[$i]);
            if (empty($line) || !preg_match('/^\(/', $line)) {
                continue;
            }
            
            // Remove trailing comma or semicolon
            $rowData = rtrim($line, ',;');
            
            // Remove source value (the value before the last 7 NULLs)
            // This is tricky - we need to remove the 'manual' value
            // Actually, let's just use the row as-is but with modified column list
            // Since source has a default value, we can omit it
            
            // Build INSERT without source column
            $sql = "INSERT INTO `products` ($columnList) VALUES $rowData";
            
            // But wait - the VALUES still have source. We need to remove it.
            // Actually, simpler: use the row data but skip the source column value
            // Parse the row to remove the source value position
            
            // For now, let's try a different approach: use the INSERT as-is but modify the column list
            // Since source column has a default, MySQL should accept it
            // Actually no - the column count won't match
            
            // Better: extract values, remove source value, reconstruct
            // Parse row values
            $values = [];
            $current = '';
            $inQuotes = false;
            $quoteChar = null;
            
            // Remove outer parentheses
            $rowData = trim($rowData, '()');
            
            for ($j = 0; $j < strlen($rowData); $j++) {
                $char = $rowData[$j];
                if (!$inQuotes && ($char === "'" || $char === '"')) {
                    $inQuotes = true;
                    $quoteChar = $char;
                    $current .= $char;
                } elseif ($inQuotes && $char === $quoteChar && ($j === 0 || $rowData[$j-1] !== '\\')) {
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
            if ($current) {
                $values[] = trim($current);
            }
            
            // Remove source value (position 36, 0-indexed, after created_by)
            // Actually, source is at index 36 (after created_by at 35)
            if (count($values) > 36) {
                array_splice($values, 36, 1); // Remove source value
            }
            
            $rowDataFixed = '(' . implode(', ', $values) . ')';
            $sql = "INSERT INTO `products` ($columnList) VALUES $rowDataFixed";
            
            try {
                $pdo->exec($sql);
                $inserted++;
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo "❌ Error on row " . ($i - 78) . ": " . $e->getMessage() . "\n";
                throw $e;
            }
        }
        
        $pdo->commit();
        echo "✓ Data imported: $inserted products\n\n";
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "❌ Error importing data: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    die("❌ Could not extract INSERT statement from products.sql\n");
}

// Step 4: Add source column
echo "Step 4: Adding source column...\n";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
    $column = $stmt->fetch();
    
    if (!$column) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
        echo "✓ Source column added\n";
    } else {
        echo "✓ Source column already exists\n";
    }
    
    // Add index
    $stmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_source'");
    $index = $stmt->fetch();
    if (!$index) {
        $pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
        echo "✓ Index added\n";
    }
    
    // Update existing products to 'manual'
    $updated = $pdo->exec("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
    echo "✓ Updated $updated products to have source = 'manual'\n\n";
} catch (PDOException $e) {
    echo "❌ Error adding source column: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 5: Verify
echo "Step 5: Verification...\n";
$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$count = $stmt->fetchColumn();
echo "Total products: $count\n";

$stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
$sourceCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nSource distribution:\n";
foreach ($sourceCounts as $row) {
    echo "  - " . ($row['source'] ?: 'NULL') . ": " . $row['count'] . "\n";
}

echo "\n✅ Complete!\n";

