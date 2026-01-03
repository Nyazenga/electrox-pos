-- Add source column to products table to track how products were created
ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`,
ADD KEY IF NOT EXISTS `idx_source` (`source`);

-- Update existing products to have source = 'manual' (default)
UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = '';

