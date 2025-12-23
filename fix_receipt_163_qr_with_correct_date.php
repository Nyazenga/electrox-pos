<?php
/**
 * Fix QR code for receipt #163 using the correct receiptDate from sale_date
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';
require_once APP_PATH . '/vendor/autoload.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

$primaryDb = Database::getPrimaryInstance();

// Get sale 163
$sale = $primaryDb->getRow("SELECT * FROM sales WHERE id = 163");

if (!$sale) {
    echo "Sale 163 not found\n";
    exit(1);
}

// Get fiscal receipt
$fiscalReceipt = $primaryDb->getRow(
    "SELECT fr.*, fc.qr_url 
     FROM fiscal_receipts fr
     LEFT JOIN fiscal_config fc ON fc.branch_id = fr.branch_id AND fc.device_id = fr.device_id
     WHERE fr.sale_id = 163 
     ORDER BY fr.id DESC LIMIT 1"
);

if (!$fiscalReceipt) {
    echo "Fiscal receipt not found for sale 163\n";
    exit(1);
}

echo "=== Current State ===\n";
echo "Sale Date: {$sale['sale_date']}\n";
echo "Receipt Date in DB: {$fiscalReceipt['receipt_date']}\n";
echo "Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n\n";

// The receiptDate should be from sale_date (formatted as Y-m-d\TH:i:s)
$correctReceiptDate = date('Y-m-d\TH:i:s', strtotime($sale['sale_date']));

echo "=== Fixing ===\n";
echo "Correct Receipt Date (from sale_date): $correctReceiptDate\n";
echo "This should match what we originally sent to ZIMRA\n\n";

// Get QR URL
$qrUrl = $fiscalReceipt['qr_url'] ?? 'https://fdmstest.zimra.co.zw';

// Generate QR code using CORRECT receiptDate (from sale_date, not serverDate)
$qrCodeResult = ZimraQRCode::generateQRCode(
    $qrUrl,
    $fiscalReceipt['device_id'],
    $correctReceiptDate, // Use sale_date, not serverDate
    $fiscalReceipt['receipt_global_no'],
    $fiscalReceipt['receipt_qr_data']
);

echo "Generated QR Code URL:\n";
echo "{$qrCodeResult['qrCode']}\n\n";

// Update both receipt_date AND QR code image
$primaryDb->update('fiscal_receipts', [
    'receipt_date' => date('Y-m-d H:i:s', strtotime($sale['sale_date'])), // Store receiptDate (from sale_date), not serverDate
    'receipt_qr_code' => $qrCodeResult['qrImage'], // Base64 encoded image
    'receipt_verification_code' => $qrCodeResult['verificationCode']
], ['id' => $fiscalReceipt['id']]);

echo "✓ Updated receipt_date in database\n";
echo "✓ Updated QR code image\n";
echo "✓ Updated verification code\n";
echo "\nDone! Receipt #163 should now have the correct QR code.\n";

