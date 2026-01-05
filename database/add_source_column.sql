-- ============================================
-- ADD SOURCE COLUMN TO PRODUCTS TABLE
-- ============================================
-- Run this command on the live server to add the missing 'source' column
-- This column tracks whether products were created manually or via bulk upload
--
-- SAFE TO RUN: Will fail gracefully if column already exists
-- ============================================

-- For MySQL 5.7+ (IF NOT EXISTS syntax)
ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`;

-- If IF NOT EXISTS is not supported, use this instead:
-- ALTER TABLE `products` 
-- ADD COLUMN `source` enum('manual','bulk_upload') DEFAULT 'manual' AFTER `created_by`;

-- Add index for better query performance
ALTER TABLE `products` 
ADD KEY IF NOT EXISTS `idx_source` (`source`);

-- Update existing products to have 'manual' as source (if column was just added)
UPDATE `products` 
SET `source` = 'manual' 
WHERE `source` IS NULL OR `source` = '';

-- Verify the column was added
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, COLUMN_DEFAULT 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'products' 
  AND COLUMN_NAME = 'source';
