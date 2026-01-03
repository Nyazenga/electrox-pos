ALTER TABLE products ADD COLUMN source enum('manual','bulk_upload') DEFAULT 'manual' AFTER created_by;
ALTER TABLE products ADD KEY idx_source (source);
UPDATE products SET source = 'manual' WHERE source IS NULL OR source = '';

