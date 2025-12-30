<?php
define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';

// Test invoice logo logic exactly as in print.php
$invoiceLogo = getSetting('invoice_logo', getSetting('company_logo', ''));

echo "Invoice Logo Logic:\n";
echo "==================\n\n";
echo "invoice_logo setting: " . getSetting('invoice_logo', 'NOT SET') . "\n";
echo "company_logo setting: " . getSetting('company_logo', 'NOT SET') . "\n";
echo "After fallback: " . ($invoiceLogo ?: 'EMPTY') . "\n\n";

// Normalize logo path - ensure it's relative to APP_PATH
if ($invoiceLogo && !empty($invoiceLogo)) {
    $logoFullPath = APP_PATH . '/' . ltrim($invoiceLogo, '/');
    // If file doesn't exist at the stored path, try without leading slash
    if (!file_exists($logoFullPath) && strpos($invoiceLogo, '/') !== 0) {
        $logoFullPath = APP_PATH . '/' . $invoiceLogo;
    }
    // Only use logo if file actually exists
    if (!file_exists($logoFullPath)) {
        $invoiceLogo = '';
        echo "Logo file not found, cleared\n";
    } else {
        echo "Logo file found: $logoFullPath\n";
        echo "Logo URL: " . BASE_URL . ltrim($invoiceLogo, '/') . "\n";
    }
} else {
    echo "No logo setting found\n";
}

$showLogo = getSetting('invoice_show_logo', '1') == '1' && !empty($invoiceLogo);
echo "\nshowLogo: " . ($showLogo ? 'TRUE' : 'FALSE') . "\n";

