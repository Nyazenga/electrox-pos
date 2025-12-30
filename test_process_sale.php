<?php
// Test script to check process_sale.php dependencies
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing process_sale.php dependencies...\n\n";

// Check if required files exist
$files = [
    'config.php',
    'includes/session.php',
    'includes/db.php',
    'includes/auth.php',
    'includes/functions.php',
    'includes/settings_functions.php',
    'includes/fiscal_service.php',
    'includes/fiscal_helper.php',
    'includes/currency_functions.php'
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        echo "✓ $file exists\n";
    } else {
        echo "✗ $file MISSING\n";
    }
}

// Try to load config
try {
    require_once __DIR__ . '/config.php';
    echo "\n✓ config.php loaded\n";
} catch (Exception $e) {
    echo "\n✗ config.php error: " . $e->getMessage() . "\n";
}

// Try to load database
try {
    require_once APP_PATH . '/includes/db.php';
    $db = Database::getInstance();
    echo "✓ Database connection successful\n";
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
}

// Check if functions exist
$functions = ['logError', 'logActivity', 'getSetting', 'getDefaultTaxRate', 'updateStock', 'ensurePOSTables', 'fiscalizeSale'];
foreach ($functions as $func) {
    if (function_exists($func)) {
        echo "✓ Function $func exists\n";
    } else {
        echo "✗ Function $func MISSING\n";
    }
}

echo "\nTest complete.\n";

