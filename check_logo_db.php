<?php
/**
 * Check Logo Settings from Database
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();

echo "Logo Settings from Database:\n";
echo "============================\n\n";

$settings = $db->getRows("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE '%logo%' ORDER BY setting_key");

if (empty($settings)) {
    echo "No logo settings found in database.\n";
} else {
    foreach ($settings as $s) {
        echo $s['setting_key'] . ": " . ($s['setting_value'] ?: '(empty)') . "\n";
    }
}

echo "\n";

// Check if logo file exists
$logoFiles = [
    'assets/images/logo.png',
    'assets/images/invoice_logo_1765746915.png',
    'assets/images/invoice_logo_1765736174.png',
];

echo "Logo Files:\n";
echo "===========\n\n";

foreach ($logoFiles as $logoFile) {
    $fullPath = APP_PATH . '/' . $logoFile;
    $exists = file_exists($fullPath);
    echo "$logoFile: " . ($exists ? 'EXISTS' : 'NOT FOUND') . "\n";
    if ($exists) {
        $size = filesize($fullPath);
        echo "  Size: " . round($size / 1024, 2) . " KB\n";
    }
}

echo "\n";

// Test what invoice uses
require_once APP_PATH . '/includes/settings_functions.php';
$invoiceLogo = getSetting('invoice_logo', getSetting('company_logo', ''));
echo "Invoice logo (with fallback): " . ($invoiceLogo ?: 'NOT SET') . "\n";

// Test what receipt should use
$receiptLogoPath = getSetting('pos_receipt_logo', getSetting('company_logo', ''));
echo "Receipt logo (with fallback): " . ($receiptLogoPath ?: 'NOT SET') . "\n";

if ($receiptLogoPath) {
    $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
    echo "Receipt logo file path: $logoFullPath\n";
    echo "Receipt logo file exists: " . (file_exists($logoFullPath) ? 'YES' : 'NO') . "\n";
    
    if (file_exists($logoFullPath)) {
        $logoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
        echo "Receipt logo URL: $logoUrl\n";
    }
}

