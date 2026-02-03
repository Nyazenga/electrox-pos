# Manual Fiscalization Data Backup Instructions

Since automated plink commands are getting stuck, please run these commands **manually on the server**:

## Step 1: Run Backup on Server

SSH into the server and run:

```bash
php /tmp/backup_fiscal_complete.php
```

This will create a backup file like: `/tmp/fiscal_backup_20260203_143022.sql`

## Step 2: Download Backup File

On your local Windows machine, run:

```cmd
cd c:\xampp\htdocs\electrox-pos
pscp.exe -pw "GRCAdmin123/" root@31.97.199.82:/tmp/fiscal_backup_*.sql .
```

Or if you know the exact filename:
```cmd
pscp.exe -pw "GRCAdmin123/" root@31.97.199.82:/tmp/fiscal_backup_20260203_143022.sql fiscal_data_backup.sql
```

## Step 3: Restore to Localhost

On your local machine, run:

```cmd
cd c:\xampp\htdocs\electrox-pos
php restore_fiscal_simple.php fiscal_data_backup.sql
```

Or if you renamed it:
```cmd
php restore_fiscal_simple.php fiscal_data_backup.sql
```

## What Gets Backed Up

- `fiscal_devices` - Device registration, certificates, private keys
- `fiscal_config` - Fiscal configuration (taxes, QR URL, etc.)
- `fiscal_days` - Fiscal day statuses
- `fiscal_receipts` - Receipt data
- `fiscal_receipt_lines` - Receipt line items
- `fiscal_receipt_taxes` - Receipt taxes
- `fiscal_receipt_payments` - Receipt payments
- `fiscal_counters` - Receipt counters

## Alternative: Use mysqldump

If PHP script doesn't work, use mysqldump directly on server:

```bash
mysqldump -u grcadmin -p'GRCAdmin123/' electrox_primary \
  fiscal_devices fiscal_config fiscal_days fiscal_receipts \
  fiscal_receipt_lines fiscal_receipt_taxes fiscal_receipt_payments \
  fiscal_counters > /tmp/fiscal_backup.sql
```

Then download and restore as above.
