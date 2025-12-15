-- Add description column to invoice_items table for manual items
ALTER TABLE `invoice_items` 
ADD COLUMN IF NOT EXISTS `description` varchar(255) DEFAULT NULL AFTER `product_id`;


