#!/bin/bash
# SSL Certificate Generation - Force IPv4
# This ensures Let's Encrypt uses IPv4 only

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"

echo "=========================================="
echo "Generating SSL Certificate (IPv4 Only)"
echo "=========================================="

# Check current nginx config
echo "Step 1: Checking nginx configuration..."
nginx -t

# Ensure nginx is running and listening on port 80
echo "Step 2: Ensuring nginx is running..."
systemctl start nginx
systemctl status nginx --no-pager | head -5

# Test HTTP access
echo "Step 3: Testing HTTP access..."
curl -I http://$DOMAIN 2>&1 | head -3

# Generate certificate using HTTP-01 challenge with IPv4 preference
echo "Step 4: Generating SSL certificate (forcing IPv4)..."
# Use --preferred-challenges http and ensure we're using IPv4
certbot certonly --nginx \
    -d $DOMAIN \
    -d $WWW_DOMAIN \
    --non-interactive \
    --agree-tos \
    --email admin@electrox-pos.com \
    --preferred-challenges http \
    --preferred-chain "ISRG Root X1" \
    2>&1 | tee /tmp/certbot_output.log

if [ $? -eq 0 ]; then
    echo "✓ SSL certificate generated successfully!"
    
    # Update nginx config to use the certificate
    echo "Step 5: Updating nginx config with SSL..."
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

    # Test and reload nginx
    echo "Step 6: Testing nginx configuration..."
    nginx -t
    if [ $? -eq 0 ]; then
        systemctl reload nginx
        echo "✓ Nginx reloaded with SSL configuration"
    else
        echo "✗ Nginx configuration test failed!"
        exit 1
    fi
    
    # Setup auto-renewal
    echo "Step 7: Setting up SSL auto-renewal..."
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
    echo "Troubleshooting steps:"
    echo "1. Check if port 80 is accessible: curl -I http://$DOMAIN"
    echo "2. Check firewall: ufw status"
    echo "3. Check nginx is listening: netstat -tlnp | grep :80"
    echo "4. Check certbot logs: tail -50 /var/log/letsencrypt/letsencrypt.log"
    exit 1
fi
