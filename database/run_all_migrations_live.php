<?php
/**
 * Run ALL pending database migrations on all tenant databases
 * Handles: category characteristics tables, is_specific column, condition VARCHAR
 * Date: 2026-03-09
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

$dbHost = DB_HOST;
$dbUser = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1') ? 'root' : DB_USER;
$dbPass = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1') ? '' : DB_PASS;

echo "🔧 RUNNING ALL PENDING MIGRATIONS\n";
echo str_repeat("=", 60) . "\n\n";

try {
    $primaryPdo = new PDO(
        "mysql:host=$dbHost;dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    
    $tenants = $primaryPdo->query("SELECT tenant_slug FROM tenants WHERE status = 'active'")->fetchAll();
    
    foreach ($tenants as $tenant) {
        $dbName = 'electrox_' . $tenant['tenant_slug'];
        echo "📍 Processing: $dbName\n";
        
        try {
            $pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );

            // ============================================================
            // MIGRATION 1: Add is_specific column to product_categories
            // ============================================================
            $col = $pdo->query("SHOW COLUMNS FROM product_categories WHERE Field = 'is_specific'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE `product_categories` ADD COLUMN `is_specific` tinyint(1) DEFAULT 0 COMMENT '1 if products in this category are unique/specific items' AFTER `tax_id`");
                echo "  ✅ Added is_specific column to product_categories\n";
            } else {
                echo "  ⏭️  is_specific column already exists\n";
            }

            // ============================================================
            // MIGRATION 2: Create category_characteristics table
            // ============================================================
            $tableExists = $pdo->query("SHOW TABLES LIKE 'category_characteristics'")->fetch();
            if (!$tableExists) {
                $pdo->exec("CREATE TABLE `category_characteristics` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(100) NOT NULL,
                    `label` varchar(150) NOT NULL,
                    `field_type` enum('text','number','select','color','boolean','textarea','date') NOT NULL DEFAULT 'text',
                    `options` text DEFAULT NULL,
                    `is_system` tinyint(1) DEFAULT 0,
                    `system_column` varchar(100) DEFAULT NULL,
                    `description` varchar(255) DEFAULT NULL,
                    `sort_order` int(11) DEFAULT 0,
                    `is_active` tinyint(1) DEFAULT 1,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                echo "  ✅ Created category_characteristics table\n";
            } else {
                echo "  ⏭️  category_characteristics table already exists\n";
            }

            // ============================================================
            // MIGRATION 3: Create category_characteristic_assignments table
            // ============================================================
            $tableExists = $pdo->query("SHOW TABLES LIKE 'category_characteristic_assignments'")->fetch();
            if (!$tableExists) {
                $pdo->exec("CREATE TABLE `category_characteristic_assignments` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `category_id` int(11) NOT NULL,
                    `characteristic_id` int(11) NOT NULL,
                    `is_required` tinyint(1) DEFAULT 0,
                    `sort_order` int(11) DEFAULT 0,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_category_char` (`category_id`, `characteristic_id`),
                    KEY `idx_category_id` (`category_id`),
                    KEY `idx_characteristic_id` (`characteristic_id`),
                    CONSTRAINT `fk_cca_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_cca_characteristic` FOREIGN KEY (`characteristic_id`) REFERENCES `category_characteristics` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                echo "  ✅ Created category_characteristic_assignments table\n";
            } else {
                echo "  ⏭️  category_characteristic_assignments table already exists\n";
            }

            // ============================================================
            // MIGRATION 4: Create product_characteristic_values table
            // ============================================================
            $tableExists = $pdo->query("SHOW TABLES LIKE 'product_characteristic_values'")->fetch();
            if (!$tableExists) {
                $pdo->exec("CREATE TABLE `product_characteristic_values` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `specific_list_id` int(11) NOT NULL,
                    `characteristic_id` int(11) NOT NULL,
                    `value` text DEFAULT NULL,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_item_char` (`specific_list_id`, `characteristic_id`),
                    KEY `idx_specific_list_id` (`specific_list_id`),
                    KEY `idx_characteristic_id` (`characteristic_id`),
                    CONSTRAINT `fk_pcv_specific_list` FOREIGN KEY (`specific_list_id`) REFERENCES `product_specific_list` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_pcv_characteristic` FOREIGN KEY (`characteristic_id`) REFERENCES `category_characteristics` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                echo "  ✅ Created product_characteristic_values table\n";
            } else {
                echo "  ⏭️  product_characteristic_values table already exists\n";
            }

            // ============================================================
            // MIGRATION 5: Seed system characteristics
            // ============================================================
            $charCount = $pdo->query("SELECT COUNT(*) FROM category_characteristics")->fetchColumn();
            if ($charCount == 0) {
                $pdo->exec("INSERT IGNORE INTO `category_characteristics` (`name`, `label`, `field_type`, `options`, `is_system`, `system_column`, `description`, `sort_order`) VALUES
                    ('color', 'Color', 'text', NULL, 1, 'color', 'Product color', 1),
                    ('storage', 'Storage / Memory Size', 'select', '[\"16GB\",\"32GB\",\"64GB\",\"128GB\",\"256GB\",\"512GB\",\"1TB\",\"2TB\"]', 1, 'storage', 'Storage capacity', 2),
                    ('serial_number', 'Serial Number', 'text', NULL, 1, 'serial_number', 'Unique serial number', 3),
                    ('imei', 'IMEI', 'text', NULL, 1, 'imei', 'International Mobile Equipment Identity', 4),
                    ('sim_configuration', 'SIM Configuration', 'select', '[\"Single SIM\",\"Dual SIM\",\"eSIM\",\"Dual SIM + eSIM\"]', 1, 'sim_configuration', 'SIM card configuration', 5),
                    ('battery_health', 'Battery Health (%)', 'number', NULL, 1, 'battery_health', 'Battery health percentage (0-100)', 6),
                    ('manufacturer', 'Manufacturer', 'text', NULL, 1, 'manufacturer', 'Product manufacturer', 7),
                    ('warranty_months', 'Warranty (Months)', 'number', NULL, 1, 'warranty_months', 'Warranty period in months', 8),
                    ('warranty_terms', 'Warranty Terms', 'textarea', NULL, 1, 'warranty_terms', 'Warranty terms and conditions', 9),
                    ('item_condition', 'Condition', 'select', '[\"New\",\"Refurbished\",\"Used\"]', 1, 'condition', 'Product condition', 10),
                    ('trade_in_eligible', 'Trade-in Eligible', 'boolean', NULL, 1, 'trade_in_eligible', 'Whether the item is eligible for trade-in', 11)");
                echo "  ✅ Seeded " . $pdo->query("SELECT COUNT(*) FROM category_characteristics")->fetchColumn() . " system characteristics\n";
            } else {
                echo "  ⏭️  Characteristics already seeded ($charCount records)\n";
            }

            // ============================================================
            // MIGRATION 6: Mark specific categories
            // ============================================================
            $pdo->exec("UPDATE `product_categories` SET `is_specific` = 1 
                WHERE LOWER(`name`) IN ('smartphones', 'laptops', 'tablets', 'gaming', 'wearables') AND `is_specific` = 0");
            $updated = $pdo->query("SELECT COUNT(*) FROM product_categories WHERE is_specific = 1")->fetchColumn();
            echo "  ✅ Specific categories: $updated\n";

            // ============================================================
            // MIGRATION 7: Auto-assign characteristics to specific categories
            // ============================================================
            $assignCount = $pdo->query("SELECT COUNT(*) FROM category_characteristic_assignments")->fetchColumn();
            if ($assignCount == 0) {
                // Smartphones: all characteristics
                $pdo->exec("INSERT IGNORE INTO `category_characteristic_assignments` (`category_id`, `characteristic_id`, `is_required`, `sort_order`)
                    SELECT pc.id, cc.id, CASE WHEN cc.name IN ('serial_number', 'color') THEN 1 ELSE 0 END, cc.sort_order
                    FROM product_categories pc CROSS JOIN category_characteristics cc
                    WHERE LOWER(pc.name) = 'smartphones'
                    AND cc.name IN ('color', 'storage', 'serial_number', 'imei', 'sim_configuration', 'battery_health', 'manufacturer', 'warranty_months', 'warranty_terms', 'item_condition', 'trade_in_eligible')");

                // Laptops
                $pdo->exec("INSERT IGNORE INTO `category_characteristic_assignments` (`category_id`, `characteristic_id`, `is_required`, `sort_order`)
                    SELECT pc.id, cc.id, CASE WHEN cc.name IN ('serial_number') THEN 1 ELSE 0 END, cc.sort_order
                    FROM product_categories pc CROSS JOIN category_characteristics cc
                    WHERE LOWER(pc.name) = 'laptops'
                    AND cc.name IN ('color', 'storage', 'serial_number', 'manufacturer', 'warranty_months', 'warranty_terms', 'item_condition', 'trade_in_eligible')");

                // Tablets
                $pdo->exec("INSERT IGNORE INTO `category_characteristic_assignments` (`category_id`, `characteristic_id`, `is_required`, `sort_order`)
                    SELECT pc.id, cc.id, CASE WHEN cc.name IN ('serial_number') THEN 1 ELSE 0 END, cc.sort_order
                    FROM product_categories pc CROSS JOIN category_characteristics cc
                    WHERE LOWER(pc.name) = 'tablets'
                    AND cc.name IN ('color', 'storage', 'serial_number', 'sim_configuration', 'battery_health', 'manufacturer', 'warranty_months', 'warranty_terms', 'item_condition', 'trade_in_eligible')");

                // Gaming
                $pdo->exec("INSERT IGNORE INTO `category_characteristic_assignments` (`category_id`, `characteristic_id`, `is_required`, `sort_order`)
                    SELECT pc.id, cc.id, CASE WHEN cc.name IN ('serial_number') THEN 1 ELSE 0 END, cc.sort_order
                    FROM product_categories pc CROSS JOIN category_characteristics cc
                    WHERE LOWER(pc.name) = 'gaming'
                    AND cc.name IN ('color', 'storage', 'serial_number', 'manufacturer', 'warranty_months', 'item_condition')");

                // Wearables
                $pdo->exec("INSERT IGNORE INTO `category_characteristic_assignments` (`category_id`, `characteristic_id`, `is_required`, `sort_order`)
                    SELECT pc.id, cc.id, CASE WHEN cc.name IN ('serial_number') THEN 1 ELSE 0 END, cc.sort_order
                    FROM product_categories pc CROSS JOIN category_characteristics cc
                    WHERE LOWER(pc.name) = 'wearables'
                    AND cc.name IN ('color', 'serial_number', 'manufacturer', 'warranty_months', 'item_condition')");

                $newCount = $pdo->query("SELECT COUNT(*) FROM category_characteristic_assignments")->fetchColumn();
                echo "  ✅ Created $newCount characteristic assignments\n";
            } else {
                echo "  ⏭️  Assignments already exist ($assignCount records)\n";
            }

            // ============================================================
            // MIGRATION 8: Change condition column from ENUM to VARCHAR
            // ============================================================
            $condCol = $pdo->query("SHOW COLUMNS FROM product_specific_list WHERE Field = 'condition'")->fetch();
            if ($condCol && stripos($condCol['Type'], 'enum') !== false) {
                $pdo->exec("ALTER TABLE `product_specific_list` MODIFY COLUMN `condition` VARCHAR(50) DEFAULT 'New'");
                echo "  ✅ Changed condition from ENUM to VARCHAR(50)\n";
            } else {
                echo "  ⏭️  condition column already VARCHAR\n";
            }

            echo "  🎉 All migrations applied!\n\n";

        } catch (PDOException $e) {
            echo "  ❌ Error: " . $e->getMessage() . "\n\n";
        }
    }
    
    echo "✅ ALL MIGRATIONS COMPLETE\n";
    
} catch (Exception $e) {
    echo "❌ FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
