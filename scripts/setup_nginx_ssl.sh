#!/bin/bash
# Setup Nginx and SSL Certificate for electrox-pos.com

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"
WEB_ROOT="/var/www/electrox-pos"
NGINX_CONF="/etc/nginx/sites-available/electrox-pos"
NGINX_ENABLED="/etc/nginx/sites-enabled/electrox-pos"

echo "=========================================="
echo "Setting up Nginx and SSL for $DOMAIN"
echo "=========================================="
echo ""

# Step 1: Install certbot if not installed
echo "Step 1: Checking certbot installation..."
if ! command -v certbot &> /dev/null; then
    echo "Installing certbot..."
    apt-get update
    apt-get install -y certbot python3-certbot-nginx
else
    echo "✓ Certbot is already installed"
fi

# Step 2: Create temporary HTTP config for certificate generation
echo ""
echo "Step 2: Creating temporary HTTP config..."
cat > /tmp/nginx_temp.conf <<EOF
server {
    listen 80;
    server_name $DOMAIN $WWW_DOMAIN;
    root $WEB_ROOT;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

# Step 3: Backup existing config if it exists
if [ -f "$NGINX_CONF" ]; then
    echo "Backing up existing config..."
    cp "$NGINX_CONF" "${NGINX_CONF}.backup.$(date +%Y%m%d_%H%M%S)"
fi

# Step 4: Copy temporary config
echo "Copying temporary config..."
cp /tmp/nginx_temp.conf "$NGINX_CONF"

# Step 5: Enable site
echo "Enabling site..."
ln -sf "$NGINX_CONF" "$NGINX_ENABLED"

# Step 6: Test nginx config
echo "Testing nginx configuration..."
nginx -t
if [ $? -ne 0 ]; then
    echo "✗ Nginx configuration test failed!"
    exit 1
fi

# Step 7: Reload nginx
echo "Reloading nginx..."
systemctl reload nginx

# Step 8: Generate SSL certificate
echo ""
echo "Step 3: Generating SSL certificate..."
certbot --nginx -d $DOMAIN -d $WWW_DOMAIN --non-interactive --agree-tos --email admin@electrox-pos.com --redirect

if [ $? -eq 0 ]; then
    echo "✓ SSL certificate generated successfully!"
else
    echo "✗ SSL certificate generation failed!"
    echo "You may need to run manually:"
    echo "  certbot --nginx -d $DOMAIN -d $WWW_DOMAIN"
    exit 1
fi

# Step 9: Update nginx config with full HTTPS config
echo ""
echo "Step 4: Updating nginx config with full HTTPS settings..."
cat > "$NGINX_CONF" <<'NGINX_EOF'
# HTTP server - redirect to HTTPS
server {
    listen 80;
    server_name electrox-pos.com www.electrox-pos.com;
    return 301 https://$server_name$request_uri;
}

# HTTPS server
server {
    listen 443 ssl http2;
    server_name electrox-pos.com www.electrox-pos.com;
    root /var/www/electrox-pos;
    index index.php index.html;

    # SSL configuration (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/electrox-pos.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/electrox-pos.com/privkey.pem;
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
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
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

# Step 10: Test and reload
echo "Testing updated configuration..."
nginx -t
if [ $? -ne 0 ]; then
    echo "✗ Configuration test failed!"
    exit 1
fi

echo "Reloading nginx..."
systemctl reload nginx

# Step 11: Setup auto-renewal
echo ""
echo "Step 5: Setting up SSL certificate auto-renewal..."
systemctl enable certbot.timer
systemctl start certbot.timer

echo ""
echo "=========================================="
echo "✓ Setup completed successfully!"
echo "=========================================="
echo ""
echo "Domain: https://$DOMAIN"
echo "Domain: https://$WWW_DOMAIN"
echo ""
echo "SSL certificate will auto-renew via certbot timer."
echo "Test your site: https://$DOMAIN"
