<?php
/**
 * Debug script to check why QR code isn't showing on receipt
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

$id = 91;

echo "=== Debugging Receipt QR Code for Sale $id ===\n\n";

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get sale
$sale = $db->getRow("SELECT * FROM sales WHERE id = :id", [':id' => $id]);
if (!$sale) {
    echo "✗ Sale $id not found\n";
    exit(1);
}

echo "✓ Sale $id found\n";
echo "  Branch ID: " . ($sale['branch_id'] ?? 'NULL') . "\n";
echo "  Fiscalized: " . ($sale['fiscalized'] ?? 'NULL') . "\n";
echo "  Fiscal Details: " . (empty($sale['fiscal_details']) ? 'EMPTY' : 'EXISTS') . "\n\n";

// Get fiscal receipt
$fiscalReceipt = $primaryDb->getRow(
    "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
     FROM fiscal_receipts fr
     LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
     LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
     WHERE fr.sale_id = :sale_id
     LIMIT 1",
    [':sale_id' => $id]
);

if (!$fiscalReceipt) {
    echo "✗ No fiscal receipt found for sale $id\n";
    exit(1);
}

echo "✓ Fiscal receipt found:\n";
echo "  Receipt Global No: " . $fiscalReceipt['receipt_global_no'] . "\n";
echo "  Device ID: " . $fiscalReceipt['device_id'] . "\n";
echo "  Receipt Date: " . $fiscalReceipt['receipt_date'] . "\n";
echo "  QR Data: " . $fiscalReceipt['receipt_qr_data'] . "\n";
echo "  QR Code Image: " . (empty($fiscalReceipt['receipt_qr_code']) ? 'EMPTY' : strlen($fiscalReceipt['receipt_qr_code']) . ' bytes') . "\n";
echo "  QR URL: " . ($fiscalReceipt['qr_url'] ?? 'NULL') . "\n";
echo "  Verification Code: " . ($fiscalReceipt['receipt_verification_code'] ?? 'NULL') . "\n\n";

// Build fiscal details
$fiscalDetails = [
    'receipt_global_no' => $fiscalReceipt['receipt_global_no'],
    'device_id' => $fiscalReceipt['device_id'],
    'verification_code' => $fiscalReceipt['receipt_verification_code'],
    'qr_code' => $fiscalReceipt['receipt_qr_data']
];

echo "Fiscal Details Array:\n";
print_r($fiscalDetails);
echo "\n";

// Check QR code display conditions
echo "QR Code Display Checks:\n";
echo "  1. fiscalDetails exists: " . (isset($fiscalDetails) ? 'YES' : 'NO') . "\n";
echo "  2. fiscalReceipt exists: " . (isset($fiscalReceipt) ? 'YES' : 'NO') . "\n";
echo "  3. Condition (fiscalDetails && fiscalReceipt): " . (($fiscalDetails && $fiscalReceipt) ? 'TRUE' : 'FALSE') . "\n";
echo "  4. receipt_qr_code isset: " . (isset($fiscalReceipt['receipt_qr_code']) ? 'YES' : 'NO') . "\n";
echo "  5. receipt_qr_code !empty: " . (!empty($fiscalReceipt['receipt_qr_code']) ? 'YES' : 'NO') . "\n";
echo "  6. receipt_qr_code strlen > 0: " . (strlen($fiscalReceipt['receipt_qr_code'] ?? '') > 0 ? 'YES' : 'NO') . "\n";
echo "  7. receipt_qr_data isset: " . (isset($fiscalReceipt['receipt_qr_data']) ? 'YES' : 'NO') . "\n";
echo "  8. receipt_qr_data !empty: " . (!empty($fiscalReceipt['receipt_qr_data']) ? 'YES' : 'NO') . "\n\n";

// Test QR code string generation
if (!empty($fiscalReceipt['receipt_qr_data'])) {
    $qrUrl = $fiscalReceipt['qr_url'] ?? 'https://fdmstest.zimra.co.zw';
    $deviceId = $fiscalReceipt['device_id'] ?? '';
    $receiptDate = $fiscalReceipt['receipt_date'] ?? '';
    $receiptGlobalNo = $fiscalReceipt['receipt_global_no'] ?? '';
    
    if ($deviceId && $receiptDate && $receiptGlobalNo) {
        $deviceIdFormatted = str_pad($deviceId, 10, '0', STR_PAD_LEFT);
        $date = new DateTime($receiptDate);
        $receiptDateFormatted = $date->format('dmy');
        $receiptGlobalNoFormatted = str_pad($receiptGlobalNo, 10, '0', STR_PAD_LEFT);
        $qrDataFormatted = substr($fiscalReceipt['receipt_qr_data'], 0, 16);
        $qrCodeString = rtrim($qrUrl, '/') . '/' . $deviceIdFormatted . $receiptDateFormatted . $receiptGlobalNoFormatted . $qrDataFormatted;
        
        echo "QR Code String Generated:\n";
        echo "  $qrCodeString\n";
        echo "  Length: " . strlen($qrCodeString) . " characters\n\n";
    }
}

echo "=== End Debug ===\n";

