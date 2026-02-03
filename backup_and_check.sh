#!/bin/bash
# Backup fiscal data and save output to file
php /tmp/backup_fiscal_quick.php > /tmp/backup_result.txt 2>&1
echo "Backup completed. Check /tmp/backup_result.txt for details."
cat /tmp/backup_result.txt
ls -lh /tmp/fiscal_backup_*.sql 2>/dev/null | tail -1
