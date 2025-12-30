<?php
/**
 * Debug Logo Comparison - Invoice vs Receipt
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';

echo "=== Logo Comparison: Invoice vs Receipt ===\n\n";

// Invoice logic (from print.php)
$invoiceLogo = getSetting('invoice_logo', getSetting('company_logo', ''));
echo "INVOICE LOGIC:\n";
echo "  invoice_logo: " . getSetting('invoice_logo', 'NOT SET') . "\n";
echo "  company_logo: " . getSetting('company_logo', 'NOT SET') . "\n";
echo "  After fallback: " . ($invoiceLogo ?: 'EMPTY') . "\n";

if ($invoiceLogo && !empty($invoiceLogo)) {
    $logoFullPath = APP_PATH . '/' . ltrim($invoiceLogo, '/');
    if (!file_exists($logoFullPath) && strpos($invoiceLogo, '/') !== 0) {
        $logoFullPath = APP_PATH . '/' . $invoiceLogo;
    }
    if (!file_exists($logoFullPath)) {
        $invoiceLogo = '';
        echo "  File not found, cleared\n";
    } else {
        echo "  File exists: YES\n";
        $invoiceLogoUrl = BASE_URL . ltrim($invoiceLogo, '/');
        echo "  Logo URL: $invoiceLogoUrl\n";
    }
}

$showLogo = getSetting('invoice_show_logo', '1') == '1' && !empty($invoiceLogo);
echo "  showLogo: " . ($showLogo ? 'TRUE' : 'FALSE') . "\n\n";

// Receipt logic (from receipt.php)
$receiptLogoPath = getSetting('pos_receipt_logo', getSetting('invoice_logo', getSetting('company_logo', '')));
echo "RECEIPT LOGIC:\n";
echo "  pos_receipt_logo: " . getSetting('pos_receipt_logo', 'NOT SET') . "\n";
echo "  invoice_logo: " . getSetting('invoice_logo', 'NOT SET') . "\n";
echo "  company_logo: " . getSetting('company_logo', 'NOT SET') . "\n";
echo "  After fallback: " . ($receiptLogoPath ?: 'EMPTY') . "\n";

if ($receiptLogoPath && !empty($receiptLogoPath)) {
    $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
    if (!file_exists($logoFullPath) && strpos($receiptLogoPath, '/') !== 0) {
        $logoFullPath = APP_PATH . '/' . $receiptLogoPath;
    }
    if (!file_exists($logoFullPath)) {
        $receiptLogoPath = '';
        echo "  File not found, cleared\n";
    } else {
        echo "  File exists: YES\n";
        $receiptLogoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
        echo "  Logo URL: $receiptLogoUrl\n";
    }
}

echo "  Will show logo: " . ($receiptLogoPath ? 'YES' : 'NO') . "\n\n";

// Check all logo files
echo "LOGO FILES ON DISK:\n";
$logoFiles = glob(APP_PATH . '/assets/images/*logo*.png');
foreach ($logoFiles as $file) {
    $relative = str_replace(APP_PATH . '/', '', $file);
    echo "  $relative: EXISTS\n";
}

