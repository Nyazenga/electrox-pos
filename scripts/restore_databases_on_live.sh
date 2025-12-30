#!/bin/bash
# Script to restore full databases on live server
# Run this on the server: bash restore_databases_on_live.sh

DB_USER="grcadmin"
DB_PASS="GRCAdmin123/"
DEPLOY_PATH="/var/www/electro-pos"

echo "=== Restoring databases from full exports ==="

# Backup existing databases first
echo "Creating backups..."
mysqldump -u $DB_USER -p"$DB_PASS" electrox_primary > ${DEPLOY_PATH}/backup_electrox_primary_$(date +%Y%m%d_%H%M%S).sql
mysqldump -u $DB_USER -p"$DB_PASS" electrox_base > ${DEPLOY_PATH}/backup_electrox_base_$(date +%Y%m%d_%H%M%S).sql
echo "Backups created"

# Drop and recreate databases
echo "Dropping existing databases..."
mysql -u $DB_USER -p"$DB_PASS" -e "DROP DATABASE IF EXISTS electrox_primary;"
mysql -u $DB_USER -p"$DB_PASS" -e "DROP DATABASE IF EXISTS electrox_base;"
echo "Databases dropped"

# Create fresh databases
echo "Creating fresh databases..."
mysql -u $DB_USER -p"$DB_PASS" -e "CREATE DATABASE electrox_primary CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -u $DB_USER -p"$DB_PASS" -e "CREATE DATABASE electrox_base CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
echo "Databases created"

# Restore from full exports
echo "Restoring electrox_primary..."
mysql -u $DB_USER -p"$DB_PASS" electrox_primary < ${DEPLOY_PATH}/electrox_primary_full.sql
echo "electrox_primary restored"

echo "Restoring electrox_base..."
mysql -u $DB_USER -p"$DB_PASS" electrox_base < ${DEPLOY_PATH}/electrox_base_full.sql
echo "electrox_base restored"

echo "=== Database restoration completed ==="
echo "Verifying..."
mysql -u $DB_USER -p"$DB_PASS" electrox_primary -e "SELECT COUNT(*) as role_count FROM roles; SELECT COUNT(*) as user_count FROM users;"
mysql -u $DB_USER -p"$DB_PASS" electrox_base -e "SELECT COUNT(*) as tenant_count FROM tenants;"

