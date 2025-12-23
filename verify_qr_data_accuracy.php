<?php
/**
 * HONEST VERIFICATION: What can we actually verify about QR data?
 * 
 * We can verify:
 * 1. Our algorithm matches our implementation (internal consistency)
 * 2. We follow our interpretation of ZIMRA docs
 * 
 * We CANNOT verify:
 * 1. If it matches what ZIMRA actually expects (their QR verification shows "not received")
 * 2. If our interpretation of "hexadecimal format" is correct
 * 3. If ZIMRA calculates QR data the same way we do
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== HONEST ASSESSMENT: WHAT CAN WE VERIFY? ===\n\n";

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
echo "  Receipt Global No: {$receipt['receipt_global_no']}\n";
echo "  Receipt Date: {$receipt['receipt_date']}\n";
echo "  Stored QR Data: {$receipt['receipt_qr_data']}\n\n";

// Get stored device signature
$deviceSignature = json_decode($receipt['receipt_device_signature'], true);

echo "=== WHAT WE CAN VERIFY (INTERNAL CONSISTENCY) ===\n\n";

echo "1. Our Algorithm Matches Our Implementation:\n";
$generatedQrData = ZimraQRCode::generateReceiptQrData($deviceSignature);
if ($generatedQrData === $receipt['receipt_qr_data']) {
    echo "   ✓ YES - Generated QR data matches stored QR data\n";
    echo "   This means: Our algorithm is consistent with what we stored\n\n";
} else {
    echo "   ✗ NO - There's a bug in our code\n\n";
}

echo "2. We Follow Our Interpretation of ZIMRA Docs:\n";
echo "   ZIMRA says: 'First 16 characters of MD5 hash from ReceiptDeviceSignature hexadecimal format'\n";
echo "   Our interpretation:\n";
echo "     a) ReceiptDeviceSignature['signature'] is base64 encoded\n";
echo "     b) Decode base64 to binary\n";
echo "     c) Convert binary to hexadecimal (bin2hex)\n";
echo "     d) MD5 hash the hex string\n";
echo "     e) Take first 16 characters (uppercase)\n\n";

// Demonstrate the process
$signatureBase64 = $deviceSignature['signature'];
$signatureBinary = base64_decode($signatureBase64);
$signatureHex = bin2hex($signatureBinary);
$md5Hash = md5($signatureHex);
$qrData = strtoupper(substr($md5Hash, 0, 16));

echo "   Process demonstration:\n";
echo "     Signature (base64, first 50 chars): " . substr($signatureBase64, 0, 50) . "...\n";
echo "     Binary length: " . strlen($signatureBinary) . " bytes\n";
echo "     Hex length: " . strlen($signatureHex) . " characters\n";
echo "     MD5 hash: $md5Hash\n";
echo "     QR Data (first 16 chars): $qrData\n";
echo "     Stored QR Data: {$receipt['receipt_qr_data']}\n";
if ($qrData === $receipt['receipt_qr_data']) {
    echo "   ✓ Our interpretation produces the stored QR data\n\n";
} else {
    echo "   ✗ Our interpretation does NOT match stored QR data\n\n";
}

echo "=== WHAT WE CANNOT VERIFY (ZIMRA EXPECTATIONS) ===\n\n";

echo "1. Does ZIMRA Use The Same Algorithm?\n";
echo "   ✗ UNKNOWN - We have no way to verify this\n";
echo "   ZIMRA's QR verification shows 'Invoice is not yet received'\n";
echo "   This could mean:\n";
echo "     - Our QR data is wrong\n";
echo "     - ZIMRA's verification system is delayed\n";
echo "     - ZIMRA uses a different algorithm\n\n";

echo "2. Is Our Interpretation of 'Hexadecimal Format' Correct?\n";
echo "   ✗ UNKNOWN - 'Hexadecimal format' could mean:\n";
echo "     Option A (OUR CURRENT): Base64 signature → Binary → Hex → MD5 → First 16 chars\n";
echo "     Option B: Signature is already in hex format → MD5 → First 16 chars\n";
echo "     Option C: Some other format entirely\n\n";

echo "3. Should We Use Device Signature or Server Signature?\n";
echo "   ✓ We use Device Signature (what we sent to ZIMRA)\n";
echo "   ✗ But we cannot verify if this is what ZIMRA expects\n";
echo "   Documentation says 'ReceiptDeviceSignature' which implies device signature\n\n";

echo "=== CURRENT QR CODE URL ===\n";
$qrResult = ZimraQRCode::generateQRCode(
    $receipt['qr_url'],
    $receipt['device_id'],
    $receipt['receipt_date'],
    $receipt['receipt_global_no'],
    $receipt['receipt_qr_data']
);
echo "QR Code URL: {$qrResult['qrCode']}\n";
echo "\n";
echo "When visiting this URL, ZIMRA shows: 'Invoice is not yet received'\n";
echo "Even though ZIMRA shows 'last submitted invoice: 23/12/2025 16:48:46'\n";
echo "\n";

echo "=== CONCLUSION ===\n";
echo "We can ONLY verify:\n";
echo "  ✓ Our code is internally consistent\n";
echo "  ✓ We follow our interpretation of the documentation\n";
echo "\n";
echo "We CANNOT verify:\n";
echo "  ✗ If our QR data matches what ZIMRA expects\n";
echo "  ✗ If ZIMRA uses the same algorithm\n";
echo "  ✗ If our interpretation of 'hexadecimal format' is correct\n";
echo "\n";
echo "TO ACTUALLY VERIFY CORRECTNESS:\n";
echo "  1. ZIMRA's QR verification system would need to work\n";
echo "  2. Or ZIMRA would need to provide a working example\n";
echo "  3. Or ZIMRA would need to confirm our implementation\n";

