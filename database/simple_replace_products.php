<?php
/**
 * Simple script: drop products table, recreate from products.sql (without source),
 * then add source column afterwards
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Connected to " . PRIMARY_DB_NAME . "\n\n";

// Read products.sql file
$sqlFile = __DIR__ . '/products.sql';
if (!file_exists($sqlFile)) {
    die("❌ products.sql not found\n");
}

$content = file_get_contents($sqlFile);
$lines = explode("\n", $content);

// Step 1: Drop table
echo "Step 1: Dropping products table...\n";
$pdo->exec("DROP TABLE IF EXISTS `products`");
echo "✓ Dropped\n\n";

// Step 2: Extract and execute CREATE TABLE (remove source column line)
echo "Step 2: Creating table structure (without source column)...\n";
$createTable = '';
$inCreateTable = false;
for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    if (preg_match('/CREATE TABLE.*products/i', $line)) {
        $inCreateTable = true;
        $createTable .= $line . "\n";
    } elseif ($inCreateTable) {
        // Skip line with source column
        if (preg_match('/`source`/i', $line)) {
            continue;
        }
        $createTable .= $line . "\n";
        if (preg_match('/\);$/', $line)) {
            break;
        }
    }
}

// Remove source column references
$createTable = preg_replace('/,\s*`source`[^,)]*/i', '', $createTable);

try {
    $pdo->exec($createTable);
    echo "✓ Table created\n\n";
} catch (PDOException $e) {
    die("❌ Error creating table: " . $e->getMessage() . "\n");
}

// Step 3: Import data (remove source from INSERT)
echo "Step 3: Importing data (without source column)...\n";
// Find INSERT line
$insertLineIdx = -1;
for ($i = 0; $i < count($lines); $i++) {
    if (preg_match('/INSERT INTO.*products/i', $lines[$i])) {
        $insertLineIdx = $i;
        break;
    }
}

if ($insertLineIdx === -1) {
    die("❌ INSERT statement not found\n");
}

// Extract column list and remove source
$insertLine = $lines[$insertLineIdx];
$insertLine = preg_replace('/`, `source`|`source`, `/i', '', $insertLine);

// Build INSERT statement
$insertSQL = $insertLine . "\n";
for ($i = $insertLineIdx + 1; $i < count($lines) && $i <= 132; $i++) {
    $line = $lines[$i];
    if (preg_match('/^\(/', $line)) {
        // Remove source value (7th value from the end, before the last 6 NULLs)
        // Actually, source is after created_by. Let's parse and remove it
        // For now, let's just try using MySQL's ability to ignore extra columns
        // No wait, that won't work with explicit column list
        
        // Better: parse values, count commas, remove the value at source position
        // Source is at position 36 (after created_by at 35, 0-indexed)
        // Count values by counting commas outside quotes
        $valueCount = substr_count($line, ',') + 1;
        if ($valueCount === 43) {
            // Remove the 36th value (index 35, 0-indexed)
            // Parse values manually
            $values = [];
            $current = '';
            $inQuotes = false;
            $quoteChar = null;
            $lineContent = trim($line, ',;');
            $lineContent = trim($lineContent, '()');
            
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
            if ($current) {
                $values[] = trim($current);
            }
            
            // Remove source value (index 36, which is 'manual')
            if (count($values) > 36) {
                array_splice($values, 36, 1);
            }
            
            $line = '(' . implode(', ', $values) . ')';
            if ($i < 132) {
                $line .= ',';
            } else {
                $line = rtrim($line, ',') . ';';
            }
        }
        $insertSQL .= $line . "\n";
    }
}

try {
    $pdo->exec($insertSQL);
    echo "✓ Data imported\n\n";
} catch (PDOException $e) {
    die("❌ Error importing: " . $e->getMessage() . "\n");
}

// Step 4: Add source column
echo "Step 4: Adding source column...\n";
$pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
$pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
$pdo->exec("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
echo "✓ Source column added\n\n";

// Verify
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

