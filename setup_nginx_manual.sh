#!/bin/bash
# Manual Nginx and SSL Setup for electrox-pos.com
# Run this directly on the server via SSH

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"
WEB_ROOT="/var/www/electrox-pos"
NGINX_CONF="/etc/nginx/sites-available/electrox-pos"
NGINX_ENABLED="/etc/nginx/sites-enabled/electrox-pos"

echo "=========================================="
echo "Setting up Nginx and SSL for $DOMAIN"
echo "=========================================="

# Install certbot
echo "Installing certbot..."
apt-get update -qq
apt-get install -y certbot python3-certbot-nginx

# Copy nginx config
echo "Setting up nginx configuration..."
cp /tmp/nginx_electrox_pos.conf "$NGINX_CONF"
ln -sf "$NGINX_CONF" "$NGINX_ENABLED"

# Test and reload nginx
echo "Testing nginx configuration..."
nginx -t
if [ $? -ne 0 ]; then
    echo "✗ Nginx configuration test failed!"
    exit 1
fi

systemctl reload nginx

# Generate SSL certificate
echo "Generating SSL certificate..."
certbot --nginx -d $DOMAIN -d $WWW_DOMAIN --non-interactive --agree-tos --email admin@electrox-pos.com --redirect

# Setup auto-renewal
systemctl enable certbot.timer
systemctl start certbot.timer

echo "✓ Setup completed!"
echo "Test: https://$DOMAIN"
