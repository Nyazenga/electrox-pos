<?php
/**
 * Check Logo Settings
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';

echo "Logo Settings Check:\n";
echo "===================\n\n";

$posReceiptLogo = getSetting('pos_receipt_logo', '');
$companyLogo = getSetting('company_logo', '');
$invoiceLogo = getSetting('invoice_logo', '');

echo "pos_receipt_logo: " . ($posReceiptLogo ?: 'NOT SET') . "\n";
echo "company_logo: " . ($companyLogo ?: 'NOT SET') . "\n";
echo "invoice_logo: " . ($invoiceLogo ?: 'NOT SET') . "\n\n";

// Check which one will be used with fallback
$receiptLogoPath = getSetting('pos_receipt_logo', getSetting('company_logo', ''));
echo "Receipt logo (with fallback): " . ($receiptLogoPath ?: 'NOT SET') . "\n\n";

// Check if files exist
if ($receiptLogoPath) {
    $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
    echo "Logo file path: $logoFullPath\n";
    echo "File exists: " . (file_exists($logoFullPath) ? 'YES' : 'NO') . "\n";
    
    if (file_exists($logoFullPath)) {
        $logoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
        echo "Logo URL: $logoUrl\n";
    }
} else {
    echo "No logo path found - logo will not display\n";
}

