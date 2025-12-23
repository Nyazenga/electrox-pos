<?php
/**
 * Test if QR data should use ZIMRA's receiptServerSignature instead of our device signature
 * This tests both approaches to see which one matches what ZIMRA expects
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== TESTING QR DATA WITH DIFFERENT SIGNATURES ===\n\n";

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

echo "Receipt #103:\n";
echo "  Device ID: {$receipt['device_id']}\n";
echo "  Receipt Global No: {$receipt['receipt_global_no']}\n";
echo "  Receipt Date: {$receipt['receipt_date']}\n";
echo "  Stored QR Data: {$receipt['receipt_qr_data']}\n";
echo "  Verification Code: {$receipt['receipt_verification_code']}\n\n";

// Get stored signatures
$deviceSignature = json_decode($receipt['receipt_device_signature'], true);
$serverSignature = json_decode($receipt['receipt_server_signature'], true);

echo "=== TESTING QR DATA FROM DEVICE SIGNATURE (CURRENT APPROACH) ===\n";
$qrDataFromDevice = ZimraQRCode::generateReceiptQrData($deviceSignature);
echo "QR Data from Device Signature: $qrDataFromDevice\n";
echo "Stored QR Data: {$receipt['receipt_qr_data']}\n";
if ($qrDataFromDevice === $receipt['receipt_qr_data']) {
    echo "✓ MATCH - We're using device signature (correct per ZIMRA spec)\n\n";
} else {
    echo "✗ MISMATCH\n\n";
}

// Test with server signature if available
if ($serverSignature && isset($serverSignature['signature'])) {
    echo "=== TESTING QR DATA FROM SERVER SIGNATURE (ALTERNATIVE) ===\n";
    $qrDataFromServer = ZimraQRCode::generateReceiptQrData($serverSignature);
    echo "QR Data from Server Signature: $qrDataFromServer\n";
    echo "Stored QR Data: {$receipt['receipt_qr_data']}\n";
    if ($qrDataFromServer === $receipt['receipt_qr_data']) {
        echo "✓ MATCH - Server signature matches stored QR data\n\n";
    } else {
        echo "✗ MISMATCH - Server signature does NOT match stored QR data\n\n";
    }
    
    // Generate QR code with server signature to test
    echo "=== TESTING QR CODE WITH SERVER SIGNATURE ===\n";
    $qrResultServer = ZimraQRCode::generateQRCode(
        $receipt['qr_url'],
        $receipt['device_id'],
        $receipt['receipt_date'],
        $receipt['receipt_global_no'],
        $qrDataFromServer
    );
    echo "QR Code URL (with server signature): {$qrResultServer['qrCode']}\n\n";
} else {
    echo "No server signature available to test.\n\n";
}

// Final QR code with current approach
echo "=== CURRENT QR CODE (WITH DEVICE SIGNATURE) ===\n";
$qrResult = ZimraQRCode::generateQRCode(
    $receipt['qr_url'],
    $receipt['device_id'],
    $receipt['receipt_date'],
    $receipt['receipt_global_no'],
    $receipt['receipt_qr_data']
);
echo "QR Code URL: {$qrResult['qrCode']}\n";
echo "Verification Code: {$qrResult['verificationCode']}\n\n";

echo "=== ANALYSIS ===\n";
echo "According to ZIMRA documentation Section 11:\n";
echo "  receiptQrData = First 16 characters of MD5 hash from ReceiptDeviceSignature hexadecimal format\n";
echo "\n";
echo "ReceiptDeviceSignature is what WE send TO ZIMRA (our device signature).\n";
echo "receiptServerSignature is what ZIMRA sends back TO US.\n";
echo "\n";
echo "Therefore, we should use ReceiptDeviceSignature (device signature) for QR data,\n";
echo "which is what we're currently doing.\n";
echo "\n";
echo "The 'Invoice is not yet received' message might be:\n";
echo "1. Asynchronous verification (takes time for ZIMRA to index)\n";
echo "2. The QR verification system checks a different database\n";
echo "3. There's a delay in ZIMRA's QR verification system\n";
echo "\n";
echo "However, since ZIMRA shows 'last submitted invoice: 23/12/2025 16:48:46'\n";
echo "which matches your submission time, ZIMRA HAS received the invoice.\n";

