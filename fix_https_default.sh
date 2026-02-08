#!/bin/bash
# Ensure electrox-pos.com handles HTTPS requests properly (even without cert)

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"
WEB_ROOT="/var/www/electro-pos"

echo "=========================================="
echo "Fixing HTTPS Default Server Issue"
echo "=========================================="
echo ""

# Step 1: Add a catch-all HTTPS server block that serves HTTP content
# This prevents browsers from redirecting to other sites when HTTPS fails
echo "Step 1: Adding HTTPS catch-all server block..."
cat >> /etc/nginx/sites-available/electrox-pos <<EOF

# HTTPS server (without SSL for now - will serve HTTP content)
# This prevents browsers from redirecting elsewhere when HTTPS is attempted
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $DOMAIN $WWW_DOMAIN;
    
    # Use self-signed certificate temporarily to prevent browser errors
    ssl_certificate /etc/ssl/certs/ssl-cert-snakeoil.pem;
    ssl_certificate_key /etc/ssl/private/ssl-cert-snakeoil.key;
    
    root $WEB_ROOT;
    index index.php index.html;

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

# Test and reload
echo "Step 2: Testing and reloading nginx..."
nginx -t
if [ $? -ne 0 ]; then
    echo "✗ Nginx configuration test failed!"
    exit 1
fi
systemctl reload nginx
echo "✓ Nginx reloaded"

echo ""
echo "=========================================="
echo "✓ HTTPS Catch-All Added"
echo "=========================================="
echo ""
echo "Now when users try HTTPS, they'll see a security warning"
echo "but can proceed to see your site instead of redirecting to aja.co.zw"
echo ""
echo "Once Let's Encrypt certificate is generated (after rate limit),"
echo "this will be replaced with the real certificate."
