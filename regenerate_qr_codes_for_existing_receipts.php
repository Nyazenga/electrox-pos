<?php
/**
 * Regenerate QR codes for existing receipts that were generated with the wrong date
 * This fixes receipts that used serverDate instead of receiptDate field value
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

echo "=== Regenerating QR Codes for Existing Receipts ===\n\n";

$primaryDb = Database::getPrimaryInstance();

// Get all fiscal receipts that have receipt_qr_data
$fiscalReceipts = $primaryDb->getRows(
    "SELECT fr.*, fc.qr_url 
     FROM fiscal_receipts fr
     LEFT JOIN fiscal_config fc ON fc.branch_id = fr.branch_id AND fc.device_id = fr.device_id
     WHERE fr.receipt_qr_data IS NOT NULL 
     AND fr.receipt_qr_data != ''
     AND fr.submission_status = 'Submitted'
     ORDER BY fr.id DESC"
);

if (empty($fiscalReceipts)) {
    echo "No fiscal receipts found.\n";
    exit(0);
}

echo "Found " . count($fiscalReceipts) . " fiscal receipts to process.\n\n";

$updatedCount = 0;
$errorCount = 0;

foreach ($fiscalReceipts as $fiscalReceipt) {
    try {
        $receiptId = $fiscalReceipt['id'];
        $receiptGlobalNo = $fiscalReceipt['receipt_global_no'];
        
        echo "Processing receipt ID $receiptId (Global No: $receiptGlobalNo)...\n";
        
        // Get QR URL from config
        $qrUrl = $fiscalReceipt['qr_url'] ?? 'https://fdmstest.zimra.co.zw';
        
        // Use receipt_date from database (this should be the receiptDate we sent to ZIMRA)
        // Format: should be YYYY-MM-DD or YYYY-MM-DD HH:mm:ss or YYYY-MM-DDTHH:mm:ss
        $receiptDate = $fiscalReceipt['receipt_date'];
        
        if (empty($receiptDate)) {
            echo "  ⚠ Skipping: receipt_date is empty\n\n";
            $errorCount++;
            continue;
        }
        
        // Generate QR code using the CORRECT date (receipt_date from database, not serverDate)
        $qrCodeResult = ZimraQRCode::generateQRCode(
            $qrUrl,
            $fiscalReceipt['device_id'],
            $receiptDate, // This is the receiptDate field value we sent to ZIMRA
            $fiscalReceipt['receipt_global_no'],
            $fiscalReceipt['receipt_qr_data']
        );
        
        // Update fiscal receipt with new QR code
        $primaryDb->update('fiscal_receipts', [
            'receipt_qr_code' => $qrCodeResult['qrImage'], // Base64 encoded image
            'receipt_verification_code' => $qrCodeResult['verificationCode']
        ], ['id' => $receiptId]);
        
        echo "  ✓ Updated QR code (URL: " . substr($qrCodeResult['qrCode'], 0, 80) . "...)\n";
        echo "  ✓ Verification code: {$qrCodeResult['verificationCode']}\n\n";
        
        $updatedCount++;
        
    } catch (Exception $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n\n";
        $errorCount++;
    }
}

echo "=== SUMMARY ===\n";
echo "Total receipts: " . count($fiscalReceipts) . "\n";
echo "Successfully updated: $updatedCount\n";
echo "Errors: $errorCount\n";
echo "\nDone!\n";

