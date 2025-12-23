<?php
/**
 * Complete Fiscalization Flow Test
 * Tests: Certificate -> Open Day -> Submit Receipt -> Generate QR -> PDF
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "COMPLETE FISCALIZATION FLOW TEST\n";
echo "========================================\n\n";

$deviceId = 30199;
$deviceSerialNo = 'electrox-1';

$primaryDb = Database::getPrimaryInstance();

// Step 1: Load certificate
echo "Step 1: Loading certificate...\n";
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    die("✗ No certificate found. Please register device first.\n");
}
echo "✓ Certificate loaded\n\n";

// Step 2: Initialize API
echo "Step 2: Initializing API...\n";
$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);
echo "✓ API initialized with certificate\n\n";

// Step 3: Get status and open fiscal day if needed
echo "Step 3: Checking fiscal day status...\n";
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
    } else {
        echo "✓ Fiscal day is already open\n";
    }
} catch (Exception $e) {
    echo "⚠ Could not get status: " . $e->getMessage() . "\n";
    echo "Proceeding with submitReceipt test anyway...\n";
    $fiscalDayNo = 1;
    $lastReceiptGlobalNo = 0;
}
echo "\n";

// Step 4: Submit receipt
echo "Step 4: Submitting test receipt...\n";
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
    'invoiceNo' => 'TEST-FLOW-' . time(),
    'receiptTotal' => 115.00,
    'receiptLinesTaxInclusive' => true,
    'receiptLines' => [
        [
            'receiptLineType' => 'Sale',
            'receiptLineNo' => 1,
            'receiptLineHSCode' => '00000000',
            'receiptLineName' => 'Test Product for Flow',
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
    
    // Step 5: Generate QR code
    echo "\nStep 5: Generating QR code...\n";
    $qrData = ZimraQRCode::generateReceiptQrData($deviceSignature);
    $verificationCode = ZimraQRCode::formatVerificationCode($deviceSignature['hash']);
    $qrUrl = ZimraQRCode::generateQRCodeUrl($qrData, 'https://fdmstest.zimra.co.zw');
    
    echo "✓ QR code data generated\n";
    echo "  Verification Code: $verificationCode\n";
    echo "  QR URL: $qrUrl\n";
    
    // Generate QR code image
    $qrImage = ZimraQRCode::generateQRCode($qrData, 'https://fdmstest.zimra.co.zw');
    if ($qrImage) {
        echo "✓ QR code image generated\n";
        echo "  Image size: " . strlen($qrImage) . " bytes\n";
    } else {
        echo "⚠ QR code image generation returned null\n";
    }
    
    // Step 6: Save to database
    echo "\nStep 6: Saving receipt to database...\n";
    $branch = $primaryDb->getRow("SELECT id FROM branches LIMIT 1");
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
    }
    
    echo "\n✓✓✓ FISCALIZATION FLOW COMPLETE! ✓✓✓\n";
    echo "\nReceipt Details:\n";
    echo "  Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
    echo "  Invoice No: " . $receiptData['invoiceNo'] . "\n";
    echo "  Verification Code: $verificationCode\n";
    echo "  QR Code: Generated\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";

