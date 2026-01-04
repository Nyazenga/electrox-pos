<?php
/**
 * Final restore: Import products.sql, then add source column if missing
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Connected to " . PRIMARY_DB_NAME . "\n\n";

// Step 1: Drop table
echo "Step 1: Dropping products table...\n";
$pdo->exec("DROP TABLE IF EXISTS `products`");
echo "✓ Dropped\n\n";

// Step 2: Read and execute CREATE TABLE from products.sql
echo "Step 2: Creating table structure...\n";
$sqlFile = __DIR__ . '/products.sql';
$lines = file($sqlFile);

$createTableSQL = '';
$inCreateTable = false;
for ($i = 29; $i < 78; $i++) { // Lines 30-78 (0-indexed 29-77)
    $line = $lines[$i];
    if (preg_match('/CREATE TABLE/i', $line)) {
        $inCreateTable = true;
    }
    if ($inCreateTable) {
        $createTableSQL .= $line;
        if (preg_match('/\);$/', trim($line))) {
            break;
        }
    }
}

try {
    $pdo->exec($createTableSQL);
    echo "✓ Table created\n\n";
} catch (PDOException $e) {
    die("❌ Error: " . $e->getMessage() . "\n");
}

// Step 3: Check if source column exists, if not add it before importing data
echo "Step 3: Checking for source column...\n";
$stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
$hasSource = $stmt->fetch();
if (!$hasSource) {
    echo "  Adding source column to table structure...\n";
    $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
    echo "✓ Source column added\n\n";
} else {
    echo "✓ Source column exists\n\n";
}

// Step 4: Import data using the INSERT statement from products.sql
echo "Step 4: Importing product data...\n";
$insertLine = trim($lines[78]); // INSERT INTO line
$insertSQL = $insertLine . "\n";
for ($i = 79; $i <= 131; $i++) {
    $line = $lines[$i];
    if (preg_match('/^\(/', trim($line))) {
        $insertSQL .= $line;
    }
}

try {
    $pdo->exec($insertSQL);
    echo "✓ Data imported\n\n";
} catch (PDOException $e) {
    echo "❌ Error importing: " . $e->getMessage() . "\n";
    // If error is about source column, try importing without it
    if (strpos($e->getMessage(), 'source') !== false) {
        echo "  Retrying without source column in INSERT...\n";
        // Remove source from INSERT column list
        $insertLine = preg_replace('/`, `source`/', '', $insertLine);
        $insertSQL = $insertLine . "\n";
        // Rebuild INSERT without source values
        for ($i = 79; $i <= 131; $i++) {
            $line = trim($lines[$i]);
            if (preg_match('/^\(/', $line)) {
                // Parse and remove source value
                $line = rtrim($line, ',;');
                $lineContent = trim($line, '()');
                $values = [];
                $current = '';
                $inQuotes = false;
                $quoteChar = null;
                for ($j = 0; $j < strlen($lineContent); $j++) {
                    $char = $lineContent[$j];
                    if (!$inQuotes && ($char === "'" || $char === '"')) {
                        $inQuotes = true;
                        $quoteChar = $char;
                        $current .= $char;
                    } elseif ($inQuotes && $char === $quoteChar && ($j === 0 || $lineContent[$j-1] !== '\\')) {
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
                if ($current) $values[] = trim($current);
                if (count($values) > 36) array_splice($values, 36, 1); // Remove source
                $line = '(' . implode(', ', $values) . ')';
                if ($i < 131) $line .= ',';
                else $line = rtrim($line, ',') . ';';
                $insertSQL .= $line . "\n";
            }
        }
        $pdo->exec($insertSQL);
        echo "✓ Data imported (without source in INSERT)\n\n";
    } else {
        throw $e;
    }
}

// Step 5: Ensure source column and index exist
echo "Step 5: Ensuring source column and index...\n";
$stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
if (!$stmt->fetch()) {
    $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
}
$stmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_source'");
if (!$stmt->fetch()) {
    $pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
}
$pdo->exec("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
echo "✓ Source column configured\n\n";

// Step 6: Verify
echo "Step 6: Verification...\n";
$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$count = $stmt->fetchColumn();
echo "Total products: $count\n";

$stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nSource distribution:\n";
foreach ($results as $row) {
    echo "  - " . ($row['source'] ?: 'NULL') . ": " . $row['count'] . "\n";
}

echo "\n✅ Complete!\n";

