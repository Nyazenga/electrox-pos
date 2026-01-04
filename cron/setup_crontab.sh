#!/bin/bash
# Setup crontab entries for ElectroX POS cron jobs

CRON_DIR="/var/www/electro-pos/cron"
PHP_BIN="/usr/bin/php"

# Backup existing crontab
crontab -l > /tmp/crontab_backup_$(date +%Y%m%d_%H%M%S).txt 2>/dev/null || true

# Remove old entries for these scripts
crontab -l 2>/dev/null | grep -v "close_fiscal_day.php\|open_fiscal_day.php\|expiry_notifications.php\|low_stock_notifications.php" > /tmp/crontab_new.txt 2>/dev/null || true

# Add new cron entries
cat >> /tmp/crontab_new.txt <<EOF

# ElectroX POS Cron Jobs
# Close Fiscal Day - Daily at 9:00 PM (2100)
0 21 * * * cd $CRON_DIR/.. && $PHP_BIN cron/close_fiscal_day.php >> /var/log/electrox_cron_close.log 2>&1

# Open Fiscal Day - Daily at 4:00 AM (0400)
0 4 * * * cd $CRON_DIR/.. && $PHP_BIN cron/open_fiscal_day.php >> /var/log/electrox_cron_open.log 2>&1

# Expiry Notifications - Daily at 8:00 AM
0 8 * * * cd $CRON_DIR/.. && $PHP_BIN cron/expiry_notifications.php >> /var/log/electrox_cron_expiry.log 2>&1

# Low Stock Notifications - Daily at 8:30 AM
30 8 * * * cd $CRON_DIR/.. && $PHP_BIN cron/low_stock_notifications.php >> /var/log/electrox_cron_lowstock.log 2>&1
EOF

# Install new crontab
crontab /tmp/crontab_new.txt

# Verify
echo "✅ Crontab entries added:"
crontab -l | grep -A 1 "ElectroX POS"

