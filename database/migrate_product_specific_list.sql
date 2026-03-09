-- Migration: Product Specific List System
-- This migration creates a new system for tracking individual product instances
-- Date: 2026-01-03
-- 
-- IMPORTANT: This script should be run on production when ready
-- For testing, use root with empty password on localhost

-- Step 1: Create product_specific_list table to store individual product instances
CREATE TABLE IF NOT EXISTS `product_specific_list` (
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
  `condition` varchar(50) DEFAULT 'New',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 2: Add flag to products table to identify products that require specific instances
-- Check if column exists first (MySQL doesn't support IF NOT EXISTS for ALTER TABLE)
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'products' 
AND COLUMN_NAME = 'requires_specific_list';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `requires_specific_list` tinyint(1) DEFAULT 0 COMMENT ''1 if product requires product_specific_list entries (smartphones, laptops, tablets, gaming devices)''',
    'SELECT ''Column requires_specific_list already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 3: Add index for performance
SET @idx_exists = 0;
SELECT COUNT(*) INTO @idx_exists 
FROM information_schema.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'products' 
AND INDEX_NAME = 'idx_requires_specific_list';

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `products` ADD INDEX `idx_requires_specific_list` (`requires_specific_list`)',
    'SELECT ''Index idx_requires_specific_list already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 4: Migrate existing unique products to use the new system
-- This will identify products that should use product_specific_list
UPDATE `products` 
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
AND `status` = 'Active';

-- Step 5: For existing unique products with serial/IMEI, create product_specific_list entries
-- This migrates existing data
INSERT INTO `product_specific_list` (
    `product_id`, 
    `branch_id`, 
    `color`, 
    `storage`, 
    `sim_configuration`, 
    `serial_number`, 
    `imei`, 
    `battery_health`, 
    `manufacturer`, 
    `warranty_months`, 
    `warranty_terms`, 
    `condition`, 
    `trade_in_eligible`,
    `status`,
    `created_at`,
    `created_by`
)
SELECT 
    `id` as `product_id`,
    `branch_id`,
    `color`,
    `storage`,
    `sim_configuration`,
    `serial_number`,
    `imei`,
    `battery_health`,
    `manufacturer`,
    `warranty_months`,
    `warranty_terms`,
    `condition`,
    `trade_in_eligible`,
    CASE 
        WHEN `quantity_in_stock` > 0 THEN 'available'
        WHEN `status` = 'Inactive' THEN 'sold'
        ELSE 'available'
    END as `status`,
    `created_at`,
    `created_by`
FROM `products`
WHERE `requires_specific_list` = 1
AND (`serial_number` IS NOT NULL OR `imei` IS NOT NULL OR `quantity_in_stock` > 0)
AND NOT EXISTS (
    SELECT 1 FROM `product_specific_list` psl 
    WHERE psl.`product_id` = `products`.`id`
);

-- Step 6: Update products quantity_in_stock to match count of available product_specific_list entries
UPDATE `products` p
SET `quantity_in_stock` = (
    SELECT COUNT(*) 
    FROM `product_specific_list` psl 
    WHERE psl.`product_id` = p.`id` 
    AND psl.`status` = 'available'
)
WHERE p.`requires_specific_list` = 1;

-- Note: The following columns in products table are now deprecated but kept for backward compatibility:
-- color, storage, sim_configuration, serial_number, imei, battery_health, manufacturer, 
-- warranty_months, warranty_terms, condition, trade_in_eligible, is_trade_in
-- They should not be used for new products that require_specific_list = 1
