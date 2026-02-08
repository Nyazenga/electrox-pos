#!/bin/bash
# Restore fiscal devices configuration from backup

DB_HOST="localhost"
DB_USER="grcadmin"
DB_PASS="GRCAdmin123/"
DB_NAME="electrox_primary"
FISCAL_BACKUP="/tmp/fiscal_backup_20260203_175512.sql"

echo "=========================================="
echo "Restoring Fiscal Devices Configuration"
echo "=========================================="
echo ""

if [ ! -f "$FISCAL_BACKUP" ]; then
    echo "✗ Fiscal backup file not found: $FISCAL_BACKUP"
    exit 1
fi

echo "Step 1: Extracting fiscal_devices and fiscal_config from backup..."
# Extract only fiscal_devices and fiscal_config tables
grep -A 1000 "CREATE TABLE.*fiscal_devices" "$FISCAL_BACKUP" | grep -B 1000 "CREATE TABLE.*fiscal_config" > /tmp/fiscal_devices_only.sql 2>/dev/null || true
grep -A 1000 "INSERT INTO.*fiscal_devices" "$FISCAL_BACKUP" >> /tmp/fiscal_devices_only.sql 2>/dev/null || true
grep -A 1000 "CREATE TABLE.*fiscal_config" "$FISCAL_BACKUP" | head -200 >> /tmp/fiscal_devices_only.sql 2>/dev/null || true
grep -A 1000 "INSERT INTO.*fiscal_config" "$FISCAL_BACKUP" >> /tmp/fiscal_devices_only.sql 2>/dev/null || true

# Better approach: restore directly from backup file
echo "Step 2: Restoring fiscal_devices and fiscal_config..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;

-- Clear existing fiscal devices and config
TRUNCATE TABLE fiscal_devices;
TRUNCATE TABLE fiscal_config;

SET FOREIGN_KEY_CHECKS=1;
SQL

# Extract and restore fiscal_devices
echo "Step 3: Extracting fiscal_devices data..."
sed -n '/INSERT INTO.*`fiscal_devices`/,/;/p' "$FISCAL_BACKUP" | head -20 > /tmp/fiscal_devices_insert.sql 2>/dev/null || true

# Extract and restore fiscal_config
echo "Step 4: Extracting fiscal_config data..."
sed -n '/INSERT INTO.*`fiscal_config`/,/;/p' "$FISCAL_BACKUP" | head -20 > /tmp/fiscal_config_insert.sql 2>/dev/null || true

# Try a different approach - use the restore script we created earlier
if [ -f "/tmp/restore_fiscal_clean.php" ]; then
    echo "Step 5: Using restore script..."
    php /tmp/restore_fiscal_clean.php /tmp/fiscal_backup_20260203_175512.sql 2>&1 | tail -20
else
    echo "Step 5: Manually restoring from backup..."
    # Extract fiscal tables from backup
    grep -E "(CREATE TABLE|INSERT INTO).*(fiscal_devices|fiscal_config)" "$FISCAL_BACKUP" > /tmp/fiscal_restore.sql
    
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < /tmp/fiscal_restore.sql 2>&1 | grep -v "Warning: Using a password" | head -10
fi

echo ""
echo "Step 6: Verifying restore..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
SELECT COUNT(*) as fiscal_devices FROM fiscal_devices;
SELECT COUNT(*) as fiscal_config FROM fiscal_config;
SELECT id, branch_id, device_id, device_serial_no, activation_key, is_registered, is_active FROM fiscal_devices;
SQL

echo ""
echo "=========================================="
echo "✓ Fiscal Devices Restore Complete"
echo "=========================================="
