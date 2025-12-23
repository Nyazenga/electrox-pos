<?php
/**
 * Test QR Code Generation
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';
require_once APP_PATH . '/includes/zimra_signature.php';

echo "=== Testing QR Code Generation ===\n\n";

// Create a test device signature
$deviceSignature = [
    'hash' => base64_encode(hash('sha256', 'test-receipt-data', true)),
    'signature' => base64_encode('test-signature-data')
];

echo "1. Generating QR code data...\n";
$qrData = ZimraQRCode::generateReceiptQrData($deviceSignature);
echo "✓ QR data generated: $qrData\n";

echo "\n2. Formatting verification code...\n";
$verificationCode = ZimraQRCode::formatVerificationCode($deviceSignature['hash']);
echo "✓ Verification code: $verificationCode\n";

echo "\n3. Generating QR code URL...\n";
$qrUrl = ZimraQRCode::generateQRCodeUrl($qrData, 'https://fdmstest.zimra.co.zw');
echo "✓ QR URL: $qrUrl\n";

echo "\n4. Generating QR code image...\n";
$qrImage = ZimraQRCode::generateQRCode($qrData, 'https://fdmstest.zimra.co.zw');
if ($qrImage) {
    echo "✓ QR code image generated\n";
    echo "  Image size: " . strlen($qrImage) . " bytes\n";
    
    // Save to file for testing
    $testFile = 'test_qr_code.png';
    file_put_contents($testFile, $qrImage);
    echo "  Saved to: $testFile\n";
} else {
    echo "✗ QR code image generation failed\n";
}

echo "\n=== Test Complete ===\n";

