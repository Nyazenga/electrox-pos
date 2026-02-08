#!/bin/bash
# Clear cash management data on live server

DB_HOST="localhost"
DB_USER="grcadmin"
DB_PASS="GRCAdmin123/"
DB_NAME="electrox_primary"

echo "=========================================="
echo "Clearing Cash Management Data (Live Server)"
echo "=========================================="
echo ""

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;

-- Clear drawer_transactions
TRUNCATE TABLE drawer_transactions;
ALTER TABLE drawer_transactions AUTO_INCREMENT = 1;

-- Clear shifts
TRUNCATE TABLE shifts;
ALTER TABLE shifts AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS=1;

-- Verify
SELECT 
    (SELECT COUNT(*) FROM drawer_transactions) as drawer_transactions_count,
    (SELECT COUNT(*) FROM shifts) as shifts_count;
SQL

echo ""
echo "=========================================="
echo "✓ Cash Management Data Cleared (Live)"
echo "=========================================="
