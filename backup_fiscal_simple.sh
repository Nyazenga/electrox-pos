#!/bin/bash
# Simple backup script using mysqldump
# Run on live server

DB_USER="grcadmin"
DB_PASS="GRCAdmin123/"
DB_NAME="electrox_primary"
BACKUP_FILE="/tmp/fiscal_data_backup_$(date +%Y%m%d_%H%M%S).sql"

echo "Backing up fiscalization data from $DB_NAME..."
echo "================================================"

# Tables to backup
TABLES="fiscal_devices fiscal_config fiscal_days fiscal_receipts fiscal_receipt_lines fiscal_receipt_taxes fiscal_receipt_payments fiscal_counters"

# Create backup
mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" $TABLES > "$BACKUP_FILE" 2>&1

if [ $? -eq 0 ]; then
    FILE_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    echo ""
    echo "✓ Backup completed successfully!"
    echo "File: $BACKUP_FILE"
    echo "Size: $FILE_SIZE"
    echo ""
    echo "To download to local machine, run:"
    echo "  pscp.exe -pw \"GRCAdmin123/\" root@31.97.199.82:$BACKUP_FILE ."
else
    echo "✗ Backup failed!"
    exit 1
fi
