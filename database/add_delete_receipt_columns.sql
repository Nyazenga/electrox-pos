-- Add deleted_at and deleted_by columns to sales table for soft delete functionality

-- Check and add deleted_at column
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'sales' 
                   AND COLUMN_NAME = 'deleted_at');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE sales ADD COLUMN deleted_at DATETIME NULL AFTER updated_at',
    'SELECT "deleted_at column already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add deleted_by column
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'sales' 
                   AND COLUMN_NAME = 'deleted_by');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE sales ADD COLUMN deleted_by INT(11) NULL AFTER deleted_at',
    'SELECT "deleted_by column already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add index
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'sales' 
                   AND INDEX_NAME = 'idx_deleted_at');

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE sales ADD KEY idx_deleted_at (deleted_at)',
    'SELECT "idx_deleted_at index already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

