<?php
/**
 * Working import: Use column list from INSERT statement in SQL file
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Connected to " . PRIMARY_DB_NAME . "\n\n";

// Ensure source column exists
$stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
if (!$stmt->fetch()) {
    echo "Adding source column...\n";
    $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
    $pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
    echo "✓ Source column added\n\n";
}

// Read SQL file
$sqlFile = __DIR__ . '/products.sql';
$lines = file($sqlFile);

// Extract column list from INSERT statement (line 79, index 78)
$insertLine = trim($lines[78]);
if (!preg_match('/INSERT INTO `products` \((.+?)\) VALUES/i', $insertLine, $matches)) {
    die("❌ Could not extract column list\n");
}
$columnList = $matches[1]; // Use column list AS-IS from SQL file
echo "Using column list from SQL file\n\n";

// Truncate
$pdo->exec("TRUNCATE TABLE products");
echo "Table truncated\n\n";

// Import rows
$inserted = 0;
$pdo->beginTransaction();

try {
    for ($i = 79; $i <= 131; $i++) {
        $line = trim($lines[$i]);
        if (empty($line) || !preg_match('/^\(/', $line)) {
            continue;
        }
        
        $rowData = rtrim($line, ',;');
        $sql = "INSERT INTO `products` ($columnList) VALUES $rowData";
        
        $pdo->exec($sql);
        $inserted++;
        if ($inserted % 10 == 0) {
            echo "  Inserted $inserted...\n";
        }
    }
    
    $pdo->commit();
    echo "\n✅ Imported $inserted products\n\n";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    die("❌ Error: " . $e->getMessage() . "\n");
}

// Verify
$stmt = $pdo->query("SELECT COUNT(*) FROM products");
echo "Total products: " . $stmt->fetchColumn() . "\n";

$stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nSource distribution:\n";
foreach ($results as $row) {
    echo "  - " . ($row['source'] ?: 'NULL') . ": " . $row['count'] . "\n";
}

echo "\n✅ Done!\n";

