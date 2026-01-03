<?php
/**
 * Final script to add source column to products table in electrox_primary
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
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
    $column = $stmt->fetch();
    
    if (!$column) {
        echo "Adding source column...\n";
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
        echo "✓ Column added\n";
    } else {
        echo "✓ Column already exists\n";
    }
    
    // Check if index exists
    $stmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_source'");
    $index = $stmt->fetch();
    
    if (!$index) {
        echo "Adding index...\n";
        $pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
        echo "✓ Index added\n";
    } else {
        echo "✓ Index already exists\n";
    }
    
    // Update existing products
    echo "Updating existing products...\n";
    $updated = $pdo->exec("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
    echo "✓ Updated $updated products\n";
    
    // Verify
    $stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
    $results = $stmt->fetchAll();
    echo "\nSource distribution:\n";
    foreach ($results as $row) {
        $source = $row['source'] ?? 'NULL';
        echo "  $source: " . $row['count'] . " products\n";
    }
    
    echo "\n✅ Done!\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

