-- Add source column to products table in electrox_primary
-- This script checks if the column exists and adds it if it doesn't

-- Check and add column
SET @dbname = 'electrox_primary';
SET @tablename = 'products';
SET @columnname = 'source';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1', -- Column exists, do nothing
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` enum(\'manual\',\'bulk_upload\') DEFAULT \'manual\' AFTER `created_by`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add index if it doesn't exist
SET @indexname = 'idx_source';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (index_name = @indexname)
  ) > 0,
  'SELECT 1', -- Index exists, do nothing
  CONCAT('ALTER TABLE `', @tablename, '` ADD KEY `', @indexname, '` (`', @columnname, '`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Update existing products
UPDATE products SET source = 'manual' WHERE source IS NULL OR source = '';

