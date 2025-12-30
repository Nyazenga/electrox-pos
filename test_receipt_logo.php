<?php
define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';

echo "Receipt Logo Test (matching invoice logic):\n";
echo "===========================================\n\n";

// Test receipt logo logic - EXACT same as invoice
$receiptLogoPath = getSetting('pos_receipt_logo', getSetting('invoice_logo', getSetting('company_logo', '')));

echo "pos_receipt_logo: " . getSetting('pos_receipt_logo', 'NOT SET') . "\n";
echo "invoice_logo: " . getSetting('invoice_logo', 'NOT SET') . "\n";
echo "company_logo: " . getSetting('company_logo', 'NOT SET') . "\n";
echo "After fallback chain: " . ($receiptLogoPath ?: 'EMPTY') . "\n\n";

// Normalize logo path - ensure it's relative to APP_PATH
if ($receiptLogoPath && !empty($receiptLogoPath)) {
    $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
    // If file doesn't exist at the stored path, try without leading slash
    if (!file_exists($logoFullPath) && strpos($receiptLogoPath, '/') !== 0) {
        $logoFullPath = APP_PATH . '/' . $receiptLogoPath;
    }
    // Only use logo if file actually exists
    if (!file_exists($logoFullPath)) {
        $receiptLogoPath = '';
        echo "Logo file not found, cleared\n";
    } else {
        echo "Logo file found: $logoFullPath\n";
        $receiptLogoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
        echo "Logo URL: $receiptLogoUrl\n";
    }
} else {
    echo "No logo path available\n";
}

