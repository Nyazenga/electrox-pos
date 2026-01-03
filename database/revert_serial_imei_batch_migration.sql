-- Revert Migration: Serial Number, IMEI, and Batch Number Tracking System
-- This script reverts the changes made by migration_serial_imei_batch_tracking.sql
-- Date: 2026-01-02
-- 
-- IMPORTANT: This script removes:
-- 1. Boolean flags (has_serial_number, has_imei, has_batch_number) from products table
-- 2. product_identifiers table
-- 3. identifiers_json columns from sale_items and refund_items tables
--
-- It KEEPS the original columns:
-- - products.serial_number, products.imei, products.batch_number (original fields)
-- - grn_items.serial_numbers (original field)
-- - transfer_items.serial_numbers (original field)
-- - stock_movements.serial_number (original field)

-- Step 1: Drop the product_identifiers table if it exists
DROP TABLE IF EXISTS `product_identifiers`;

-- Step 2: Remove identifiers_json column from sale_items table if it exists
SET @dbname = DATABASE();
SET @tablename = 'sale_items';
SET @columnname = 'identifiers_json';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  CONCAT('ALTER TABLE `', @tablename, '` DROP COLUMN `', @columnname, '`;'),
  'SELECT 1'
));
PREPARE alterIfExists FROM @preparedStatement;
EXECUTE alterIfExists;
DEALLOCATE PREPARE alterIfExists;

-- Step 3: Remove identifiers_json column from refund_items table if it exists
SET @tablename = 'refund_items';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  CONCAT('ALTER TABLE `', @tablename, '` DROP COLUMN `', @columnname, '`;'),
  'SELECT 1'
));
PREPARE alterIfExists FROM @preparedStatement;
EXECUTE alterIfExists;
DEALLOCATE PREPARE alterIfExists;

-- Step 4: Remove has_serial_number column from products table if it exists
SET @tablename = 'products';
SET @columnname = 'has_serial_number';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  CONCAT('ALTER TABLE `', @tablename, '` DROP COLUMN `', @columnname, '`;'),
  'SELECT 1'
));
PREPARE alterIfExists FROM @preparedStatement;
EXECUTE alterIfExists;
DEALLOCATE PREPARE alterIfExists;

-- Step 5: Remove has_imei column from products table if it exists
SET @columnname = 'has_imei';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  CONCAT('ALTER TABLE `', @tablename, '` DROP COLUMN `', @columnname, '`;'),
  'SELECT 1'
));
PREPARE alterIfExists FROM @preparedStatement;
EXECUTE alterIfExists;
DEALLOCATE PREPARE alterIfExists;

-- Step 6: Remove has_batch_number column from products table if it exists
SET @columnname = 'has_batch_number';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  CONCAT('ALTER TABLE `', @tablename, '` DROP COLUMN `', @columnname, '`;'),
  'SELECT 1'
));
PREPARE alterIfExists FROM @preparedStatement;
EXECUTE alterIfExists;
DEALLOCATE PREPARE alterIfExists;

-- Note: The original columns (serial_number, imei, batch_number) in products table
-- are kept as they were part of the original schema

