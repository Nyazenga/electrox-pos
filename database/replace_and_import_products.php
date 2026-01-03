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

// Step 2: Create table structure from localhost export
echo "Step 2: Creating table structure...\n";
$structureFile = __DIR__ . '/products_structure_only.sql';
if (!file_exists($structureFile)) {
    die("❌ Structure file not found: $structureFile\n");
}

$structureSQL = file_get_contents($structureFile);
// Remove any CREATE DATABASE or USE statements
$structureSQL = preg_replace('/CREATE DATABASE.*?;/is', '', $structureSQL);
$structureSQL = preg_replace('/USE.*?;/is', '', $structureSQL);

try {
    $pdo->exec($structureSQL);
    echo "✓ Table structure created\n\n";
} catch (PDOException $e) {
    echo "❌ Error creating table: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 3: Import data
echo "Step 3: Importing product data...\n";
$dataFile = __DIR__ . '/products_data_only.sql';
if (!file_exists($dataFile)) {
    die("❌ Data file not found: $dataFile\n");
}

$dataSQL = file_get_contents($dataFile);
// Remove any INSERT statements that might have problematic syntax
$dataSQL = preg_replace('/\/\*!.*?\*\//', '', $dataSQL);
$dataSQL = preg_replace('/LOCK TABLES.*?UNLOCK TABLES;/is', '', $dataSQL);

try {
    $pdo->exec($dataSQL);
    echo "✓ Data imported\n\n";
} catch (PDOException $e) {
    echo "❌ Error importing data: " . $e->getMessage() . "\n";
    exit(1);
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

