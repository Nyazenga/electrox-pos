<?php
/**
 * Test QR code generation with 10-digit receiptGlobalNo
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== TESTING QR CODE WITH 10-DIGIT receiptGlobalNo ===\n\n";

// Get a recent fiscal receipt
$receipt = $db->getRow(
    "SELECT fr.*, fc.qr_url 
     FROM fiscal_receipts fr
     LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
     WHERE fr.receipt_global_no IS NOT NULL 
     ORDER BY fr.id DESC 
     LIMIT 1"
);

if (!$receipt) {
    echo "No fiscal receipts found in database.\n";
    exit(1);
}

echo "Testing with Receipt:\n";
echo "  Receipt ID: {$receipt['id']}\n";
echo "  Device ID: {$receipt['device_id']}\n";
echo "  Receipt Global No: {$receipt['receipt_global_no']}\n";
echo "  Receipt Date: {$receipt['receipt_date']}\n";
echo "  QR URL: {$receipt['qr_url']}\n";
echo "  QR Data: {$receipt['receipt_qr_data']}\n\n";

// Generate QR code with 10-digit receiptGlobalNo
try {
    $qrResult = ZimraQRCode::generateQRCode(
        $receipt['qr_url'],
        $receipt['device_id'],
        $receipt['receipt_date'],
        $receipt['receipt_global_no'],
        $receipt['receipt_qr_data']
    );
    
    echo "=== QR CODE GENERATED ===\n";
    echo "QR Code URL: {$qrResult['qrCode']}\n";
    echo "Verification Code: {$qrResult['verificationCode']}\n\n";
    
    // Parse the QR code URL
    $qrData = str_replace($receipt['qr_url'] . '/', '', $qrResult['qrCode']);
    
    // Extract parts
    // Format: deviceID(10) + receiptDate(8) + receiptGlobalNo(10) + receiptQrData(16) = 44 total
    if (strlen($qrData) >= 44) {
        $devicePart = substr($qrData, 0, 10);
        $datePart = substr($qrData, 10, 8);
        $globalNoPart = substr($qrData, 18, 10); // 10 digits
        $qrDataPart = substr($qrData, 28, 16);
        
        echo "QR Code Breakdown:\n";
        echo "  Device ID (10 digits): $devicePart\n";
        echo "  Receipt Date (8 digits): $datePart\n";
        echo "  Receipt Global No (10 digits): $globalNoPart\n";
        echo "  QR Data (16 digits): $qrDataPart\n\n";
        
        if (strlen($globalNoPart) === 10) {
            echo "✓ SUCCESS: receiptGlobalNo is correctly formatted as 10 digits!\n";
        } else {
            echo "✗ ERROR: receiptGlobalNo should be 10 digits, but found " . strlen($globalNoPart) . " digits\n";
        }
    }
    
    echo "\n=== TESTING URL ===\n";
    echo "Full URL: {$qrResult['qrCode']}\n";
    echo "You can test this URL in a browser to verify it works.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

