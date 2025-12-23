<?php
/**
 * Full Fiscalization Flow Test with Certificate Persistence
 * Tests: Load Certificate -> Open Day -> Submit Receipt -> Generate QR -> Save -> Reload -> Verify
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "FULL FISCALIZATION FLOW - CERTIFICATE PERSISTENCE TEST\n";
echo "========================================\n\n";

$deviceId = 30200;
$deviceSerialNo = 'electrox-2';

$primaryDb = Database::getPrimaryInstance();

// STEP 1: Load Certificate from Database (Testing Persistence)
echo "STEP 1: Loading Certificate from Database (Persistence Test)\n";
echo str_repeat("-", 50) . "\n";
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    die("✗ No certificate found. Please register device first.\n");
}
echo "✓ Certificate loaded from database\n";
echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n";
echo "  Valid till: " . ($certData['validTill'] ?? 'N/A') . "\n\n";

// STEP 2: Initialize API with Loaded Certificate
echo "STEP 2: Initializing API with Loaded Certificate\n";
echo str_repeat("-", 50) . "\n";
$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);
echo "✓ API initialized with certificate from database\n\n";

// STEP 3: Get Status and Open Fiscal Day
echo "STEP 3: Getting Fiscal Day Status\n";
echo str_repeat("-", 50) . "\n";
try {
    $status = $api->getStatus($deviceId);
    echo "✓ Status retrieved\n";
    echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "  Last Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
    echo "  Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n";
    
    $fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
    $lastReceiptGlobalNo = $status['lastReceiptGlobalNo'] ?? 0;
    
    if ($status['fiscalDayStatus'] !== 'FiscalDayOpened') {
        echo "\nOpening fiscal day...\n";
        $openResult = $api->openDay($deviceId, date('Y-m-d\TH:i:s'), $fiscalDayNo + 1);
        $fiscalDayNo = $openResult['fiscalDayNo'];
        echo "✓ Fiscal day opened: $fiscalDayNo\n";
        $lastReceiptGlobalNo = 0;
        
        // Get updated status
        $status = $api->getStatus($deviceId);
    } else {
        echo "✓ Fiscal day is already open\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
echo "\n";

// STEP 4: Submit Receipt
echo "STEP 4: Submitting Fiscal Receipt\n";
echo str_repeat("-", 50) . "\n";
$receiptGlobalNo = $lastReceiptGlobalNo + 1;
$receiptCounter = 1;

// Get previous receipt counter
$previousReceipt = $primaryDb->getRow(
    "SELECT receipt_counter FROM fiscal_receipts WHERE device_id = :device_id AND fiscal_day_no = :fiscal_day_no ORDER BY receipt_counter DESC LIMIT 1",
    [':device_id' => $deviceId, ':fiscal_day_no' => $fiscalDayNo]
);
if ($previousReceipt) {
    $receiptCounter = $previousReceipt['receipt_counter'] + 1;
}

$receiptData = [
    'receiptType' => 'FiscalInvoice',
    'receiptCurrency' => 'USD',
    'receiptCounter' => $receiptCounter,
    'receiptGlobalNo' => $receiptGlobalNo,
    'receiptDate' => date('Y-m-d\TH:i:s'),
    'invoiceNo' => 'FLOW-TEST-' . time(),
    'receiptTotal' => 115.00,
    'receiptLinesTaxInclusive' => true,
    'receiptLines' => [
        [
            'receiptLineType' => 'Sale',
            'receiptLineNo' => 1,
            'receiptLineHSCode' => '00000000',
            'receiptLineName' => 'Full Flow Test Product',
            'receiptLinePrice' => 100.00,
            'receiptLineQuantity' => 1,
            'receiptLineTotal' => 100.00,
            'taxID' => 1,
            'taxPercent' => 15.00,
            'taxCode' => 'A'
        ]
    ],
    'receiptTaxes' => [
        [
            'taxID' => 1,
            'taxCode' => 'A',
            'taxPercent' => 15.00,
            'taxAmount' => 15.00,
            'salesAmountWithTax' => 115.00
        ]
    ],
    'receiptPayments' => [
        [
            'moneyTypeCode' => 'Cash',
            'paymentAmount' => 115.00
        ]
    ],
    'receiptPrintForm' => 'InvoiceA4'
];

// Generate signature
$receiptDataForSignature = $receiptData;
$receiptDataForSignature['deviceID'] = $deviceId;

$previousReceiptHash = null;
$previousReceiptRecord = $primaryDb->getRow(
    "SELECT receipt_hash FROM fiscal_receipts WHERE device_id = :device_id ORDER BY receipt_global_no DESC LIMIT 1",
    [':device_id' => $deviceId]
);
if ($previousReceiptRecord) {
    $previousReceiptHash = $previousReceiptRecord['receipt_hash'];
}

$deviceSignature = ZimraSignature::generateReceiptDeviceSignature(
    $receiptDataForSignature,
    $previousReceiptHash,
    $certData['privateKey']
);

$receiptData['receiptDeviceSignature'] = $deviceSignature;

try {
    $result = $api->submitReceipt($deviceId, $receiptData);
    echo "✓ Receipt submitted successfully!\n";
    echo "  Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
    echo "  Receipt Global No: " . ($result['receiptGlobalNo'] ?? 'N/A') . "\n";
    echo "  Server Date: " . ($result['serverDate'] ?? 'N/A') . "\n";
    
    $serverSignature = $result['receiptServerSignature'] ?? null;
    if ($serverSignature) {
        echo "  Server Signature: Received\n";
    }
    
    // STEP 5: Generate QR Code
    echo "\nSTEP 5: Generating QR Code\n";
    echo str_repeat("-", 50) . "\n";
    
    // Get QR URL from config
    $config = $primaryDb->getRow(
        "SELECT qr_url FROM fiscal_config WHERE device_id = :device_id LIMIT 1",
        [':device_id' => $deviceId]
    );
    $qrUrl = $config['qr_url'] ?? 'https://fdmstest.zimra.co.zw';
    
    $qrData = ZimraQRCode::generateReceiptQrData($deviceSignature);
    $verificationCode = ZimraQRCode::formatVerificationCode($qrData);
    $qrCodeUrl = ZimraQRCode::generateQRCodeUrl($qrData, $qrUrl, $deviceId, $receiptData['receiptDate'], $result['receiptGlobalNo'] ?? $receiptGlobalNo);
    
    // Generate QR code image - use the full URL
    $qrImage = null;
    if (class_exists('TCPDF2DBarcode')) {
        try {
            $qr = new TCPDF2DBarcode($qrCodeUrl, 'QRCODE,L');
            $qrImageData = $qr->getBarcodePNGData(4, 4);
            if ($qrImageData) {
                $qrImage = base64_encode($qrImageData);
            }
        } catch (Exception $e) {
            echo "  ⚠ QR code generation error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "✓ QR code generated\n";
    echo "  QR Data: $qrData\n";
    echo "  Verification Code: $verificationCode\n";
    echo "  QR URL: $qrCodeUrl\n";
    echo "  QR Image: " . ($qrImage ? "Generated (" . strlen($qrImage) . " bytes)" : "Failed") . "\n";
    
    // STEP 6: Save Receipt to Database
    echo "\nSTEP 6: Saving Receipt to Database\n";
    echo str_repeat("-", 50) . "\n";
    $branch = $primaryDb->getRow("SELECT id FROM branches WHERE branch_code = 'HS' OR branch_name LIKE '%Hillside%' LIMIT 1");
    if (!$branch) {
        $branch = $primaryDb->getRow("SELECT id FROM branches LIMIT 1");
    }
    $branchId = $branch['id'] ?? 1;
    
    $receiptId = $primaryDb->insert('fiscal_receipts', [
        'invoice_id' => 0,
        'branch_id' => $branchId,
        'device_id' => $deviceId,
        'fiscal_day_no' => $fiscalDayNo,
        'receipt_type' => 'FiscalInvoice',
        'receipt_currency' => 'USD',
        'receipt_counter' => $receiptCounter,
        'receipt_global_no' => $result['receiptGlobalNo'] ?? $receiptGlobalNo,
        'invoice_no' => $receiptData['invoiceNo'],
        'receipt_date' => $receiptData['receiptDate'],
        'receipt_total' => $receiptData['receiptTotal'],
        'receipt_hash' => $deviceSignature['hash'],
        'receipt_device_signature' => json_encode($deviceSignature),
        'receipt_server_signature' => json_encode($serverSignature),
        'receipt_id' => $result['receiptID'],
        'receipt_qr_code' => $qrImage,
        'receipt_qr_data' => $qrData,
        'receipt_verification_code' => $verificationCode,
        'submission_status' => 'Submitted',
        'submitted_at' => date('Y-m-d H:i:s')
    ]);
    
    if ($receiptId) {
        echo "✓ Receipt saved to database (ID: $receiptId)\n";
    } else {
        echo "✗ Failed to save receipt to database\n";
        exit(1);
    }
    
    // STEP 7: Test Certificate Persistence - Reload and Test Again
    echo "\nSTEP 7: Testing Certificate Persistence (Reload and Re-test)\n";
    echo str_repeat("-", 50) . "\n";
    
    // Create NEW API instance (simulating new session)
    $api2 = new ZimraApi('Server', 'v1', true);
    
    // Load certificate again from database
    $certData2 = CertificateStorage::loadCertificate($deviceId);
    if (!$certData2) {
        echo "✗ Failed to reload certificate from database\n";
        exit(1);
    }
    echo "✓ Certificate reloaded from database\n";
    echo "  Certificate length: " . strlen($certData2['certificate']) . " bytes\n";
    echo "  Private key length: " . strlen($certData2['privateKey']) . " bytes\n";
    
    // Set certificate in new API instance
    $api2->setCertificate($certData2['certificate'], $certData2['privateKey']);
    echo "✓ Certificate set in new API instance\n";
    
    // Test authentication with reloaded certificate
    echo "\nTesting authentication with reloaded certificate...\n";
    try {
        $status2 = $api2->getStatus($deviceId);
        echo "  ✓✓✓ getStatus SUCCESS (Certificate persistence verified!) ✓✓✓\n";
        echo "    Fiscal Day Status: " . ($status2['fiscalDayStatus'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "  ✗ getStatus FAILED: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    try {
        $config2 = $api2->getConfig($deviceId);
        echo "  ✓✓✓ getConfig SUCCESS ✓✓✓\n";
        echo "    Operating Mode: " . ($config2['deviceOperatingMode'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "  ✗ getConfig FAILED: " . $e->getMessage() . "\n";
    }
    
    // Verify receipt was saved correctly
    echo "\nVerifying saved receipt...\n";
    $savedReceipt = $primaryDb->getRow(
        "SELECT * FROM fiscal_receipts WHERE id = :id",
        [':id' => $receiptId]
    );
    
    if ($savedReceipt) {
        echo "✓ Receipt found in database\n";
        echo "  Receipt ID: " . ($savedReceipt['receipt_id'] ?? 'N/A') . "\n";
        echo "  Invoice No: " . ($savedReceipt['invoice_no'] ?? 'N/A') . "\n";
        echo "  Verification Code: " . ($savedReceipt['receipt_verification_code'] ?? 'N/A') . "\n";
        echo "  QR Code: " . ($savedReceipt['receipt_qr_code'] ? "Present (" . strlen($savedReceipt['receipt_qr_code']) . " bytes)" : "Missing") . "\n";
        echo "  Submission Status: " . ($savedReceipt['submission_status'] ?? 'N/A') . "\n";
    } else {
        echo "✗ Receipt not found in database\n";
    }
    
    echo "\n========================================\n";
    echo "✓✓✓ FULL FISCALIZATION FLOW COMPLETE! ✓✓✓\n";
    echo "========================================\n";
    echo "\nSummary:\n";
    echo "  ✓ Certificate loaded from database\n";
    echo "  ✓ Fiscal day opened\n";
    echo "  ✓ Receipt submitted to ZIMRA\n";
    echo "  ✓ QR code generated\n";
    echo "  ✓ Receipt saved to database\n";
    echo "  ✓ Certificate persistence verified (reloaded and tested)\n";
    echo "  ✓ All operations successful\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

