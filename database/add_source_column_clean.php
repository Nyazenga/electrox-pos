<?php
/**
 * Add source column to products table
 * This script safely adds the source column if it doesn't exist
 * Works on both local and live databases
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

echo "Adding source column to products table...\n\n";

try {
    // Connect to primary database (electrox_primary)
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    echo "Connected to database: " . PRIMARY_DB_NAME . "\n";
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'source'");
    $columnExists = $stmt->fetch() !== false;
    
    if (!$columnExists) {
        echo "Column 'source' does not exist. Adding it...\n";
        
        // Add the column
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
        echo "✓ Column 'source' added successfully.\n";
    } else {
        echo "✓ Column 'source' already exists.\n";
        
        // Check if the enum values are correct
        $stmt = $pdo->query("SHOW COLUMNS FROM products WHERE Field = 'source'");
        $columnInfo = $stmt->fetch();
        
        if ($columnInfo) {
            $type = $columnInfo['Type'];
            if (strpos($type, "enum('manual','bulk_upload')") === false) {
                echo "⚠ Column exists but enum values may be incorrect. Type: $type\n";
                echo "Attempting to modify column...\n";
                try {
                    $pdo->exec("ALTER TABLE `products` MODIFY COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual'");
                    echo "✓ Column modified successfully.\n";
                } catch (PDOException $e) {
                    echo "⚠ Could not modify column: " . $e->getMessage() . "\n";
                    echo "  You may need to manually update the column type.\n";
                }
            } else {
                echo "✓ Column type is correct.\n";
            }
        }
    }
    
    // Check if index exists
    $stmt = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_source'");
    $indexExists = $stmt->fetch() !== false;
    
    if (!$indexExists) {
        echo "Index 'idx_source' does not exist. Adding it...\n";
        $pdo->exec("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
        echo "✓ Index 'idx_source' added successfully.\n";
    } else {
        echo "✓ Index 'idx_source' already exists.\n";
    }
    
    // Update any NULL or empty source values to 'manual'
    echo "\nUpdating existing products...\n";
    $updated = $pdo->exec("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
    echo "✓ Updated $updated product(s) to have source = 'manual'.\n";
    
    // Verify
    echo "\nVerification:\n";
    $stmt = $pdo->query("SELECT source, COUNT(*) as count FROM products GROUP BY source");
    $results = $stmt->fetchAll();
    foreach ($results as $row) {
        echo "  - {$row['source']}: {$row['count']} product(s)\n";
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $total = $stmt->fetch()['total'];
    echo "  - Total products: $total\n";
    
    echo "\n✅ Source column setup completed successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

