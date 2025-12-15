-- Update trade_ins table to include new_product_id column if it doesn't exist
-- This column stores the ID of the product the customer is getting in exchange

ALTER TABLE `trade_ins` 
ADD COLUMN IF NOT EXISTS `new_product_id` int(11) DEFAULT NULL AFTER `final_valuation`,
ADD KEY IF NOT EXISTS `idx_new_product_id` (`new_product_id`);

