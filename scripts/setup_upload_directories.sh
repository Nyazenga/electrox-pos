#!/bin/bash
# Setup Upload Directories Script
# 
# This script creates the necessary upload directories with proper permissions.
# Run this script on the server to set up the upload directories.
#
# Usage: bash scripts/setup_upload_directories.sh
# Or: chmod +x scripts/setup_upload_directories.sh && ./scripts/setup_upload_directories.sh

# Get the script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
APP_DIR="$(dirname "$SCRIPT_DIR")"

UPLOADS_DIR="$APP_DIR/uploads"
PRODUCTS_DIR="$UPLOADS_DIR/products"

echo "=== Setting up upload directories ==="
echo ""
echo "Application directory: $APP_DIR"
echo "Uploads directory: $UPLOADS_DIR"
echo "Products directory: $PRODUCTS_DIR"
echo ""

# Check if we're running as root or have sudo
if [ "$EUID" -eq 0 ]; then
    SUDO=""
else
    SUDO="sudo"
    echo "Note: Some operations may require sudo privileges"
    echo ""
fi

# Create uploads directory
if [ ! -d "$UPLOADS_DIR" ]; then
    echo "Creating uploads directory..."
    $SUDO mkdir -p "$UPLOADS_DIR"
    if [ $? -eq 0 ]; then
        echo "✓ Uploads directory created"
    else
        echo "✗ Failed to create uploads directory"
        exit 1
    fi
else
    echo "✓ Uploads directory already exists"
fi

# Create products directory
if [ ! -d "$PRODUCTS_DIR" ]; then
    echo "Creating products directory..."
    $SUDO mkdir -p "$PRODUCTS_DIR"
    if [ $? -eq 0 ]; then
        echo "✓ Products directory created"
    else
        echo "✗ Failed to create products directory"
        exit 1
    fi
else
    echo "✓ Products directory already exists"
fi

# Set permissions
echo ""
echo "Setting permissions..."
$SUDO chmod -R 755 "$UPLOADS_DIR"
if [ $? -eq 0 ]; then
    echo "✓ Permissions set to 755"
else
    echo "✗ Failed to set permissions"
    exit 1
fi

# Set ownership (try to detect web server user)
if [ -n "$SUDO" ]; then
    # Try www-data (Apache)
    if id "www-data" &>/dev/null; then
        echo "Setting ownership to www-data:www-data..."
        $SUDO chown -R www-data:www-data "$UPLOADS_DIR"
        echo "✓ Ownership set to www-data:www-data"
    # Try nginx
    elif id "nginx" &>/dev/null; then
        echo "Setting ownership to nginx:nginx..."
        $SUDO chown -R nginx:nginx "$UPLOADS_DIR"
        echo "✓ Ownership set to nginx:nginx"
    else
        echo "⚠ Could not detect web server user. Please set ownership manually:"
        echo "  chown -R www-data:www-data $UPLOADS_DIR"
        echo "  or"
        echo "  chown -R nginx:nginx $UPLOADS_DIR"
    fi
fi

# Create .htaccess file
HTACCESS_FILE="$UPLOADS_DIR/.htaccess"
if [ ! -f "$HTACCESS_FILE" ]; then
    echo ""
    echo "Creating .htaccess file..."
    cat > "$HTACCESS_FILE" << 'EOF'
# Allow access to image files
<FilesMatch "\.(jpg|jpeg|png|gif|webp)$">
    Order Allow,Deny
    Allow from all
</FilesMatch>
EOF
    if [ $? -eq 0 ]; then
        echo "✓ .htaccess file created"
    else
        echo "⚠ Could not create .htaccess file (optional)"
    fi
else
    echo "✓ .htaccess file already exists"
fi

echo ""
echo "=== Setup complete ==="
echo "Upload directories are ready for use."
echo ""
echo "Directory structure:"
echo "  $UPLOADS_DIR"
echo "  $PRODUCTS_DIR"
echo ""
echo "Permissions:"
ls -ld "$UPLOADS_DIR" | awk '{print "  " $1 " " $3 ":" $4 " " $9}'
ls -ld "$PRODUCTS_DIR" | awk '{print "  " $1 " " $3 ":" $4 " " $9}'

