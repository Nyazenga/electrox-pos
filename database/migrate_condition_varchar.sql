-- Migration: Change condition column from ENUM to VARCHAR
-- This allows users to define custom condition values via category characteristics
-- Date: 2026-03-09

ALTER TABLE `product_specific_list` MODIFY COLUMN `condition` VARCHAR(50) DEFAULT 'New';
