<?php
/**
 * Migration script to add requires_specific_list column to all tenant databases
 * Connects directly using root/empty password
 */

require_once __DIR__ . '/config.php';

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';

try {
    $dsn = "mysql:host=$dbHost;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "Connected to MySQL server\n";
    echo "===========================================\n\n";
    
    // Get all tenant databases
    $databases = $pdo->query("SHOW DATABASES LIKE 'electrox_%'")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Found " . count($databases) . " tenant database(s)\n\n";
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($databases as $dbName) {
        echo "Processing: $dbName\n";
        
        try {
            // Connect to this tenant database
            $tenantPdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            
            // Check if column exists
            $check = $tenantPdo->query("SHOW COLUMNS FROM products LIKE 'requires_specific_list'");
            if ($check->rowCount() > 0) {
                echo "  ✓ Column 'requires_specific_list' already exists\n";
            } else {
                // Add column
                $tenantPdo->exec("ALTER TABLE `products` ADD COLUMN `requires_specific_list` tinyint(1) DEFAULT 0 COMMENT '1 if product requires product_specific_list entries'");
                echo "  ✓ Column 'requires_specific_list' added\n";
            }
            
            // Check if product_specific_list table exists
            $tableCheck = $tenantPdo->query("SHOW TABLES LIKE 'product_specific_list'");
            if ($tableCheck->rowCount() > 0) {
                echo "  ✓ Table 'product_specific_list' already exists\n";
            } else {
                // Create table
                $tenantPdo->exec("CREATE TABLE IF NOT EXISTS `product_specific_list` (
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
                  KEY `idx_imei` (`imei`),
                  CONSTRAINT `fk_product_specific_list_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
                  CONSTRAINT `fk_product_specific_list_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                echo "  ✓ Table 'product_specific_list' created\n";
            }
            
            // Add index if it doesn't exist
            $indexCheck = $tenantPdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_requires_specific_list'");
            if ($indexCheck->rowCount() == 0) {
                $tenantPdo->exec("ALTER TABLE `products` ADD INDEX `idx_requires_specific_list` (`requires_specific_list`)");
                echo "  ✓ Index added\n";
            }
            
            $successCount++;
            echo "  ✓ $dbName completed successfully\n\n";
            
        } catch (PDOException $e) {
            $errorCount++;
            echo "  ✗ Error: " . $e->getMessage() . "\n\n";
        }
    }
    
    echo "===========================================\n";
    echo "Migration Summary:\n";
    echo "  - Successfully processed: $successCount database(s)\n";
    echo "  - Errors: $errorCount database(s)\n";
    echo "\nMigration completed!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
