UPDATE products SET source = 'manual' WHERE source IS NULL;
UPDATE products SET source = 'manual' WHERE source = '';

