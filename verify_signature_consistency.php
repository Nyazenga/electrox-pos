<?php
/**
 * Verify that we're using the exact same ReceiptDeviceSignature 
 * that was sent to ZIMRA in the payload
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== VERIFYING SIGNATURE CONSISTENCY ===\n\n";

// Get receipt #103
$receipt = $db->getRow(
    "SELECT fr.*, fc.qr_url 
     FROM fiscal_receipts fr
     LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
     WHERE fr.receipt_global_no = 103
     ORDER BY fr.id DESC 
     LIMIT 1"
);

if (!$receipt) {
    echo "Receipt #103 not found.\n";
    exit(1);
}

echo "Receipt #103 Details:\n";
echo "  Receipt ID: {$receipt['id']}\n";
echo "  Sale ID: {$receipt['sale_id']}\n";
echo "  Receipt Global No: {$receipt['receipt_global_no']}\n";
echo "  Receipt Date: {$receipt['receipt_date']}\n";
echo "  Device ID: {$receipt['device_id']}\n\n";

// Get stored ReceiptDeviceSignature
$storedSignature = json_decode($receipt['receipt_device_signature'], true);
echo "Stored ReceiptDeviceSignature:\n";
echo "  Hash: {$storedSignature['hash']}\n";
echo "  Signature (first 50 chars): " . substr($storedSignature['signature'], 0, 50) . "...\n\n";

// Try to regenerate the signature to see if it matches
// We need the receipt data, previous hash, and private key
$device = $db->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = ? AND branch_id = ?",
    [$receipt['device_id'], $receipt['branch_id']]
);

if (!$device || empty($device['private_key_pem'])) {
    echo "ERROR: Device or private key not found\n";
    exit(1);
}

// Get the sale to reconstruct receipt data
$saleDb = Database::getInstance();
$sale = $saleDb->getRow("SELECT * FROM sales WHERE id = ?", [$receipt['sale_id']]);

if (!$sale) {
    echo "ERROR: Sale not found\n";
    exit(1);
}

echo "NOTE: To fully verify, we would need to reconstruct the exact receipt data\n";
echo "that was sent to ZIMRA. This is complex as it involves tax calculations.\n\n";

// However, we can verify the QR data generation is correct
echo "=== VERIFYING QR DATA GENERATION ===\n";
$generatedQrData = ZimraQRCode::generateReceiptQrData($storedSignature);
echo "Generated QR Data from stored signature: $generatedQrData\n";
echo "Stored QR Data: {$receipt['receipt_qr_data']}\n";

if ($generatedQrData === $receipt['receipt_qr_data']) {
    echo "✓ QR Data matches - we're using the correct signature format\n\n";
} else {
    echo "✗ QR Data mismatch!\n\n";
}

// Check what the QR code URL looks like
echo "=== CURRENT QR CODE URL ===\n";
$qrResult = ZimraQRCode::generateQRCode(
    $receipt['qr_url'],
    $receipt['device_id'],
    $receipt['receipt_date'],
    $receipt['receipt_global_no'],
    $receipt['receipt_qr_data']
);

echo "QR Code URL: {$qrResult['qrCode']}\n";
echo "Verification Code: {$qrResult['verificationCode']}\n\n";

// Parse the URL to verify format
$urlParts = parse_url($qrResult['qrCode']);
$path = str_replace($urlParts['scheme'] . '://' . $urlParts['host'] . '/', '', $qrResult['qrCode']);

echo "URL Breakdown:\n";
echo "  Total length: " . strlen($path) . " characters\n";
echo "  Device ID (10): " . substr($path, 0, 10) . "\n";
echo "  Receipt Date (8): " . substr($path, 10, 8) . "\n";
echo "  Receipt Global No (10): " . substr($path, 18, 10) . "\n";
echo "  QR Data (16): " . substr($path, 28, 16) . "\n\n";

// According to ZIMRA spec, the format should be:
// qrUrl/deviceID(10)receiptDate(8)receiptGlobalNo(10)receiptQrData(16)
// Total: 10 + 8 + 10 + 16 = 44 characters

if (strlen($path) === 44) {
    echo "✓ URL format is correct (44 characters total)\n";
} else {
    echo "✗ URL format is incorrect (expected 44, got " . strlen($path) . ")\n";
}

echo "\n=== IMPORTANT NOTES ===\n";
echo "The 'Invoice is not yet received' message from ZIMRA could mean:\n";
echo "1. QR code verification is asynchronous and takes time\n";
echo "2. The signature format might need to match ZIMRA's expected format exactly\n";
echo "3. The QR data might need to come from ZIMRA's receiptServerSignature instead\n";
echo "\nHowever, since the page shows 'last submitted invoice: 23/12/2025 16:48:46'\n";
echo "which matches your submission time, ZIMRA HAS received the invoice.\n";
echo "The QR verification might just be delayed or checking a different signature.\n";

