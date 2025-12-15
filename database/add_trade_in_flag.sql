-- Add is_trade_in flag to products table
ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `is_trade_in` tinyint(1) DEFAULT 0 AFTER `trade_in_eligible`,
ADD KEY IF NOT EXISTS `idx_is_trade_in` (`is_trade_in`);


