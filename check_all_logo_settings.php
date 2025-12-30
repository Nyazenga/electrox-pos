<?php
define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();

echo "All Logo-Related Settings:\n";
echo "==========================\n\n";

// Get all settings with 'logo' in the key
$settings = $db->getRows("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE '%logo%' ORDER BY setting_key");

if (empty($settings)) {
    echo "No logo settings found in database.\n\n";
} else {
    foreach ($settings as $s) {
        echo $s['setting_key'] . ": " . ($s['setting_value'] ?: '(empty)') . "\n";
    }
}

echo "\n";

// Check all possible logo files
echo "Logo Files on Disk:\n";
echo "===================\n";
$logoFiles = [
    'assets/images/logo.png',
    'assets/images/invoice_logo_1765746915.png',
    'assets/images/invoice_logo_1765736174.png',
    'assets/images/invoice_logo_1765736740.png',
    'assets/images/invoice_logo_1765736217.png',
    'assets/images/invoice_logo_1765746977.png',
];

foreach ($logoFiles as $file) {
    $fullPath = APP_PATH . '/' . $file;
    if (file_exists($fullPath)) {
        echo "$file: EXISTS\n";
    }
}

