<?php
/**
 * Migration script for product_specific_list system
 * Connects directly to electrox_primary using root/empty password
 * 
 * IMPORTANT: This is for testing. For production, update credentials.
 */

require_once __DIR__ . '/config.php';

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = PRIMARY_DB_NAME;

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    
    echo "Connected to database: $dbName\n";
    echo "===========================================\n\n";
    
    // Step 1: Create product_specific_list table
    echo "Step 1: Creating product_specific_list table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_specific_list` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `product_id` int(11) NOT NULL,
      `branch_id` int(11) DEFAULT NULL,
      `color` varchar(50) DEFAULT NULL,
      `storage` varchar(50) DEFAULT NULL,
      `sim_configuration` varchar(50) DEFAULT NULL,
      `serial_number` varchar(100) DEFAULT NULL,
      `imei` varchar(50) DEFAULT NULL,
      `battery_health` int(11) DEFAULT NULL COMMENT 'Percentage 0-100',
      `manufacturer` varchar(100) DEFAULT NULL,
      `warranty_months` int(11) DEFAULT 0,
      `warranty_terms` text DEFAULT NULL,
      `condition` enum('New','Refurbished','Used') DEFAULT 'New',
      `trade_in_eligible` tinyint(1) DEFAULT 0,
      `status` enum('available','sold','returned','damaged','transferred') DEFAULT 'available',
      `sale_item_id` int(11) DEFAULT NULL COMMENT 'Links to sale_items when sold',
      `invoice_item_id` int(11) DEFAULT NULL COMMENT 'Links to invoice_items when invoiced',
      `refund_item_id` int(11) DEFAULT NULL COMMENT 'Links to refund_items when returned',
      `grn_item_id` int(11) DEFAULT NULL COMMENT 'Links to grn_items when received',
      `stock_take_item_id` int(11) DEFAULT NULL COMMENT 'Links to stock_take_items when added via stock take',
      `transfer_item_id` int(11) DEFAULT NULL COMMENT 'Links to transfer_items when transferred',
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `created_by` int(11) DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_serial_number` (`serial_number`, `branch_id`),
      UNIQUE KEY `unique_imei` (`imei`, `branch_id`),
      KEY `idx_product_id` (`product_id`),
      KEY `idx_branch_id` (`branch_id`),
      KEY `idx_status` (`status`),
      KEY `idx_sale_item_id` (`sale_item_id`),
      KEY `idx_invoice_item_id` (`invoice_item_id`),
      KEY `idx_grn_item_id` (`grn_item_id`),
      KEY `idx_stock_take_item_id` (`stock_take_item_id`),
      KEY `idx_transfer_item_id` (`transfer_item_id`),
      KEY `idx_serial_number` (`serial_number`),
      KEY `idx_imei` (`imei`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  ✓ Table created\n\n";
    
    // Step 2: Add requires_specific_list column
    echo "Step 2: Adding requires_specific_list column...\n";
    $check = $pdo->query("SHOW COLUMNS FROM products LIKE 'requires_specific_list'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `requires_specific_list` tinyint(1) DEFAULT 0 COMMENT '1 if product requires product_specific_list entries (smartphones, laptops, tablets, gaming devices)'");
        echo "  ✓ Column added\n";
    } else {
        echo "  ✓ Column already exists\n";
    }
    
    // Check if source column exists
    $checkSource = $pdo->query("SHOW COLUMNS FROM products LIKE 'source'");
    if ($checkSource->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `source` varchar(50) DEFAULT 'manual' COMMENT 'Source of product: manual, import, bulk, etc.'");
        echo "  ✓ Column 'source' added\n";
    } else {
        echo "  ✓ Column 'source' already exists\n";
    }
    echo "\n";
    
    // Step 3: Add index
    echo "Step 3: Adding index...\n";
    $check = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_requires_specific_list'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `products` ADD INDEX `idx_requires_specific_list` (`requires_specific_list`)");
        echo "  ✓ Index added\n\n";
    } else {
        echo "  ✓ Index already exists\n\n";
    }
    
    // Step 4: Update products to set requires_specific_list flag
    echo "Step 4: Updating products to set requires_specific_list flag...\n";
    $updated = $pdo->exec("UPDATE `products` 
        SET `requires_specific_list` = 1 
        WHERE (
            `category_id` IN (
                SELECT `id` FROM `product_categories` 
                WHERE LOWER(`name`) LIKE '%smartphone%' 
                   OR LOWER(`name`) LIKE '%phone%' 
                   OR LOWER(`name`) LIKE '%laptop%' 
                   OR LOWER(`name`) LIKE '%tablet%' 
                   OR LOWER(`name`) LIKE '%gaming%'
            )
            OR `serial_number` IS NOT NULL 
            OR `imei` IS NOT NULL
        )
        AND `status` = 'Active'");
    echo "  ✓ Updated $updated products\n\n";
    
    // Step 5: Migrate existing data (if any)
    echo "Step 5: Migrating existing unique products to product_specific_list...\n";
    $products = $pdo->query("SELECT * FROM products WHERE requires_specific_list = 1 
        AND (serial_number IS NOT NULL OR imei IS NOT NULL OR quantity_in_stock > 0)")->fetchAll();
    
    $migrated = 0;
    $skipped = 0;
    foreach ($products as $product) {
        // Check if entry already exists
        $exists = $pdo->prepare("SELECT COUNT(*) as cnt FROM product_specific_list WHERE product_id = ?");
        $exists->execute([$product['id']]);
        if ($exists->fetch()['cnt'] > 0) {
            continue; // Already migrated
        }
        
        // Check for duplicate serial/IMEI before inserting
        if (!empty($product['serial_number'])) {
            $duplicate = $pdo->prepare("SELECT COUNT(*) as cnt FROM product_specific_list WHERE serial_number = ? AND branch_id = ?");
            $duplicate->execute([$product['serial_number'], $product['branch_id']]);
            if ($duplicate->fetch()['cnt'] > 0) {
                $skipped++;
                echo "  ⚠ Skipping product ID {$product['id']} - duplicate serial number: {$product['serial_number']}\n";
                continue;
            }
        }
        
        if (!empty($product['imei'])) {
            $duplicate = $pdo->prepare("SELECT COUNT(*) as cnt FROM product_specific_list WHERE imei = ? AND branch_id = ?");
            $duplicate->execute([$product['imei'], $product['branch_id']]);
            if ($duplicate->fetch()['cnt'] > 0) {
                $skipped++;
                echo "  ⚠ Skipping product ID {$product['id']} - duplicate IMEI: {$product['imei']}\n";
                continue;
            }
        }
        
        // Create entry
        try {
            $stmt = $pdo->prepare("INSERT INTO `product_specific_list` (
                `product_id`, `branch_id`, `color`, `storage`, `sim_configuration`, 
                `serial_number`, `imei`, `battery_health`, `manufacturer`, 
                `warranty_months`, `warranty_terms`, `condition`, `trade_in_eligible`,
                `status`, `created_at`, `created_by`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $status = ($product['quantity_in_stock'] > 0 && $product['status'] == 'Active') ? 'available' : 'sold';
            
            $stmt->execute([
                $product['id'],
                $product['branch_id'],
                $product['color'],
                $product['storage'],
                $product['sim_configuration'],
                $product['serial_number'],
                $product['imei'],
                $product['battery_health'],
                $product['manufacturer'],
                $product['warranty_months'] ?? 0,
                $product['warranty_terms'],
                $product['condition'] ?? 'New',
                $product['trade_in_eligible'] ?? 0,
                $status,
                $product['created_at'] ?? date('Y-m-d H:i:s'),
                $product['created_by']
            ]);
            $migrated++;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                // Duplicate entry - skip
                $skipped++;
                echo "  ⚠ Skipping product ID {$product['id']} - duplicate entry: " . $e->getMessage() . "\n";
            } else {
                throw $e;
            }
        }
    }
    echo "  ✓ Migrated $migrated products\n";
    if ($skipped > 0) {
        echo "  ⚠ Skipped $skipped products (duplicates)\n";
    }
    echo "\n";
    
    // Step 6: Update quantities
    echo "Step 6: Updating product quantities to match product_specific_list counts...\n";
    $updated = $pdo->exec("UPDATE `products` p
        SET `quantity_in_stock` = (
            SELECT COUNT(*) 
            FROM `product_specific_list` psl 
            WHERE psl.`product_id` = p.`id` 
            AND psl.`status` = 'available'
        )
        WHERE p.`requires_specific_list` = 1");
    echo "  ✓ Updated quantities for $updated products\n\n";
    
    echo "===========================================\n";
    echo "Migration completed successfully!\n";
    
    // Summary
    $totalProducts = $pdo->query("SELECT COUNT(*) as cnt FROM products WHERE requires_specific_list = 1")->fetch()['cnt'];
    $totalEntries = $pdo->query("SELECT COUNT(*) as cnt FROM product_specific_list")->fetch()['cnt'];
    $availableEntries = $pdo->query("SELECT COUNT(*) as cnt FROM product_specific_list WHERE status = 'available'")->fetch()['cnt'];
    
    echo "\nSummary:\n";
    echo "  - Products requiring specific list: $totalProducts\n";
    echo "  - Total product_specific_list entries: $totalEntries\n";
    echo "  - Available entries: $availableEntries\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
