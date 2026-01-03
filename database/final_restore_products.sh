#!/bin/bash
# Final script to restore products: import without source, then add source column

cd /var/www/electro-pos/database

echo "Step 1: Dropping products table..."
mysql -u root -pGRCAdmin123/ electrox_primary -e "DROP TABLE IF EXISTS products;" 2>&1 | grep -v "Warning"

echo "Step 2: Creating table structure (extracting from products.sql without source column)..."
# Extract CREATE TABLE, remove source column line
sed -n '30,78p' products.sql | grep -v '`source`' | sed 's/,$//' | head -n -1 > /tmp/products_create.sql
echo ");" >> /tmp/products_create.sql
mysql -u root -pGRCAdmin123/ electrox_primary < /tmp/products_create.sql 2>&1 | grep -v "Warning"

echo "Step 3: Importing data (this step requires removing source from INSERT - using PHP script)..."
php -r "
require_once '/var/www/electro-pos/config.php';
\$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . PRIMARY_DB_NAME, DB_USER, DB_PASS);
\$lines = file('/var/www/electro-pos/database/products.sql');
\$insertLine = trim(\$lines[78]);
\$insertLine = preg_replace('/`, `source`/', '', \$insertLine);
\$insertSQL = \$insertLine . PHP_EOL;
for (\$i = 79; \$i <= 131; \$i++) {
    \$line = trim(\$lines[\$i]);
    if (preg_match('/^\(/', \$line)) {
        \$line = rtrim(\$line, ',;');
        // Parse and remove source value (36th value, after created_by)
        \$values = [];
        \$current = '';
        \$inQuotes = false;
        \$quoteChar = null;
        \$lineContent = trim(\$line, '()');
        for (\$j = 0; \$j < strlen(\$lineContent); \$j++) {
            \$char = \$lineContent[\$j];
            if (!\$inQuotes && (\$char === \"'\" || \$char === '\"')) {
                \$inQuotes = true;
                \$quoteChar = \$char;
                \$current .= \$char;
            } elseif (\$inQuotes && \$char === \$quoteChar && (\$j === 0 || \$lineContent[\$j-1] !== '\\\\')) {
                \$inQuotes = false;
                \$quoteChar = null;
                \$current .= \$char;
            } elseif (!\$inQuotes && \$char === ',') {
                \$values[] = trim(\$current);
                \$current = '';
            } else {
                \$current .= \$char;
            }
        }
        if (\$current) \$values[] = trim(\$current);
        if (count(\$values) > 36) array_splice(\$values, 36, 1);
        \$line = '(' . implode(', ', \$values) . ')';
        if (\$i < 131) \$line .= ',';
        else \$line = rtrim(\$line, ',') . ';';
        \$insertSQL .= \$line . PHP_EOL;
    }
}
\$pdo->exec(\$insertSQL);
echo 'Data imported' . PHP_EOL;
"

echo "Step 4: Adding source column..."
mysql -u root -pGRCAdmin123/ electrox_primary -e "ALTER TABLE products ADD COLUMN source enum('manual','bulk_upload') DEFAULT 'manual' AFTER created_by;" 2>&1 | grep -v "Warning"
mysql -u root -pGRCAdmin123/ electrox_primary -e "ALTER TABLE products ADD KEY idx_source (source);" 2>&1 | grep -v "Warning"
mysql -u root -pGRCAdmin123/ electrox_primary -e "UPDATE products SET source = 'manual' WHERE source IS NULL OR source = '';" 2>&1 | grep -v "Warning"

echo "Step 5: Verification..."
mysql -u root -pGRCAdmin123/ electrox_primary -e "SELECT COUNT(*) as total FROM products; SELECT source, COUNT(*) as count FROM products GROUP BY source;" 2>&1 | grep -v "Warning"

echo "Done!"

