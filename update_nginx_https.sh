#!/bin/bash
# Update nginx to use HTTPS with the newly generated SSL certificate

echo "=========================================="
echo "Updating Nginx for HTTPS"
echo "=========================================="
echo ""

NGINX_CONFIG="/etc/nginx/sites-available/electrox-pos"
CERT_PATH="/etc/letsencrypt/live/electrox-pos.com"

# Check if certificate exists
if [ ! -f "$CERT_PATH/fullchain.pem" ] || [ ! -f "$CERT_PATH/privkey.pem" ]; then
    echo "✗ SSL certificate not found!"
    echo "Expected: $CERT_PATH/fullchain.pem"
    exit 1
fi

echo "✓ SSL certificate found"

# Backup current config
if [ -f "$NGINX_CONFIG" ]; then
    cp "$NGINX_CONFIG" "${NGINX_CONFIG}.backup.$(date +%Y%m%d_%H%M%S)"
    echo "✓ Config backed up"
fi

# Create HTTPS configuration
cat > "$NGINX_CONFIG" <<'EOF'
# HTTP - Redirect to HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name electrox-pos.com www.electrox-pos.com;
    
    # Redirect all HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

# HTTPS - Main server block
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name electrox-pos.com www.electrox-pos.com;
    
    root /var/www/electro-pos;
    index index.php index.html index.htm;
    
    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/electrox-pos.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/electrox-pos.com/privkey.pem;
    
    # SSL Security Settings
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    # Security Headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    
    # Logging
    access_log /var/log/nginx/electrox_pos_access.log;
    error_log /var/log/nginx/electrox_pos_error.log;
    
    # PHP-FPM Configuration
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Deny access to hidden files
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }
    
    # Main location block
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # Static files caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
EOF

echo "✓ Nginx configuration updated"

# Test nginx configuration
echo ""
echo "Testing nginx configuration..."
if nginx -t 2>&1 | grep -q "successful"; then
    echo "✓ Nginx configuration test passed"
    
    # Reload nginx
    echo ""
    echo "Reloading nginx..."
    systemctl reload nginx
    
    if [ $? -eq 0 ]; then
        echo "✓ Nginx reloaded successfully"
    else
        echo "✗ Failed to reload nginx"
        exit 1
    fi
else
    echo "✗ Nginx configuration test failed"
    nginx -t
    exit 1
fi

echo ""
echo "=========================================="
echo "✓ HTTPS Configuration Complete"
echo "=========================================="
echo ""
echo "Site is now available at:"
echo "  - https://electrox-pos.com"
echo "  - https://www.electrox-pos.com"
echo ""
echo "HTTP requests will automatically redirect to HTTPS."
echo ""
echo "Certificate expires: 2026-05-04"
echo "Auto-renewal: Configured by Certbot"
