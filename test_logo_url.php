<?php
/**
 * Test Logo URL
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/settings_functions.php';

// Test receipt logo logic
$receiptLogoPath = getSetting('pos_receipt_logo', getSetting('company_logo', ''));

// If no logo setting found, try default logo path
if (empty($receiptLogoPath)) {
    $defaultLogoPath = 'assets/images/logo.png';
    if (file_exists(APP_PATH . '/' . $defaultLogoPath)) {
        $receiptLogoPath = $defaultLogoPath;
    }
}

echo "Receipt Logo Path: " . ($receiptLogoPath ?: 'NOT SET') . "\n";

if ($receiptLogoPath) {
    $receiptLogoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
    echo "Receipt Logo URL: $receiptLogoUrl\n";
    echo "File exists: " . (file_exists(APP_PATH . '/' . $receiptLogoPath) ? 'YES' : 'NO') . "\n";
} else {
    echo "No logo will be displayed\n";
}

