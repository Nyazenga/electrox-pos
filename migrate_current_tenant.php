<?php
/**
 * Migration script to add requires_specific_list column to current tenant database
 * This uses the session to determine which tenant database to update
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';

// Get current tenant database name
$tenantName = getCurrentTenantDbName();
if (!$tenantName) {
    echo "Error: No tenant database found in session.\n";
    echo "Please log in first, or specify tenant name manually.\n";
    exit(1);
}

$dbName = 'electrox_' . $tenantName;

echo "Migrating tenant database: $dbName\n";
echo "===========================================\n\n";

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Step 1: Add requires_specific_list column
    echo "Step 1: Adding requires_specific_list column...\n";
    $check = $pdo->query("SHOW COLUMNS FROM products LIKE 'requires_specific_list'");
    if ($check->rowCount() > 0) {
        echo "  ✓ Column 'requires_specific_list' already exists\n";
    } else {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `requires_specific_list` tinyint(1) DEFAULT 0 COMMENT '1 if product requires product_specific_list entries'");
        echo "  ✓ Column 'requires_specific_list' added\n";
    }
    
    // Step 2: Add index
    echo "\nStep 2: Adding index...\n";
    $indexCheck = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_requires_specific_list'");
    if ($indexCheck->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `products` ADD INDEX `idx_requires_specific_list` (`requires_specific_list`)");
        echo "  ✓ Index added\n";
    } else {
        echo "  ✓ Index already exists\n";
    }
    
    // Step 3: Create product_specific_list table
    echo "\nStep 3: Creating product_specific_list table...\n";
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'product_specific_list'");
    if ($tableCheck->rowCount() > 0) {
        echo "  ✓ Table 'product_specific_list' already exists\n";
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_specific_list` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `product_id` int(11) NOT NULL,
          `branch_id` int(11) DEFAULT NULL,
          `color` varchar(50) DEFAULT NULL,
          `storage` varchar(50) DEFAULT NULL,
          `sim_configuration` varchar(50) DEFAULT NULL,
          `serial_number` varchar(100) DEFAULT NULL,
          `imei` varchar(50) DEFAULT NULL,
          `battery_health` int(11) DEFAULT NULL,
          `manufacturer` varchar(100) DEFAULT NULL,
          `warranty_months` int(11) DEFAULT 0,
          `warranty_terms` text DEFAULT NULL,
          `condition` enum('New','Refurbished','Used') DEFAULT 'New',
          `trade_in_eligible` tinyint(1) DEFAULT 0,
          `status` enum('available','sold','transferred','returned','damaged','in_stock_take') DEFAULT 'available',
          `grn_item_id` int(11) DEFAULT NULL,
          `sale_item_id` int(11) DEFAULT NULL,
          `stock_take_item_id` int(11) DEFAULT NULL,
          `transfer_item_id` int(11) DEFAULT NULL,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `created_by` int(11) DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_serial_number` (`serial_number`, `branch_id`),
          UNIQUE KEY `unique_imei` (`imei`, `branch_id`),
          KEY `idx_product_id` (`product_id`),
          KEY `idx_branch_id` (`branch_id`),
          KEY `idx_status` (`status`),
          KEY `idx_serial_number` (`serial_number`),
          KEY `idx_imei` (`imei`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "  ✓ Table 'product_specific_list' created\n";
    }
    
    echo "\n===========================================\n";
    echo "✓ Migration completed successfully for $dbName!\n";
    echo "You can now add products without errors.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
