<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();

echo "Checking for 'source' column...\n";

// Get all columns
$columns = $db->getRows("SHOW COLUMNS FROM products");
$hasSource = false;

foreach ($columns as $col) {
    if ($col['Field'] == 'source') {
        $hasSource = true;
        echo "✓ Column 'source' EXISTS\n";
        break;
    }
}

if (!$hasSource) {
    echo "✗ Column 'source' DOES NOT EXIST - Adding it now...\n";
    try {
        $db->query("ALTER TABLE `products` ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`");
        echo "✓ Column 'source' added successfully!\n";
        
        // Add index
        $db->query("ALTER TABLE `products` ADD KEY `idx_source` (`source`)");
        echo "✓ Index 'idx_source' added successfully!\n";
        
        // Update existing products
        $db->query("UPDATE `products` SET `source` = 'manual'");
        echo "✓ Updated existing products to have source = 'manual'\n";
        
        echo "\n✅ Migration completed successfully!\n";
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "✓ Column already exists - no action needed\n";
}

