<?php
/**
 * Setup Upload Directories Script
 * 
 * This script creates the necessary upload directories with proper permissions.
 * Run this script once on the server to set up the upload directories.
 * 
 * Usage: php scripts/setup_upload_directories.php
 */

require_once dirname(dirname(__FILE__)) . '/config.php';

echo "=== Setting up upload directories ===\n\n";

$baseDir = APP_PATH;
$uploadsDir = $baseDir . '/uploads';
$productsDir = $uploadsDir . '/products';

// Check if base directory exists
if (!is_dir($baseDir)) {
    echo "ERROR: Base application directory does not exist: $baseDir\n";
    exit(1);
}

echo "Base directory: $baseDir\n";

// Create uploads directory
if (!is_dir($uploadsDir)) {
    echo "Creating uploads directory: $uploadsDir\n";
    if (mkdir($uploadsDir, 0755, true)) {
        echo "✓ Uploads directory created successfully\n";
    } else {
        echo "✗ Failed to create uploads directory\n";
        echo "Please run: mkdir -p $uploadsDir && chmod 755 $uploadsDir\n";
        exit(1);
    }
} else {
    echo "✓ Uploads directory already exists\n";
}

// Create products directory
if (!is_dir($productsDir)) {
    echo "Creating products directory: $productsDir\n";
    if (mkdir($productsDir, 0755, true)) {
        echo "✓ Products directory created successfully\n";
    } else {
        echo "✗ Failed to create products directory\n";
        echo "Please run: mkdir -p $productsDir && chmod 755 $productsDir\n";
        exit(1);
    }
} else {
    echo "✓ Products directory already exists\n";
}

// Check and set permissions
echo "\nChecking permissions...\n";

$dirs = [$uploadsDir, $productsDir];
foreach ($dirs as $dir) {
    $perms = substr(sprintf('%o', fileperms($dir)), -4);
    echo "  $dir: $perms\n";
    
    if (!is_writable($dir)) {
        echo "  ⚠ Directory is not writable. Attempting to fix...\n";
        if (chmod($dir, 0755)) {
            echo "  ✓ Permissions updated to 755\n";
        } else {
            echo "  ✗ Failed to update permissions. Please run: chmod 755 $dir\n";
        }
    } else {
        echo "  ✓ Directory is writable\n";
    }
}

// Create .htaccess to protect uploads directory (optional)
$htaccessFile = $uploadsDir . '/.htaccess';
if (!file_exists($htaccessFile)) {
    $htaccessContent = "# Allow access to image files\n";
    $htaccessContent .= "<FilesMatch \"\\.(jpg|jpeg|png|gif|webp)$\">\n";
    $htaccessContent .= "    Order Allow,Deny\n";
    $htaccessContent .= "    Allow from all\n";
    $htaccessContent .= "</FilesMatch>\n";
    
    if (file_put_contents($htaccessFile, $htaccessContent)) {
        echo "\n✓ Created .htaccess file for uploads directory\n";
    } else {
        echo "\n⚠ Could not create .htaccess file (optional)\n";
    }
}

echo "\n=== Setup complete ===\n";
echo "Upload directories are ready for use.\n";
echo "\nIf you still have permission issues, run these commands as root:\n";
echo "  chmod -R 755 $uploadsDir\n";
echo "  chown -R www-data:www-data $uploadsDir\n";
echo "  (or chown -R nginx:nginx $uploadsDir if using nginx)\n";

