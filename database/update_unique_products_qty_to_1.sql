-- Update all products with serial numbers, IMEI, or in smartphone/laptop/tablet categories to have qty=1
-- This script enforces the rule that unique products must always have quantity = 1

-- Step 1: Update products that have serial_number or imei populated
UPDATE products 
SET quantity_in_stock = 1 
WHERE (serial_number IS NOT NULL AND serial_number != '') 
   OR (imei IS NOT NULL AND imei != '');

-- Step 2: Update products in smartphone, phone, laptop, or tablet categories
UPDATE products p
INNER JOIN product_categories pc ON p.category_id = pc.id
SET p.quantity_in_stock = 1
WHERE LOWER(pc.name) LIKE '%smartphone%' 
   OR LOWER(pc.name) LIKE '%phone%'
   OR LOWER(pc.name) LIKE '%laptop%'
   OR LOWER(pc.name) LIKE '%tablet%';

-- Step 3: Show summary of updated products
SELECT 
    COUNT(*) as total_unique_products,
    SUM(CASE WHEN serial_number IS NOT NULL AND serial_number != '' THEN 1 ELSE 0 END) as with_serial,
    SUM(CASE WHEN imei IS NOT NULL AND imei != '' THEN 1 ELSE 0 END) as with_imei,
    SUM(CASE WHEN pc.name IS NOT NULL AND (
        LOWER(pc.name) LIKE '%smartphone%' 
        OR LOWER(pc.name) LIKE '%phone%'
        OR LOWER(pc.name) LIKE '%laptop%'
        OR LOWER(pc.name) LIKE '%tablet%'
    ) THEN 1 ELSE 0 END) as in_unique_categories
FROM products p
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE (p.serial_number IS NOT NULL AND p.serial_number != '') 
   OR (p.imei IS NOT NULL AND p.imei != '')
   OR (pc.name IS NOT NULL AND (
       LOWER(pc.name) LIKE '%smartphone%' 
       OR LOWER(pc.name) LIKE '%phone%'
       OR LOWER(pc.name) LIKE '%laptop%'
       OR LOWER(pc.name) LIKE '%tablet%'
   ));

