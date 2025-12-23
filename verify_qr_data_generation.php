<?php
/**
 * Verify QR data generation from ReceiptDeviceSignature
 * Check if we're using the correct signature format
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== VERIFYING QR DATA GENERATION ===\n\n";

// Get a recent fiscal receipt
$receipt = $db->getRow(
    "SELECT fr.*, fc.qr_url 
     FROM fiscal_receipts fr
     LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
     WHERE fr.receipt_global_no = 103
     AND fr.receipt_device_signature IS NOT NULL
     ORDER BY fr.id DESC 
     LIMIT 1"
);

if (!$receipt) {
    echo "No fiscal receipt found (looking for receiptGlobalNo = 103).\n";
    exit(1);
}

echo "Receipt Details:\n";
echo "  Receipt ID: {$receipt['id']}\n";
echo "  Receipt Global No: {$receipt['receipt_global_no']}\n";
echo "  Receipt Date: {$receipt['receipt_date']}\n";
echo "  Device ID: {$receipt['device_id']}\n";
echo "  Stored QR Data: {$receipt['receipt_qr_data']}\n";
echo "  Verification Code: {$receipt['receipt_verification_code']}\n\n";

// Get the stored ReceiptDeviceSignature
$receiptDeviceSignature = json_decode($receipt['receipt_device_signature'], true);

if (!$receiptDeviceSignature || !isset($receiptDeviceSignature['signature'])) {
    echo "ERROR: Could not parse receipt_device_signature from database\n";
    exit(1);
}

echo "ReceiptDeviceSignature from Database:\n";
echo "  Hash (base64): {$receiptDeviceSignature['hash']}\n";
echo "  Signature (base64): " . substr($receiptDeviceSignature['signature'], 0, 50) . "...\n";
echo "  Signature length: " . strlen($receiptDeviceSignature['signature']) . " characters\n\n";

// Verify QR data generation
echo "=== QR DATA GENERATION VERIFICATION ===\n";

// Step 1: Decode base64 signature to binary
$signatureBinary = base64_decode($receiptDeviceSignature['signature']);
echo "Step 1: Base64 decode signature\n";
echo "  Binary length: " . strlen($signatureBinary) . " bytes\n";

// Step 2: Convert binary to hexadecimal
$signatureHex = bin2hex($signatureBinary);
echo "\nStep 2: Convert binary to hexadecimal\n";
echo "  Hex length: " . strlen($signatureHex) . " characters\n";
echo "  Hex (first 100 chars): " . substr($signatureHex, 0, 100) . "...\n";

// Step 3: Generate MD5 hash of hexadecimal
$md5Hash = md5($signatureHex);
echo "\nStep 3: Generate MD5 hash of hexadecimal\n";
echo "  MD5 hash: $md5Hash\n";
echo "  MD5 hash length: " . strlen($md5Hash) . " characters\n";

// Step 4: Get first 16 characters (uppercase)
$qrData = strtoupper(substr($md5Hash, 0, 16));
echo "\nStep 4: Get first 16 characters (uppercase)\n";
echo "  QR Data: $qrData\n";
echo "  QR Data length: " . strlen($qrData) . " characters\n";

// Compare with stored value
echo "\n=== COMPARISON ===\n";
echo "Stored QR Data: {$receipt['receipt_qr_data']}\n";
echo "Generated QR Data: $qrData\n";

if ($receipt['receipt_qr_data'] === $qrData) {
    echo "✓ MATCH: Stored QR data matches generated QR data\n";
} else {
    echo "✗ MISMATCH: Stored QR data does NOT match generated QR data\n";
    echo "\nThis could indicate:\n";
    echo "  1. The signature format is incorrect\n";
    echo "  2. We're using a different signature than what was sent to ZIMRA\n";
    echo "  3. The QR data generation algorithm is incorrect\n";
}

// Test with generateReceiptQrData function
echo "\n=== TESTING generateReceiptQrData FUNCTION ===\n";
$generatedQrData = ZimraQRCode::generateReceiptQrData($receiptDeviceSignature);
echo "Function generated QR Data: $generatedQrData\n";

if ($generatedQrData === $qrData) {
    echo "✓ Function matches manual calculation\n";
} else {
    echo "✗ Function does NOT match manual calculation\n";
}

// Generate full QR code URL
echo "\n=== FULL QR CODE URL ===\n";
try {
    $qrResult = ZimraQRCode::generateQRCode(
        $receipt['qr_url'],
        $receipt['device_id'],
        $receipt['receipt_date'],
        $receipt['receipt_global_no'],
        $qrData // Use the verified QR data
    );
    
    echo "Generated QR Code URL: {$qrResult['qrCode']}\n";
    echo "Verification Code: {$qrResult['verificationCode']}\n";
    
    // Parse to verify format
    $urlParts = parse_url($qrResult['qrCode']);
    $path = str_replace($urlParts['scheme'] . '://' . $urlParts['host'] . '/', '', $qrResult['qrCode']);
    
    echo "\nURL Breakdown:\n";
    echo "  Device ID (10): " . substr($path, 0, 10) . "\n";
    echo "  Receipt Date (8): " . substr($path, 10, 8) . "\n";
    echo "  Receipt Global No (10): " . substr($path, 18, 10) . "\n";
    echo "  QR Data (16): " . substr($path, 28, 16) . "\n";
    
    // Expected URL based on user's example
    echo "\nExpected URL format (from your example):\n";
    echo "  https://fdmstest.zimra.co.zw/0000030199231220250000000103[QR_DATA]\n";
    echo "\nOur generated URL:\n";
    echo "  {$qrResult['qrCode']}\n";
    
} catch (Exception $e) {
    echo "ERROR generating QR code: " . $e->getMessage() . "\n";
}

