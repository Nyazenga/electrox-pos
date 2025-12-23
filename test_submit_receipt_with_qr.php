<?php
/**
 * Test submitReceipt and QR code generation together
 * Uses certificate from file (known to work)
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "TEST SUBMIT RECEIPT WITH QR CODE\n";
echo "========================================\n\n";

$deviceId = 30199;
$deviceSerialNo = 'electrox-1';

$primaryDb = Database::getPrimaryInstance();

// Load certificate from file if not in database
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    // Try loading from file
    $certFile = "certificate_$deviceId.pem";
    $keyFile = "private_key_$deviceId.pem";
    
    if (file_exists($certFile) && file_exists($keyFile)) {
        echo "Loading certificate from file...\n";
        $certData = [
            'certificate' => file_get_contents($certFile),
            'privateKey' => file_get_contents($keyFile)
        ];
        echo "✓ Certificate loaded from file\n";
        
        // Save to database
        CertificateStorage::saveCertificate($deviceId, $certData['certificate'], $certData['privateKey']);
        echo "✓ Certificate saved to database\n";
    } else {
        die("✗ No certificate found\n");
    }
} else {
    echo "✓ Certificate loaded from database\n";
}

// Initialize API
$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);

// Get status (may fail, but try)
echo "\nGetting fiscal day status...\n";
$fiscalDayNo = 1;
$lastReceiptGlobalNo = 0;
try {
    $status = $api->getStatus($deviceId);
    $fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
    $lastReceiptGlobalNo = $status['lastReceiptGlobalNo'] ?? 0;
    echo "✓ Status retrieved\n";
    echo "  Fiscal Day No: $fiscalDayNo\n";
    echo "  Last Receipt Global No: $lastReceiptGlobalNo\n";
} catch (Exception $e) {
    echo "⚠ Could not get status: " . $e->getMessage() . "\n";
    echo "Using defaults: Fiscal Day No: $fiscalDayNo, Receipt Global No: " . ($lastReceiptGlobalNo + 1) . "\n";
}

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

echo "\nSubmitting receipt...\n";
$receiptData = [
    'receiptType' => 'FiscalInvoice',
    'receiptCurrency' => 'USD',
    'receiptCounter' => $receiptCounter,
    'receiptGlobalNo' => $receiptGlobalNo,
    'receiptDate' => date('Y-m-d\TH:i:s'),
    'invoiceNo' => 'TEST-QR-' . time(),
    'receiptTotal' => 115.00,
    'receiptLinesTaxInclusive' => true,
    'receiptLines' => [
        [
            'receiptLineType' => 'Sale',
            'receiptLineNo' => 1,
            'receiptLineHSCode' => '00000000',
            'receiptLineName' => 'Test Product for QR',
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
    
    // Generate QR code
    echo "\nGenerating QR code...\n";
    $qrData = ZimraQRCode::generateReceiptQrData($deviceSignature);
    $verificationCode = ZimraQRCode::formatVerificationCode($qrData);
    $qrUrl = ZimraQRCode::generateQRCodeUrl($qrData, 'https://fdmstest.zimra.co.zw', $deviceId, $receiptData['receiptDate'], $result['receiptGlobalNo'] ?? $receiptGlobalNo);
    $qrImage = ZimraQRCode::generateQRCode($qrUrl);
    
    echo "✓ QR code generated\n";
    echo "  QR Data: $qrData\n";
    echo "  Verification Code: $verificationCode\n";
    echo "  QR URL: $qrUrl\n";
    echo "  QR Image: " . ($qrImage ? "Generated (" . strlen($qrImage) . " bytes)" : "Failed") . "\n";
    
    // Save to database
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
        'receipt_server_signature' => json_encode($result['receiptServerSignature'] ?? []),
        'receipt_id' => $result['receiptID'],
        'receipt_qr_code' => $qrImage,
        'receipt_qr_data' => $qrData,
        'receipt_verification_code' => $verificationCode,
        'submission_status' => 'Submitted',
        'submitted_at' => date('Y-m-d H:i:s')
    ]);
    
    if ($receiptId) {
        echo "\n✓ Receipt saved to database (ID: $receiptId)\n";
        echo "\n✓✓✓ RECEIPT SUBMITTED WITH QR CODE! ✓✓✓\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

