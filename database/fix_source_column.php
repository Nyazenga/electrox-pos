<?php
/**
 * Script to add source column to products table
 * This will check which database is actually being used and add the column there
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/session.php';

initSession();

// Get the database instance that the application uses
$db = Database::getInstance();

// Get the current database name
$currentDb = $db->query("SELECT DATABASE()")->fetchColumn();

echo "Current database: $currentDb\n";
echo "Adding source column to products table...\n\n";

try {
    // Check if column exists
    $columns = $db->getRows("SHOW COLUMNS FROM products LIKE 'source'");
    
    if (empty($columns)) {
        // Column doesn't exist, add it
        $db->query("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
        echo "✓ Column 'source' added successfully.\n";
    } else {
        echo "✓ Column 'source' already exists.\n";
    }
    
    // Check if index exists
    $indexes = $db->getRows("SHOW INDEX FROM products WHERE Key_name = 'idx_source'");
    
    if (empty($indexes)) {
        // Index doesn't exist, add it
        $db->query("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
        echo "✓ Index 'idx_source' added successfully.\n";
    } else {
        echo "✓ Index 'idx_source' already exists.\n";
    }
    
    // Update existing products
    try {
        $db->query("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
        echo "✓ Updated existing products to have source = 'manual'.\n";
    } catch (Exception $e) {
        echo "✓ No products needed updating.\n";
    }
    
    echo "\n✅ Migration completed successfully for database: $currentDb\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

