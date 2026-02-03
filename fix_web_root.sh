#!/bin/bash
# Fix web root path in nginx configuration

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"
WEB_ROOT="/var/www/electro-pos"  # Note: without 'x'

echo "=========================================="
echo "Fixing Web Root Configuration"
echo "=========================================="
echo ""

# Step 1: Verify web root exists
echo "Step 1: Verifying web root..."
if [ -d "$WEB_ROOT" ]; then
    echo "✓ Web root exists: $WEB_ROOT"
    if [ -f "$WEB_ROOT/index.php" ]; then
        echo "✓ index.php found"
    else
        echo "⚠ index.php not found, checking for other files..."
        ls -la "$WEB_ROOT" | head -10
    fi
else
    echo "✗ Web root does not exist: $WEB_ROOT"
    exit 1
fi

# Step 2: Update nginx configuration with correct web root
echo ""
echo "Step 2: Updating nginx configuration..."
cat > /etc/nginx/sites-available/electrox-pos <<EOF
# HTTP server - redirect to HTTPS (after SSL is set up)
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN $WWW_DOMAIN;
    root $WEB_ROOT;
    index index.php index.html;

    # Allow Let's Encrypt validation
    location /.well-known/acme-challenge/ {
        root /var/www/html;
        try_files \$uri =404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

# Step 3: Ensure .well-known directory exists
echo "Step 3: Creating .well-known directory..."
mkdir -p /var/www/html/.well-known/acme-challenge
chmod -R 755 /var/www/html/.well-known

# Step 4: Test nginx configuration
echo ""
echo "Step 4: Testing nginx configuration..."
nginx -t
if [ $? -ne 0 ]; then
    echo "✗ Nginx configuration test failed!"
    exit 1
fi

# Step 5: Reload nginx
echo "Step 5: Reloading nginx..."
systemctl reload nginx
echo "✓ Nginx reloaded"

# Step 6: Test HTTP access
echo ""
echo "Step 6: Testing HTTP access..."
curl -I http://localhost 2>&1 | head -5

echo ""
echo "=========================================="
echo "✓ Configuration Updated"
echo "=========================================="
echo ""
echo "Web root: $WEB_ROOT"
echo "Domain: $DOMAIN"
echo ""
echo "Test URLs:"
echo "  http://$DOMAIN"
echo "  http://$WWW_DOMAIN"
echo ""
echo "If the site loads, proceed with SSL certificate generation:"
echo "  certbot --nginx -d $DOMAIN -d $WWW_DOMAIN"
