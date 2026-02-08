#!/bin/bash
# Fix certbot webroot and generate SSL certificate correctly

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"
WEB_ROOT="/var/www/electro-pos"  # Correct path without 'x'

echo "=========================================="
echo "Fixing Certbot Webroot and Generating SSL"
echo "=========================================="
echo ""

# Step 1: Verify web root
echo "Step 1: Verifying web root..."
if [ -d "$WEB_ROOT" ]; then
    echo "✓ Web root exists: $WEB_ROOT"
    if [ -f "$WEB_ROOT/index.php" ]; then
        echo "✓ index.php found"
    fi
else
    echo "✗ Web root does not exist: $WEB_ROOT"
    exit 1
fi

# Step 2: Create .well-known directory in web root (not /var/www/html)
echo "Step 2: Creating .well-known directory in web root..."
mkdir -p "$WEB_ROOT/.well-known/acme-challenge"
chmod -R 755 "$WEB_ROOT/.well-known"

# Step 3: Update nginx to serve .well-known from web root
echo "Step 3: Updating nginx configuration..."
cat > /etc/nginx/sites-available/electrox-pos <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN $WWW_DOMAIN;
    root $WEB_ROOT;
    index index.php index.html;

    # Allow Let's Encrypt validation from web root
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

# Test and reload nginx
echo "Step 4: Testing and reloading nginx..."
nginx -t
if [ $? -ne 0 ]; then
    echo "✗ Nginx configuration test failed!"
    exit 1
fi
systemctl reload nginx
echo "✓ Nginx reloaded"

# Step 5: Generate SSL certificate using CORRECT webroot
echo ""
echo "Step 5: Generating SSL certificate with correct webroot..."
certbot certonly --webroot \
    -w $WEB_ROOT \
    -d $DOMAIN \
    -d $WWW_DOMAIN \
    --non-interactive \
    --agree-tos \
    --email admin@electrox-pos.com \
    --preferred-chain "ISRG Root X1" \
    2>&1 | tee /tmp/certbot_output.log

if [ $? -eq 0 ]; then
    echo "✓ SSL certificate generated successfully!"
    
    # Step 6: Update nginx with HTTPS configuration
    echo ""
    echo "Step 6: Updating nginx with HTTPS configuration..."
    cat > /etc/nginx/sites-available/electrox-pos <<NGINX_EOF
# HTTP server - redirect to HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN $WWW_DOMAIN;
    return 301 https://\$server_name\$request_uri;
}

# HTTPS server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $DOMAIN $WWW_DOMAIN;
    root $WEB_ROOT;
    index index.php index.html;

    # SSL configuration (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    access_log /var/log/nginx/electrox_pos_access.log;
    error_log /var/log/nginx/electrox_pos_error.log;

    # Increase upload size
    client_max_body_size 50M;

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

    # Deny access to hidden files
    location ~ /\.ht {
        deny all;
    }

    # Deny access to sensitive files
    location ~ /\.(git|env|log) {
        deny all;
    }

    # Cache static assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
NGINX_EOF

    # Test and reload
    echo "Step 7: Testing HTTPS configuration..."
    nginx -t
    if [ $? -eq 0 ]; then
        systemctl reload nginx
        echo "✓ HTTPS configuration active"
    else
        echo "✗ HTTPS configuration test failed!"
        exit 1
    fi
    
    # Setup auto-renewal
    echo "Step 8: Setting up SSL auto-renewal..."
    systemctl enable certbot.timer
    systemctl start certbot.timer
    
    echo ""
    echo "=========================================="
    echo "✓ Setup Completed Successfully!"
    echo "=========================================="
    echo ""
    echo "Your site is now live:"
    echo "  https://$DOMAIN"
    echo "  https://$WWW_DOMAIN"
    echo ""
    echo "HTTP will automatically redirect to HTTPS."
    
else
    echo "✗ SSL certificate generation failed!"
    echo ""
    echo "Check output: cat /tmp/certbot_output.log"
    echo ""
    echo "Troubleshooting:"
    echo "1. Verify DNS: dig $DOMAIN"
    echo "2. Test HTTP: curl -I http://$DOMAIN"
    echo "3. Check .well-known: curl http://$DOMAIN/.well-known/acme-challenge/test"
    exit 1
fi
