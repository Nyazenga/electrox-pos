#!/bin/bash
# Check and fix domain configuration on server

DOMAIN="electrox-pos.com"
WWW_DOMAIN="www.electrox-pos.com"
WEB_ROOT="/var/www/electrox-pos"

echo "=========================================="
echo "Checking Domain Configuration"
echo "=========================================="
echo ""

# Step 1: Check if web root exists and has files
echo "Step 1: Checking web root..."
if [ -d "$WEB_ROOT" ]; then
    echo "✓ Web root exists: $WEB_ROOT"
    file_count=$(find "$WEB_ROOT" -maxdepth 1 -type f | wc -l)
    echo "  Files in root: $file_count"
    if [ -f "$WEB_ROOT/index.php" ]; then
        echo "  ✓ index.php exists"
    else
        echo "  ⚠ index.php not found"
    fi
else
    echo "✗ Web root does not exist: $WEB_ROOT"
    exit 1
fi

# Step 2: Check nginx configuration
echo ""
echo "Step 2: Checking nginx configuration..."
if [ -f "/etc/nginx/sites-available/electrox-pos" ]; then
    echo "✓ Nginx config exists"
    echo ""
    echo "Current config:"
    cat /etc/nginx/sites-available/electrox-pos
else
    echo "✗ Nginx config not found"
fi

# Step 3: Check if site is enabled
echo ""
echo "Step 3: Checking enabled sites..."
if [ -L "/etc/nginx/sites-enabled/electrox-pos" ]; then
    echo "✓ Site is enabled"
else
    echo "⚠ Site is not enabled, enabling now..."
    ln -sf /etc/nginx/sites-available/electrox-pos /etc/nginx/sites-enabled/electrox-pos
fi

# Step 4: Test nginx configuration
echo ""
echo "Step 4: Testing nginx configuration..."
nginx -t

# Step 5: Check what nginx is listening on
echo ""
echo "Step 5: Checking nginx listening ports..."
netstat -tlnp | grep nginx | grep -E ':(80|443)'

# Step 6: Check if domain resolves correctly from server
echo ""
echo "Step 6: Checking DNS resolution from server..."
dig +short $DOMAIN
dig +short $WWW_DOMAIN

# Step 7: Create a simple test page
echo ""
echo "Step 7: Creating test page..."
cat > "$WEB_ROOT/test.html" <<EOF
<!DOCTYPE html>
<html>
<head>
    <title>ELECTROX-POS Test</title>
</head>
<body>
    <h1>ELECTROX-POS Server is Working!</h1>
    <p>Domain: $DOMAIN</p>
    <p>Server IP: $(hostname -I | awk '{print $1}')</p>
    <p>Time: $(date)</p>
</body>
</html>
EOF
echo "✓ Test page created at $WEB_ROOT/test.html"

# Step 8: Reload nginx
echo ""
echo "Step 8: Reloading nginx..."
systemctl reload nginx
echo "✓ Nginx reloaded"

echo ""
echo "=========================================="
echo "Check Complete"
echo "=========================================="
echo ""
echo "Test URLs:"
echo "  http://$DOMAIN/test.html"
echo "  http://$WWW_DOMAIN/test.html"
echo ""
echo "If test.html works but main site doesn't, check:"
echo "  1. index.php permissions"
echo "  2. PHP-FPM status: systemctl status php8.3-fpm"
echo "  3. Nginx error logs: tail -f /var/log/nginx/error.log"
