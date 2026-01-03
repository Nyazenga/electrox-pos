<?php
/**
 * Complete import script - adds source column and imports products
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
echo "Checking source column...\n";
$stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
$column = $stmt->fetch();
if (!$column) {
    echo "Adding source column...\n";
    $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
    echo "✓ Column added\n";
} else {
    echo "✓ Column exists\n";
}

// Check index
$stmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_source'");
$index = $stmt->fetch();
if (!$index) {
    echo "Adding index...\n";
    $pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
    echo "✓ Index added\n";
} else {
    echo "✓ Index exists\n";
}

echo "\n";

// Read SQL file
$sqlFile = __DIR__ . '/products.sql';
if (!file_exists($sqlFile)) {
    die("❌ SQL file not found: $sqlFile\n");
}

$lines = file($sqlFile);

// Get INSERT statement header (line 79, index 78)
$insertHeader = trim($lines[78]);
echo "INSERT header: " . substr($insertHeader, 0, 100) . "...\n";

// Extract column list
if (!preg_match('/INSERT INTO `products` \((.+?)\) VALUES/i', $insertHeader, $matches)) {
    die("❌ Could not extract column list\n");
}
$columnList = $matches[1];
echo "Using column list from SQL file\n\n";

// Truncate table
$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$count = $stmt->fetchColumn();
echo "Current products: $count\n";

if ($count > 0) {
    echo "Truncating table...\n";
    $pdo->exec("TRUNCATE TABLE products");
    echo "✓ Table truncated\n\n";
}

// Import rows
$inserted = 0;
$errors = 0;

$pdo->beginTransaction();

try {
    for ($i = 79; $i <= 131; $i++) {
        $line = trim($lines[$i]);
        if (empty($line) || !preg_match('/^\(/', $line)) {
            continue;
        }
        
        // Remove trailing comma or semicolon
        $rowData = rtrim($line, ',;');
        
        // Build INSERT using column list from SQL file
        $sql = "INSERT INTO `products` ($columnList) VALUES $rowData";
        
        try {
            $pdo->exec($sql);
            $inserted++;
            if ($inserted % 10 == 0) {
                echo "  Inserted $inserted rows...\n";
            }
        } catch (PDOException $e) {
            $errors++;
            $rowNum = $i - 78;
            echo "❌ Error on row $rowNum: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    $pdo->commit();
    
    echo "\n✅ Import complete!\n";
    echo "  Inserted: $inserted\n";
    echo "  Errors: $errors\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ Import failed! Rolling back...\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$newCount = $stmt->fetchColumn();
echo "\nTotal products: $newCount\n";

$stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
$sourceCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nSource distribution:\n";
foreach ($sourceCounts as $row) {
    echo "  - " . ($row['source'] ?: 'NULL') . ": " . $row['count'] . "\n";
}

