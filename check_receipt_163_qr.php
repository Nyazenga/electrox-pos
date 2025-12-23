<?php
/**
 * Check QR code for receipt #163
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

$primaryDb = Database::getPrimaryInstance();

// Get sale 163
$sale = $primaryDb->getRow("SELECT * FROM sales WHERE id = 163");

if (!$sale) {
    echo "Sale 163 not found\n";
    exit(1);
}

echo "=== Sale 163 Details ===\n";
echo "Sale ID: {$sale['id']}\n";
echo "Sale Date: {$sale['sale_date']}\n";
echo "Receipt Number: {$sale['receipt_number']}\n\n";

// Get fiscal receipt
$fiscalReceipt = $primaryDb->getRow(
    "SELECT * FROM fiscal_receipts WHERE sale_id = 163 ORDER BY id DESC LIMIT 1"
);

if (!$fiscalReceipt) {
    echo "Fiscal receipt not found for sale 163\n";
    exit(1);
}

echo "=== Fiscal Receipt Details ===\n";
echo "Fiscal Receipt ID: {$fiscalReceipt['id']}\n";
echo "Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
echo "Receipt Date (stored in DB): {$fiscalReceipt['receipt_date']}\n";
echo "Device ID: {$fiscalReceipt['device_id']}\n";
echo "Receipt QR Data: {$fiscalReceipt['receipt_qr_data']}\n";
echo "Verification Code: {$fiscalReceipt['receipt_verification_code']}\n\n";

// Get QR URL from config
$config = $primaryDb->getRow(
    "SELECT qr_url FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
    [':branch_id' => $fiscalReceipt['branch_id'], ':device_id' => $fiscalReceipt['device_id']]
);

$qrUrl = $config['qr_url'] ?? 'https://fdmstest.zimra.co.zw';

echo "=== QR Code Details ===\n";
echo "QR URL: $qrUrl\n\n";

// Build QR code URL using stored receipt_date
$deviceIdFormatted = str_pad($fiscalReceipt['device_id'], 10, '0', STR_PAD_LEFT);
$date = new DateTime($fiscalReceipt['receipt_date']);
$receiptDateFormatted = $date->format('dmy'); // ddMMyyyy format
$receiptGlobalNoFormatted = str_pad($fiscalReceipt['receipt_global_no'], 10, '0', STR_PAD_LEFT);
$qrDataFormatted = substr($fiscalReceipt['receipt_qr_data'], 0, 16);
$qrCodeString = rtrim($qrUrl, '/') . '/' . $deviceIdFormatted . $receiptDateFormatted . $receiptGlobalNoFormatted . $qrDataFormatted;

echo "Generated QR Code URL:\n";
echo "$qrCodeString\n\n";

echo "Components:\n";
echo "  Device ID (10 digits): $deviceIdFormatted\n";
echo "  Receipt Date (8 digits ddMMyyyy): $receiptDateFormatted (from DB: {$fiscalReceipt['receipt_date']})\n";
echo "  Receipt Global No (10 digits): $receiptGlobalNoFormatted\n";
echo "  Receipt QR Data (16 chars): $qrDataFormatted\n\n";

// Check if QR code image exists
if (!empty($fiscalReceipt['receipt_qr_code'])) {
    echo "QR Code Image: EXISTS (length: " . strlen($fiscalReceipt['receipt_qr_code']) . " bytes)\n";
} else {
    echo "QR Code Image: NOT FOUND\n";
}

