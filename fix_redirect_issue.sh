#!/bin/bash
# Fix redirect issue - remove HTTPS redirect until certificate exists

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"
WEB_ROOT="/var/www/electro-pos"

echo "=========================================="
echo "Fixing Redirect Issue"
echo "=========================================="
echo ""

# Step 1: Create HTTP-only config (NO HTTPS redirect)
echo "Step 1: Creating HTTP-only configuration (no redirect)..."
cat > /etc/nginx/sites-available/electrox-pos <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name $DOMAIN $WWW_DOMAIN;
    root $WEB_ROOT;
    index index.php index.html;

    # Allow Let's Encrypt validation
    location /.well-known/acme-challenge/ {
        root $WEB_ROOT;
        try_files \$uri =404;
    }

    access_log /var/log/nginx/electrox_pos_access.log;
    error_log /var/log/nginx/electrox_pos_error.log;

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

# Ensure .well-known directory exists
mkdir -p "$WEB_ROOT/.well-known/acme-challenge"
chmod -R 755 "$WEB_ROOT/.well-known"

# Test and reload
echo "Step 2: Testing and reloading nginx..."
nginx -t
if [ $? -ne 0 ]; then
    echo "✗ Nginx configuration test failed!"
    exit 1
fi
systemctl reload nginx
echo "✓ Nginx reloaded"

# Step 3: Test HTTP access
echo ""
echo "Step 3: Testing HTTP access..."
sleep 2
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -L -H "Host: $DOMAIN" http://localhost)
echo "HTTP Response Code: $HTTP_CODE"

if [ "$HTTP_CODE" = "200" ]; then
    echo "✓ HTTP is working correctly!"
    
    # Test that it's not redirecting to aja.co.zw
    REDIRECT_URL=$(curl -s -o /dev/null -w "%{redirect_url}" -L -H "Host: $DOMAIN" http://localhost)
    if [ -z "$REDIRECT_URL" ] || [[ "$REDIRECT_URL" != *"aja.co.zw"* ]]; then
        echo "✓ No redirect to aja.co.zw detected"
    else
        echo "⚠ Still redirecting to: $REDIRECT_URL"
    fi
else
    echo "⚠ HTTP returned code: $HTTP_CODE"
fi

echo ""
echo "=========================================="
echo "✓ Configuration Fixed"
echo "=========================================="
echo ""
echo "The site should now work on HTTP without redirecting."
echo "Test: http://$DOMAIN"
echo ""
echo "Once SSL certificate is generated (after rate limit expires),"
echo "we can add HTTPS redirect back."
