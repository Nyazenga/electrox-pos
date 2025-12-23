-- Add tax_id column to products table to store selected tax ID from fiscal_config applicable_taxes
ALTER TABLE `products` 
ADD COLUMN `tax_id` INT(11) DEFAULT NULL AFTER `branch_id`,
ADD KEY `idx_tax_id` (`tax_id`);

-- tax_id will store the taxID value from the applicable_taxes JSON array in fiscal_config
-- This allows products to have their specific tax assigned at creation time

