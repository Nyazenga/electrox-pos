-- Add source column to products table if it doesn't exist
-- This is safe to run multiple times (will fail gracefully if column already exists)

ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`;

-- Add index if it doesn't exist
ALTER TABLE `products` 
ADD KEY IF NOT EXISTS `idx_source` (`source`);

-- Update existing products to have 'manual' as source if NULL
UPDATE `products` 
SET `source` = 'manual' 
WHERE `source` IS NULL OR `source` = '';

