#!/bin/bash
# Fix nginx IPv6 configuration and generate SSL certificate

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"

echo "=========================================="
echo "Fixing nginx IPv6 and generating SSL"
echo "=========================================="

# Step 1: Update nginx config to properly handle IPv6 and .well-known
echo "Step 1: Updating nginx config for IPv6 support..."
cat > /etc/nginx/sites-available/electrox-pos <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN $WWW_DOMAIN;
    root /var/www/electrox-pos;
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
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

# Ensure .well-known directory exists
mkdir -p /var/www/html/.well-known/acme-challenge
chmod -R 755 /var/www/html/.well-known

# Test and reload
echo "Step 2: Testing nginx configuration..."
nginx -t
if [ $? -ne 0 ]; then
    echo "✗ Nginx configuration test failed!"
    exit 1
fi

systemctl reload nginx
echo "✓ Nginx reloaded"

# Step 3: Try certbot with webroot method (more reliable)
echo "Step 3: Generating SSL certificate using webroot method..."
certbot certonly --webroot \
    -w /var/www/html \
    -d $DOMAIN \
    -d $WWW_DOMAIN \
    --non-interactive \
    --agree-tos \
    --email admin@electrox-pos.com \
    --preferred-chain "ISRG Root X1"

if [ $? -eq 0 ]; then
    echo "✓ SSL certificate generated!"
    
    # Step 4: Update nginx with full HTTPS config
    echo "Step 4: Updating nginx with HTTPS configuration..."
    cat > /etc/nginx/sites-available/electrox-pos <<'NGINX_EOF'
# HTTP server - redirect to HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name electrox-pos.com www.electrox-pos.com;
    return 301 https://$server_name$request_uri;
}

# HTTPS server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
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

    # Test and reload
    nginx -t
    if [ $? -eq 0 ]; then
        systemctl reload nginx
        echo "✓ HTTPS configuration active"
    else
        echo "✗ HTTPS configuration test failed!"
        exit 1
    fi
    
    # Setup auto-renewal
    systemctl enable certbot.timer
    systemctl start certbot.timer
    
    echo ""
    echo "=========================================="
    echo "✓ Setup completed successfully!"
    echo "=========================================="
    echo ""
    echo "Test your site:"
    echo "  https://$DOMAIN"
    echo "  https://$WWW_DOMAIN"
    
else
    echo "✗ Certificate generation failed!"
    echo ""
    echo "Check logs: tail -50 /var/log/letsencrypt/letsencrypt.log"
    exit 1
fi
