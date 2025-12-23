<?php
/**
 * Regenerate all QR codes with 10-digit receiptGlobalNo format
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== REGENERATING ALL QR CODES WITH 10-DIGIT receiptGlobalNo ===\n\n";

// Get all fiscal receipts
$receipts = $db->getRows(
    "SELECT fr.*, fc.qr_url 
     FROM fiscal_receipts fr
     LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
     WHERE fr.receipt_global_no IS NOT NULL 
     AND fr.receipt_qr_data IS NOT NULL
     ORDER BY fr.id"
);

echo "Found " . count($receipts) . " receipts to regenerate\n\n";

$updated = 0;
$errors = 0;

foreach ($receipts as $receipt) {
    try {
        if (empty($receipt['qr_url'])) {
            echo "Receipt ID {$receipt['id']}: Skipping - no QR URL\n";
            continue;
        }
        
        // Regenerate QR code with 10-digit receiptGlobalNo
        $qrResult = ZimraQRCode::generateQRCode(
            $receipt['qr_url'],
            $receipt['device_id'],
            $receipt['receipt_date'],
            $receipt['receipt_global_no'],
            $receipt['receipt_qr_data']
        );
        
        // Update database
        $db->update('fiscal_receipts', [
            'receipt_qr_code' => $qrResult['qrImage'],
            'receipt_verification_code' => $qrResult['verificationCode']
        ], ['id' => $receipt['id']]);
        
        $updated++;
        
        if ($updated % 10 == 0) {
            echo "Updated {$updated} receipts...\n";
        }
        
    } catch (Exception $e) {
        $errors++;
        echo "Error updating receipt ID {$receipt['id']}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== REGENERATION COMPLETE ===\n";
echo "Updated: {$updated} receipts\n";
echo "Errors: {$errors} receipts\n\n";

// Test a sample QR code
if ($updated > 0) {
    $sampleReceipt = $db->getRow(
        "SELECT fr.*, fc.qr_url 
         FROM fiscal_receipts fr
         LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
         WHERE fr.receipt_global_no = 103
         ORDER BY fr.id DESC 
         LIMIT 1"
    );
    
    if ($sampleReceipt) {
        echo "=== SAMPLE QR CODE (Receipt Global No: 103) ===\n";
        $qrResult = ZimraQRCode::generateQRCode(
            $sampleReceipt['qr_url'],
            $sampleReceipt['device_id'],
            $sampleReceipt['receipt_date'],
            $sampleReceipt['receipt_global_no'],
            $sampleReceipt['receipt_qr_data']
        );
        
        echo "Sample URL: {$qrResult['qrCode']}\n";
        echo "\nExpected format: https://fdmstest.zimra.co.zw/0000030199231220250000000103[QR_DATA]\n";
        echo "You can test this URL in a browser to verify it works.\n";
    }
}

