-- Add tax_id column to product_categories table to store default tax for category
ALTER TABLE `product_categories` 
ADD COLUMN `tax_id` INT(11) DEFAULT NULL AFTER `description`,
ADD KEY `idx_tax_id` (`tax_id`);

-- tax_id will store the taxID value from the applicable_taxes JSON array in fiscal_config
-- This allows categories to have a default tax that applies to all products in that category
-- Products can override this with their own tax_id

