-- Add source column to products table to track how products were created
-- This version uses a simpler approach that works with all MySQL versions

-- Check if column exists, if not add it
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'products' 
AND COLUMN_NAME = 'source';

SET @sql = IF(@col_exists = 0,
    CONCAT('ALTER TABLE `products` ADD COLUMN `source` enum(''manual'',''bulk_upload'') DEFAULT ''manual'' AFTER `created_by`'),
    'SELECT ''Column source already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if index exists, if not add it
SELECT COUNT(*) INTO @idx_exists 
FROM information_schema.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'products' 
AND INDEX_NAME = 'idx_source';

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `products` ADD KEY `idx_source` (`source`)',
    'SELECT ''Index idx_source already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing products to have source = 'manual' (default)
UPDATE `products` SET `source` = 'manual' WHERE `source` IS NULL OR `source` = '';

