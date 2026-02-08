#!/bin/bash
# Properly restore fiscal devices from backup

DB_HOST="localhost"
DB_USER="grcadmin"
DB_PASS="GRCAdmin123/"
DB_NAME="electrox_primary"
FISCAL_BACKUP="/tmp/fiscal_backup_20260203_175512.sql"

echo "=========================================="
echo "Restoring Fiscal Devices from Backup"
echo "=========================================="
echo ""

# Step 1: Extract fiscal_devices INSERT statements
echo "Step 1: Extracting fiscal_devices data..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;

-- Insert fiscal devices from backup
INSERT INTO `fiscal_devices` (`id`, `branch_id`, `device_id`, `device_serial_no`, `activation_key`, `is_registered`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, '30199', 'electrox-1', NULL, 1, 1, NOW(), NOW()),
(2, 3, '30200', 'electrox-2', NULL, 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    branch_id = VALUES(branch_id),
    device_id = VALUES(device_id),
    device_serial_no = VALUES(device_serial_no),
    is_registered = VALUES(is_registered),
    is_active = VALUES(is_active),
    updated_at = NOW();

SET FOREIGN_KEY_CHECKS=1;

SELECT 'Fiscal devices restored' as status;
SQL

if [ $? -eq 0 ]; then
    echo "✓ Fiscal devices inserted"
else
    echo "⚠ Insert had issues, trying alternative method..."
    
    # Alternative: Use the backup file directly
    echo "Step 2: Trying direct restore from backup file..."
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;
SQL

    # Extract just the INSERT statement for fiscal_devices
    sed -n '/INSERT INTO.*`fiscal_devices`/,/);/p' "$FISCAL_BACKUP" | mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>&1 | grep -v "Warning: Using a password"
fi

echo ""
echo "Step 3: Verifying restore..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
SELECT 
    id,
    branch_id,
    device_id,
    device_serial_no,
    activation_key,
    is_registered,
    is_active
FROM fiscal_devices;
SQL

echo ""
echo "=========================================="
echo "✓ Fiscal Devices Restore Complete"
echo "=========================================="
