# Quick Fiscalization Backup & Restore

## The Problem
Plink execution commands are getting stuck, but file uploads (pscp) work fine.

## Solution: Run Commands Manually on Server

### Step 1: SSH into Server
```bash
ssh root@31.97.199.82
# Password: GRCAdmin123/
```

### Step 2: Run Backup Script
```bash
php /tmp/backup_fiscal_complete.php
```

This will output something like:
```
File: /tmp/fiscal_backup_20260203_143022.sql
Size: 125.50 KB
```

### Step 3: Download Backup (on your local machine)
```cmd
cd c:\xampp\htdocs\electrox-pos
pscp.exe -pw "GRCAdmin123/" root@31.97.199.82:/tmp/fiscal_backup_*.sql .
```

### Step 4: Restore to Localhost
```cmd
php restore_fiscal_simple.php fiscal_backup_20260203_143022.sql
```

## Alternative: Use mysqldump Directly

On the server, run:
```bash
mysqldump -u grcadmin -p'GRCAdmin123/' electrox_primary \
  fiscal_devices fiscal_config fiscal_days fiscal_receipts \
  fiscal_receipt_lines fiscal_receipt_taxes fiscal_receipt_payments \
  fiscal_counters > /tmp/fiscal_backup.sql
```

Then download and restore as above.
