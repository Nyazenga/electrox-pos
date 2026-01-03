<?php
/**
 * Script to add source column to products table
 * Run this once to add the column if it doesn't exist
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();

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
    
    // Update existing products (only if column was just added or needs updating)
    try {
        $db->query("UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = ''");
        echo "✓ Updated existing products to have source = 'manual'.\n";
    } catch (Exception $e) {
        // Ignore if no rows to update
        echo "✓ No products needed updating.\n";
    }
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

