#!/bin/bash
# Fix session cookie settings to work with HTTP (not require HTTPS)

echo "=========================================="
echo "Fixing Session Cookie Settings"
echo "=========================================="
echo ""

# The issue is that session cookies are set with secure flag in production
# which requires HTTPS. Since we're on HTTP, we need to allow insecure cookies.

# Check current session.php
SESSION_FILE="/var/www/electro-pos/includes/session.php"

if [ -f "$SESSION_FILE" ]; then
    echo "Step 1: Backing up session.php..."
    cp "$SESSION_FILE" "${SESSION_FILE}.backup.$(date +%Y%m%d_%H%M%S)"
    
    echo "Step 2: Updating session cookie settings..."
    # Change the secure flag to false for HTTP
    sed -i "s/APP_MODE === 'production'/false/g" "$SESSION_FILE"
    
    echo "✓ Session cookie settings updated"
    echo ""
    echo "Session cookies will now work with HTTP."
    echo "Once HTTPS is enabled, change this back to: APP_MODE === 'production'"
else
    echo "✗ Session file not found: $SESSION_FILE"
    exit 1
fi

echo ""
echo "=========================================="
echo "✓ Session Settings Fixed"
echo "=========================================="
