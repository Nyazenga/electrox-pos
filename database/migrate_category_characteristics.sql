-- Migration: Category Characteristics System
-- Enables categories to be flagged as "specific/unique" and allows
-- configurable characteristics per category with full CRUD
-- Date: 2026-03-09

-- Step 1: Add is_specific flag to product_categories
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'product_categories' 
AND COLUMN_NAME = 'is_specific';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `product_categories` ADD COLUMN `is_specific` tinyint(1) DEFAULT 0 COMMENT ''1 if products in this category are unique/specific items requiring individual tracking'' AFTER `tax_id`',
    'SELECT ''Column is_specific already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Create master characteristics table
CREATE TABLE IF NOT EXISTS `category_characteristics` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL COMMENT 'Internal name/key (e.g. color, serial_number)',
    `label` varchar(150) NOT NULL COMMENT 'Display label (e.g. Color, Serial Number)',
    `field_type` enum('text','number','select','color','boolean','textarea','date') NOT NULL DEFAULT 'text',
    `options` text DEFAULT NULL COMMENT 'JSON array of options for select field type',
    `is_system` tinyint(1) DEFAULT 0 COMMENT '1 if this is a built-in characteristic mapped to product_specific_list columns',
    `system_column` varchar(100) DEFAULT NULL COMMENT 'Maps to column in product_specific_list (for system characteristics)',
    `description` varchar(255) DEFAULT NULL,
    `sort_order` int(11) DEFAULT 0,
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 3: Create junction table for category-characteristic assignments
CREATE TABLE IF NOT EXISTS `category_characteristic_assignments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 4: Create table for custom characteristic values on specific list items
CREATE TABLE IF NOT EXISTS `product_characteristic_values` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `specific_list_id` int(11) NOT NULL COMMENT 'References product_specific_list.id',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 5: Seed system characteristics (mapped to existing product_specific_list columns)
INSERT IGNORE INTO `category_characteristics` (`name`, `label`, `field_type`, `options`, `is_system`, `system_column`, `description`, `sort_order`) VALUES
('color', 'Color', 'text', NULL, 1, 'color', 'Product color', 1),
('storage', 'Storage / Memory Size', 'select', '["16GB","32GB","64GB","128GB","256GB","512GB","1TB","2TB"]', 1, 'storage', 'Storage capacity', 2),
('serial_number', 'Serial Number', 'text', NULL, 1, 'serial_number', 'Unique serial number', 3),
('imei', 'IMEI', 'text', NULL, 1, 'imei', 'International Mobile Equipment Identity', 4),
('sim_configuration', 'SIM Configuration', 'select', '["Single SIM","Dual SIM","eSIM","Dual SIM + eSIM"]', 1, 'sim_configuration', 'SIM card configuration', 5),
('battery_health', 'Battery Health (%)', 'number', NULL, 1, 'battery_health', 'Battery health percentage (0-100)', 6),
('manufacturer', 'Manufacturer', 'text', NULL, 1, 'manufacturer', 'Product manufacturer', 7),
('warranty_months', 'Warranty (Months)', 'number', NULL, 1, 'warranty_months', 'Warranty period in months', 8),
('warranty_terms', 'Warranty Terms', 'textarea', NULL, 1, 'warranty_terms', 'Warranty terms and conditions', 9),
('item_condition', 'Condition', 'select', '["New","Refurbished","Used"]', 1, 'condition', 'Product condition', 10),
('trade_in_eligible', 'Trade-in Eligible', 'boolean', NULL, 1, 'trade_in_eligible', 'Whether the item is eligible for trade-in', 11);

-- Step 6: Update existing specific categories to have is_specific = 1
UPDATE `product_categories` SET `is_specific` = 1 
WHERE LOWER(`name`) IN ('smartphones', 'laptops', 'tablets', 'gaming', 'wearables');

-- Step 7: Auto-assign characteristics to existing specific categories
-- Smartphones: all characteristics
INSERT IGNORE INTO `category_characteristic_assignments` (`category_id`, `characteristic_id`, `is_required`, `sort_order`)
SELECT pc.id, cc.id, 
    CASE WHEN cc.name IN ('serial_number', 'color') THEN 1 ELSE 0 END,
    cc.sort_order
FROM product_categories pc
CROSS JOIN category_characteristics cc
WHERE LOWER(pc.name) = 'smartphones'
AND cc.name IN ('color', 'storage', 'serial_number', 'imei', 'sim_configuration', 'battery_health', 'manufacturer', 'warranty_months', 'warranty_terms', 'item_condition', 'trade_in_eligible');

-- Laptops: relevant characteristics  
INSERT IGNORE INTO `category_characteristic_assignments` (`category_id`, `characteristic_id`, `is_required`, `sort_order`)
SELECT pc.id, cc.id,
    CASE WHEN cc.name IN ('serial_number') THEN 1 ELSE 0 END,
    cc.sort_order
FROM product_categories pc
CROSS JOIN category_characteristics cc
WHERE LOWER(pc.name) = 'laptops'
AND cc.name IN ('color', 'storage', 'serial_number', 'manufacturer', 'warranty_months', 'warranty_terms', 'item_condition', 'trade_in_eligible');

-- Tablets: relevant characteristics
INSERT IGNORE INTO `category_characteristic_assignments` (`category_id`, `characteristic_id`, `is_required`, `sort_order`)
SELECT pc.id, cc.id,
    CASE WHEN cc.name IN ('serial_number') THEN 1 ELSE 0 END,
    cc.sort_order
FROM product_categories pc
CROSS JOIN category_characteristics cc
WHERE LOWER(pc.name) = 'tablets'
AND cc.name IN ('color', 'storage', 'serial_number', 'sim_configuration', 'battery_health', 'manufacturer', 'warranty_months', 'warranty_terms', 'item_condition', 'trade_in_eligible');

-- Gaming: relevant characteristics
INSERT IGNORE INTO `category_characteristic_assignments` (`category_id`, `characteristic_id`, `is_required`, `sort_order`)
SELECT pc.id, cc.id,
    CASE WHEN cc.name IN ('serial_number') THEN 1 ELSE 0 END,
    cc.sort_order
FROM product_categories pc
CROSS JOIN category_characteristics cc
WHERE LOWER(pc.name) = 'gaming'
AND cc.name IN ('color', 'storage', 'serial_number', 'manufacturer', 'warranty_months', 'item_condition');

-- Wearables: relevant characteristics
INSERT IGNORE INTO `category_characteristic_assignments` (`category_id`, `characteristic_id`, `is_required`, `sort_order`)
SELECT pc.id, cc.id,
    CASE WHEN cc.name IN ('serial_number') THEN 1 ELSE 0 END,
    cc.sort_order
FROM product_categories pc
CROSS JOIN category_characteristics cc
WHERE LOWER(pc.name) = 'wearables'
AND cc.name IN ('color', 'serial_number', 'manufacturer', 'warranty_months', 'item_condition');
