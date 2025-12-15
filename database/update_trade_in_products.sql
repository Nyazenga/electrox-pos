-- Add is_trade_in column to products table if it doesn't exist
ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `is_trade_in` tinyint(1) DEFAULT 0,
ADD KEY IF NOT EXISTS `idx_is_trade_in` (`is_trade_in`);

-- Update existing products that came from trade-ins
-- Method 1: Products with stock movements of type 'Trade-In'
UPDATE products p
INNER JOIN stock_movements sm ON p.id = sm.product_id
SET p.is_trade_in = 1
WHERE sm.movement_type = 'Trade-In' 
AND (p.is_trade_in = 0 OR p.is_trade_in IS NULL);

-- Method 2: Products with description starting with 'Trade-in device:' or 'Trade-In Device'
UPDATE products
SET is_trade_in = 1
WHERE (description LIKE 'Trade-in device:%' 
   OR description LIKE 'Trade-In Device%'
   OR description LIKE 'Trade-In:%')
AND (is_trade_in = 0 OR is_trade_in IS NULL);

-- Method 3: Products linked to sale_items with 'Trade-In:' prefix
UPDATE products p
INNER JOIN sale_items si ON p.id = si.product_id
SET p.is_trade_in = 1
WHERE si.product_name LIKE 'Trade-In:%'
AND (p.is_trade_in = 0 OR p.is_trade_in IS NULL);


