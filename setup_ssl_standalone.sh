#!/bin/bash
# SSL Certificate Generation using Standalone Mode
# This bypasses nginx config issues

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"

echo "=========================================="
echo "Generating SSL Certificate (Standalone Mode)"
echo "=========================================="

# Stop nginx temporarily
echo "Step 1: Stopping nginx temporarily..."
systemctl stop nginx

# Generate certificate using standalone mode
echo "Step 2: Generating SSL certificate..."
certbot certonly --standalone -d $DOMAIN -d $WWW_DOMAIN --non-interactive --agree-tos --email admin@electrox-pos.com

if [ $? -ne 0 ]; then
    echo "✗ Certificate generation failed!"
    systemctl start nginx
    exit 1
fi

echo "✓ SSL certificate generated successfully!"

# Start nginx
echo "Step 3: Starting nginx..."
systemctl start nginx

# Verify certificate
echo "Step 4: Verifying certificate..."
certbot certificates

echo ""
echo "=========================================="
echo "✓ SSL certificate ready!"
echo "=========================================="
echo ""
echo "Now update nginx config to use the certificate:"
echo "  /etc/letsencrypt/live/$DOMAIN/fullchain.pem"
echo "  /etc/letsencrypt/live/$DOMAIN/privkey.pem"
