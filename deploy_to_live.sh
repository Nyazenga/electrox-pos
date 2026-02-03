#!/bin/bash
# Complete Deployment Script for Live Server
# This script handles database restore, nginx setup, SSL, and code deployment

set -e  # Exit on error

SERVER_IP="31.97.199.82"
SERVER_USER="root"
SERVER_PASS="GRCAdmin123/"
DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"
BACKUP_FILE="electrox_primary_clean_20260203_191727.sql"

echo "=========================================="
echo "ELECTROX-POS Live Server Deployment"
echo "=========================================="
echo ""
echo "This script will:"
echo "1. Upload and restore cleaned database"
echo "2. Setup nginx configuration"
echo "3. Generate SSL certificate"
echo "4. Update domain references"
echo ""
read -p "Continue? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    exit 1
fi

# Step 1: Upload database backup
echo ""
echo "Step 1: Uploading database backup..."
pscp.exe -pw "$SERVER_PASS" "$BACKUP_FILE" "$SERVER_USER@$SERVER_IP:/tmp/electrox_primary_clean.sql"
if [ $? -ne 0 ]; then
    echo "✗ Database upload failed!"
    exit 1
fi
echo "✓ Database backup uploaded"

# Step 2: Upload restore script
echo ""
echo "Step 2: Uploading restore script..."
pscp.exe -pw "$SERVER_PASS" "restore_on_server.php" "$SERVER_USER@$SERVER_IP:/tmp/restore_db.php"
echo "✓ Restore script uploaded"

# Step 3: Execute database restore
echo ""
echo "Step 3: Restoring database on server..."
plink.exe -ssh -pw "$SERVER_PASS" "$SERVER_USER@$SERVER_IP" "php /tmp/restore_db.php"
if [ $? -ne 0 ]; then
    echo "✗ Database restore failed!"
    exit 1
fi
echo "✓ Database restored"

# Step 4: Upload nginx setup script
echo ""
echo "Step 4: Uploading nginx setup script..."
pscp.exe -pw "$SERVER_PASS" "scripts/setup_nginx_ssl.sh" "$SERVER_USER@$SERVER_IP:/tmp/setup_nginx_ssl.sh"
plink.exe -ssh -pw "$SERVER_PASS" "$SERVER_USER@$SERVER_IP" "chmod +x /tmp/setup_nginx_ssl.sh"
echo "✓ Nginx setup script uploaded"

# Step 5: Execute nginx and SSL setup
echo ""
echo "Step 5: Setting up nginx and SSL certificate..."
echo "NOTE: This may take a few minutes..."
plink.exe -ssh -pw "$SERVER_PASS" "$SERVER_USER@$SERVER_IP" "bash /tmp/setup_nginx_ssl.sh"
if [ $? -ne 0 ]; then
    echo "⚠ Nginx setup had issues. Check manually."
fi

echo ""
echo "=========================================="
echo "✓ Deployment completed!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Push code to git repository"
echo "2. Verify site: https://$DOMAIN"
echo "3. Test login: https://$DOMAIN/login.php"
echo ""
echo "If nginx setup failed, run manually on server:"
echo "  ssh $SERVER_USER@$SERVER_IP"
echo "  bash /tmp/setup_nginx_ssl.sh"
