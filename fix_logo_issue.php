<?php
/**
 * Fix Logo Issue - Check and Test
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';

echo "=== Logo Issue Diagnostic ===\n\n";

// Check database settings
$db = Database::getInstance();
$settings = $db->getRows("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE '%logo%'");

echo "Database Logo Settings:\n";
if (empty($settings)) {
    echo "  No logo settings found\n";
} else {
    foreach ($settings as $s) {
        echo "  {$s['setting_key']}: {$s['setting_value']}\n";
    }
}

echo "\n";

// Test receipt logo logic
echo "Receipt Logo Logic Test:\n";
$receiptLogoPath = getSetting('pos_receipt_logo', getSetting('company_logo', ''));
echo "  After fallback: " . ($receiptLogoPath ?: 'EMPTY') . "\n";

// If no logo setting found, try default logo path
if (empty($receiptLogoPath)) {
    $defaultLogoPath = 'assets/images/logo.png';
    if (file_exists(APP_PATH . '/' . $defaultLogoPath)) {
        $receiptLogoPath = $defaultLogoPath;
        echo "  Using default: $defaultLogoPath\n";
    } else {
        echo "  Default logo NOT found: $defaultLogoPath\n";
    }
}

if ($receiptLogoPath) {
    $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
    echo "  Full path: $logoFullPath\n";
    echo "  File exists: " . (file_exists($logoFullPath) ? 'YES' : 'NO') . "\n";
    
    if (file_exists($logoFullPath)) {
        $receiptLogoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
        echo "  Logo URL: $receiptLogoUrl\n";
        echo "  BASE_URL: " . BASE_URL . "\n";
    }
} else {
    echo "  No logo path available\n";
}

echo "\n";

// Check if logo.png exists
$logoFiles = [
    'assets/images/logo.png',
    'assets/images/invoice_logo_1765746915.png',
];

echo "Logo Files Check:\n";
foreach ($logoFiles as $file) {
    $fullPath = APP_PATH . '/' . $file;
    echo "  $file: " . (file_exists($fullPath) ? 'EXISTS' : 'NOT FOUND') . "\n";
}

