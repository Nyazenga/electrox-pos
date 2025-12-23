<?php
/**
 * Regenerate QR code for sale 91
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';
require_once APP_PATH . '/vendor/autoload.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

echo "=== Regenerating QR Code for Sale 91 ===\n\n";

$primaryDb = Database::getPrimaryInstance();

// Get fiscal receipt
$fiscalReceipt = $primaryDb->getRow(
    "SELECT * FROM fiscal_receipts WHERE sale_id = 91 ORDER BY id DESC LIMIT 1"
);

if (!$fiscalReceipt) {
    echo "✗ Fiscal receipt not found for sale 91\n";
    exit(1);
}

echo "✓ Fiscal receipt found:\n";
echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
echo "  Receipt Date: {$fiscalReceipt['receipt_date']}\n";
echo "  QR Data: {$fiscalReceipt['receipt_qr_data']}\n";
echo "  Current QR Image Length: " . strlen($fiscalReceipt['receipt_qr_code'] ?? '') . "\n\n";

// Get config for QR URL
$config = $primaryDb->getRow(
    "SELECT qr_url FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
    [':branch_id' => $fiscalReceipt['branch_id'], ':device_id' => $fiscalReceipt['device_id']]
);

$qrUrl = $config['qr_url'] ?? 'https://fdmstest.zimra.co.zw';

// Build QR code URL
$deviceIdFormatted = str_pad($fiscalReceipt['device_id'], 10, '0', STR_PAD_LEFT);
$date = new DateTime($fiscalReceipt['receipt_date']);
$receiptDateFormatted = $date->format('dmy');
$receiptGlobalNoFormatted = str_pad($fiscalReceipt['receipt_global_no'], 10, '0', STR_PAD_LEFT);
$qrDataFormatted = substr($fiscalReceipt['receipt_qr_data'], 0, 16);

$qrCodeString = rtrim($qrUrl, '/') . '/' . $deviceIdFormatted . $receiptDateFormatted . $receiptGlobalNoFormatted . $qrDataFormatted;

echo "QR Code String: $qrCodeString\n\n";

// Generate QR code image
$qrImageBase64 = null;

if (class_exists('TCPDF2DBarcode')) {
    try {
        echo "Generating QR code image using TCPDF2DBarcode...\n";
        $qr = new TCPDF2DBarcode($qrCodeString, 'QRCODE,L');
        $qrImageData = $qr->getBarcodePngData(4, 4, array(0, 0, 0));
        
        if ($qrImageData) {
            $qrImageBase64 = base64_encode($qrImageData);
            echo "✓ QR code image generated (length: " . strlen($qrImageBase64) . " bytes)\n";
            
            // Update fiscal receipt
            $primaryDb->update('fiscal_receipts', [
                'receipt_qr_code' => $qrImageBase64
            ], ['id' => $fiscalReceipt['id']]);
            
            echo "✓ QR code image saved to database\n";
        } else {
            echo "✗ QR code image generation returned empty data\n";
        }
    } catch (Exception $e) {
        echo "✗ Error generating QR code: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
} else {
    echo "✗ TCPDF2DBarcode class not found\n";
}

// Verify
$updatedReceipt = $primaryDb->getRow(
    "SELECT receipt_qr_code FROM fiscal_receipts WHERE id = :id",
    [':id' => $fiscalReceipt['id']]
);

if ($updatedReceipt && !empty($updatedReceipt['receipt_qr_code'])) {
    echo "\n✓ Verification: QR code image is now stored (length: " . strlen($updatedReceipt['receipt_qr_code']) . " bytes)\n";
} else {
    echo "\n✗ Verification failed: QR code image is still empty\n";
}

