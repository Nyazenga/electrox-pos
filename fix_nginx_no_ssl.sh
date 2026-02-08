#!/bin/bash
# Fix nginx to serve HTTP first, then generate SSL

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"
WEB_ROOT="/var/www/electro-pos"

echo "=========================================="
echo "Fixing Nginx Configuration (HTTP First)"
echo "=========================================="
echo ""

# Step 1: Create HTTP-only config (no HTTPS redirect yet)
echo "Step 1: Creating HTTP-only configuration..."
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
echo "✓ Nginx reloaded with HTTP-only config"

# Step 3: Test HTTP access
echo ""
echo "Step 3: Testing HTTP access..."
sleep 2
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Host: $DOMAIN" http://localhost)
echo "HTTP Response Code: $HTTP_CODE"

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "301" ] || [ "$HTTP_CODE" = "302" ]; then
    echo "✓ HTTP is working!"
else
    echo "⚠ HTTP returned code: $HTTP_CODE"
fi

# Step 4: Generate SSL certificate
echo ""
echo "Step 4: Generating SSL certificate..."
certbot certonly --webroot \
    -w $WEB_ROOT \
    -d $DOMAIN \
    -d $WWW_DOMAIN \
    --non-interactive \
    --agree-tos \
    --email admin@electrox-pos.com \
    --preferred-chain "ISRG Root X1" \
    2>&1 | tee /tmp/certbot_final.log

if [ $? -eq 0 ]; then
    echo "✓ SSL certificate generated!"
    
    # Step 5: Update to HTTPS config
    echo ""
    echo "Step 5: Updating to HTTPS configuration..."
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
    echo "Step 6: Testing HTTPS configuration..."
    nginx -t
    if [ $? -eq 0 ]; then
        systemctl reload nginx
        echo "✓ HTTPS configuration active"
        
        # Setup auto-renewal
        systemctl enable certbot.timer
        systemctl start certbot.timer
        
        echo ""
        echo "=========================================="
        echo "✓ Setup Completed Successfully!"
        echo "=========================================="
        echo ""
        echo "Your site is now live:"
        echo "  http://$DOMAIN (redirects to HTTPS)"
        echo "  https://$DOMAIN"
        echo "  https://$WWW_DOMAIN"
    else
        echo "✗ HTTPS configuration test failed!"
        exit 1
    fi
else
    echo "⚠ SSL certificate generation failed, but HTTP should work"
    echo ""
    echo "Check: cat /tmp/certbot_final.log"
    echo ""
    echo "The site should work on HTTP for now. You can generate SSL later."
fi
