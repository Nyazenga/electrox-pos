-- Migration: Serial Number, IMEI, and Batch Number Tracking System
-- This migration converts the product tracking system from single fields to per-instance tracking
-- Date: 2026-01-02

-- Step 1: Add boolean flags to products table
ALTER TABLE `products` 
ADD COLUMN `has_serial_number` tinyint(1) DEFAULT 0 AFTER `sim_configuration`,
ADD COLUMN `has_imei` tinyint(1) DEFAULT 0 AFTER `has_serial_number`,
ADD COLUMN `has_batch_number` tinyint(1) DEFAULT 0 AFTER `has_imei`;

-- Step 2: Create product_identifiers table to store serial numbers, IMEIs, and batch numbers per product instance
CREATE TABLE IF NOT EXISTS `product_identifiers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `identifier_type` enum('serial_number','imei','batch_number') NOT NULL,
  `identifier_value` varchar(255) NOT NULL,
  `status` enum('available','sold','returned','damaged','transferred') DEFAULT 'available',
  `sale_item_id` int(11) DEFAULT NULL COMMENT 'Links to sale_items when sold',
  `refund_item_id` int(11) DEFAULT NULL COMMENT 'Links to refund_items when returned',
  `grn_item_id` int(11) DEFAULT NULL COMMENT 'Links to grn_items when received',
  `stock_movement_id` int(11) DEFAULT NULL COMMENT 'Links to stock_movements when adjusted',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product_identifier` (`product_id`, `identifier_type`, `identifier_value`, `branch_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_identifier_type` (`identifier_type`),
  KEY `idx_status` (`status`),
  KEY `idx_sale_item_id` (`sale_item_id`),
  KEY `idx_refund_item_id` (`refund_item_id`),
  KEY `idx_grn_item_id` (`grn_item_id`),
  KEY `idx_identifier_value` (`identifier_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 3: Add identifier tracking fields to sale_items table
ALTER TABLE `sale_items` 
ADD COLUMN `identifiers_json` text DEFAULT NULL COMMENT 'JSON array of identifier IDs that were sold with this item' AFTER `total_price`;

-- Step 4: Add identifier tracking fields to refund_items table
ALTER TABLE `refund_items` 
ADD COLUMN `identifiers_json` text DEFAULT NULL COMMENT 'JSON array of identifier IDs that are being returned' AFTER `total_price`;

-- Step 5: Migrate existing data (if any products have serial_number, imei, or batch_number)
-- This will set the boolean flags and create initial identifiers
-- Note: This assumes existing products with these fields have quantity_in_stock = 1
-- For products with quantity > 1, we'll only migrate the first instance

-- Set has_serial_number flag for products with existing serial_number
UPDATE `products` 
SET `has_serial_number` = 1 
WHERE `serial_number` IS NOT NULL AND `serial_number` != '';

-- Set has_imei flag for products with existing imei
UPDATE `products` 
SET `has_imei` = 1 
WHERE `imei` IS NOT NULL AND `imei` != '';

-- Set has_batch_number flag for products with existing batch_number
UPDATE `products` 
SET `has_batch_number` = 1 
WHERE `batch_number` IS NOT NULL AND `batch_number` != '';

-- Migrate existing serial numbers to product_identifiers (only for products with quantity = 1)
INSERT INTO `product_identifiers` (`product_id`, `branch_id`, `identifier_type`, `identifier_value`, `status`)
SELECT 
    `id` as `product_id`,
    `branch_id`,
    'serial_number' as `identifier_type`,
    `serial_number` as `identifier_value`,
    'available' as `status`
FROM `products`
WHERE `serial_number` IS NOT NULL 
  AND `serial_number` != '' 
  AND `quantity_in_stock` = 1
  AND NOT EXISTS (
    SELECT 1 FROM `product_identifiers` pi 
    WHERE pi.product_id = products.id 
    AND pi.identifier_type = 'serial_number' 
    AND pi.identifier_value = products.serial_number
  );

-- Migrate existing IMEIs to product_identifiers (only for products with quantity = 1)
INSERT INTO `product_identifiers` (`product_id`, `branch_id`, `identifier_type`, `identifier_value`, `status`)
SELECT 
    `id` as `product_id`,
    `branch_id`,
    'imei' as `identifier_type`,
    `imei` as `identifier_value`,
    'available' as `status`
FROM `products`
WHERE `imei` IS NOT NULL 
  AND `imei` != '' 
  AND `quantity_in_stock` = 1
  AND NOT EXISTS (
    SELECT 1 FROM `product_identifiers` pi 
    WHERE pi.product_id = products.id 
    AND pi.identifier_type = 'imei' 
    AND pi.identifier_value = products.imei
  );

-- Migrate existing batch numbers to product_identifiers (only for products with quantity = 1)
INSERT INTO `product_identifiers` (`product_id`, `branch_id`, `identifier_type`, `identifier_value`, `status`)
SELECT 
    `id` as `product_id`,
    `branch_id`,
    'batch_number' as `identifier_type`,
    `batch_number` as `identifier_value`,
    'available' as `status`
FROM `products`
WHERE `batch_number` IS NOT NULL 
  AND `batch_number` != '' 
  AND `quantity_in_stock` = 1
  AND NOT EXISTS (
    SELECT 1 FROM `product_identifiers` pi 
    WHERE pi.product_id = products.id 
    AND pi.identifier_type = 'batch_number' 
    AND pi.identifier_value = products.batch_number
  );

-- Step 6: Remove old columns from products table (commented out for safety - uncomment after verifying migration)
-- ALTER TABLE `products` DROP COLUMN `serial_number`;
-- ALTER TABLE `products` DROP COLUMN `imei`;
-- ALTER TABLE `products` DROP COLUMN `batch_number`;

-- Note: The old columns are kept for now to ensure backward compatibility
-- They can be removed in a future migration after confirming everything works

