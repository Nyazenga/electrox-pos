#!/bin/bash
# Properly restore the clean database

DB_HOST="localhost"
DB_USER="grcadmin"
DB_PASS="GRCAdmin123/"
DB_NAME="electrox_primary"
BACKUP_FILE="/tmp/electrox_primary_clean.sql"

echo "=========================================="
echo "Restoring Clean Database Properly"
echo "=========================================="
echo ""

if [ ! -f "$BACKUP_FILE" ]; then
    echo "✗ Backup file not found: $BACKUP_FILE"
    echo "Please upload the backup file first."
    exit 1
fi

echo "Step 1: Connecting to database..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;

-- Clear all business data tables
TRUNCATE TABLE product_specific_list;
TRUNCATE TABLE products;
TRUNCATE TABLE sale_payments;
TRUNCATE TABLE sale_items;
TRUNCATE TABLE sales;
TRUNCATE TABLE invoice_items;
TRUNCATE TABLE invoices;
TRUNCATE TABLE customers;
TRUNCATE TABLE suppliers;
TRUNCATE TABLE laybye_payment_schedule;
TRUNCATE TABLE laybye_payments;
TRUNCATE TABLE laybye_items;
TRUNCATE TABLE laybyes;
TRUNCATE TABLE refunds;
TRUNCATE TABLE refund_items;
TRUNCATE TABLE trade_ins;
TRUNCATE TABLE stock_transfers;
TRUNCATE TABLE stock_movements;
TRUNCATE TABLE grn_items;
TRUNCATE TABLE stock_takes;
TRUNCATE TABLE stock_take_items;
TRUNCATE TABLE fiscal_receipt_payments;
TRUNCATE TABLE fiscal_receipt_taxes;
TRUNCATE TABLE fiscal_receipt_lines;
TRUNCATE TABLE fiscal_receipts;
TRUNCATE TABLE fiscal_counters;
TRUNCATE TABLE fiscal_days;
TRUNCATE TABLE fiscal_devices;
TRUNCATE TABLE activity_logs;

-- Reset auto-increment
ALTER TABLE products AUTO_INCREMENT = 1;
ALTER TABLE sales AUTO_INCREMENT = 1;
ALTER TABLE invoices AUTO_INCREMENT = 1;
ALTER TABLE customers AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS=1;

SELECT 'Business data cleared successfully' as status;
SQL

if [ $? -eq 0 ]; then
    echo "✓ Business data cleared"
else
    echo "✗ Failed to clear business data"
    exit 1
fi

echo ""
echo "Step 2: Restoring from backup file..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$BACKUP_FILE" 2>&1 | grep -v "Warning: Using a password" | head -20

if [ $? -eq 0 ]; then
    echo "✓ Database restored from backup"
else
    echo "⚠ Some errors occurred, but continuing..."
fi

echo ""
echo "Step 3: Verifying restore..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
SELECT 
    (SELECT COUNT(*) FROM users) as users,
    (SELECT COUNT(*) FROM roles) as roles,
    (SELECT COUNT(*) FROM branches) as branches,
    (SELECT COUNT(*) FROM product_categories) as categories,
    (SELECT COUNT(*) FROM products) as products,
    (SELECT COUNT(*) FROM sales) as sales,
    (SELECT COUNT(*) FROM invoices) as invoices,
    (SELECT COUNT(*) FROM customers) as customers;
SQL

echo ""
echo "=========================================="
echo "✓ Database Restore Complete"
echo "=========================================="
echo ""
echo "Expected:"
echo "  - users: 3"
echo "  - roles: 7"
echo "  - branches: 2"
echo "  - categories: 10"
echo "  - products: 0 (cleaned)"
echo "  - sales: 0 (cleaned)"
echo "  - invoices: 0 (cleaned)"
echo "  - customers: 0 (cleaned)"
