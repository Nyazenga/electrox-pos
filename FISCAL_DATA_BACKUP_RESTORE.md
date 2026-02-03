# Fiscalization Data Backup & Restore Guide

## Step 1: Backup from Live Server

**On the live server, run:**

```bash
# Make script executable
chmod +x /tmp/backup_fiscal_simple.sh

# Run backup
bash /tmp/backup_fiscal_simple.sh
```

This will create a backup file like: `/tmp/fiscal_data_backup_20260203_143022.sql`

## Step 2: Download Backup to Local Machine

**On your local Windows machine, run:**

```cmd
cd c:\xampp\htdocs\electrox-pos
pscp.exe -pw "GRCAdmin123/" root@31.97.199.82:/tmp/fiscal_data_backup_*.sql .
```

Or if you know the exact filename:
```cmd
pscp.exe -pw "GRCAdmin123/" root@31.97.199.82:/tmp/fiscal_data_backup_20260203_143022.sql fiscal_data_backup.sql
```

## Step 3: Restore to Localhost

**On your local machine, run:**

```cmd
cd c:\xampp\htdocs\electrox-pos
php restore_fiscal_simple.php fiscal_data_backup.sql
```

Or if you renamed it:
```cmd
php restore_fiscal_simple.php fiscal_data_backup.sql
```

## What Gets Backed Up

The following tables are backed up:
- `fiscal_devices` - Device registration, certificates, private keys
- `fiscal_config` - Fiscal configuration per branch/device
- `fiscal_days` - Fiscal day statuses and signatures
- `fiscal_receipts` - Receipt data
- `fiscal_receipt_lines` - Receipt line items
- `fiscal_receipt_taxes` - Receipt tax breakdown
- `fiscal_receipt_payments` - Receipt payment methods
- `fiscal_counters` - Receipt counters

## Alternative: Use PHP Backup Script

If mysqldump doesn't work, you can use the PHP backup script:

**On live server:**
```bash
php /tmp/backup_fiscal_data_live.php
```

Then download and restore as above.

## Verification

After restore, verify the data:
- Check `fiscal_devices` table has your devices
- Check `fiscal_config` has your configuration
- Check `fiscal_days` has current fiscal day status
- Certificates and private keys should be restored

## Notes

- This will **replace** existing fiscalization data in your local database
- Make sure your local `electrox_primary` database exists
- Branch IDs should match between live and local (or update them after restore)
