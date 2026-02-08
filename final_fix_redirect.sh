#!/bin/bash
# Final fix - ensure HTTP works and prevent any redirects to aja.co.zw

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"
WEB_ROOT="/var/www/electro-pos"

echo "=========================================="
echo "Final Fix - Remove All Redirects"
echo "=========================================="
echo ""

# Step 1: Create clean HTTP-only config (no HTTPS redirect, no append)
echo "Step 1: Creating clean HTTP-only configuration..."
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

# Step 3: Test
echo ""
echo "Step 3: Testing..."
sleep 2
echo "Testing HTTP root..."
curl -s -o /dev/null -w "HTTP Code: %{http_code}\n" -L -H "Host: $DOMAIN" http://localhost/
echo "Testing HTTP login..."
curl -s -o /dev/null -w "HTTP Code: %{http_code}\n" -L -H "Host: $DOMAIN" http://localhost/login.php

echo ""
echo "=========================================="
echo "✓ Configuration Fixed"
echo "=========================================="
echo ""
echo "The site should now work correctly on HTTP:"
echo "  http://$DOMAIN"
echo "  http://$WWW_DOMAIN"
echo ""
echo "IMPORTANT: Users should use HTTP (not HTTPS) until SSL certificate is generated."
echo "HTTPS will be added after Let's Encrypt rate limit expires (1 hour)."
