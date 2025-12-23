<?php
/**
 * Test submitReceipt with correct counters from ZIMRA
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Testing submitReceipt with Correct Counters ===\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$api = new ZimraApi('Server', 'v1', true);

// Load certificate
$primaryDb = Database::getPrimaryInstance();
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

$privateKey = null;

// Try loading from database first
$certData = CertificateStorage::loadCertificate($deviceId);
if ($certData) {
    echo "✓ Certificate loaded from database\n";
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    $privateKey = $certData['privateKey'];
} elseif ($device && $device['certificate_pem'] && $device['private_key_pem']) {
    // Fallback: use plain text from database
    $api->setCertificate($device['certificate_pem'], $device['private_key_pem']);
    $privateKey = $device['private_key_pem'];
} else {
    echo "Registering device...\n";
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    if (isset($result['certificate'])) {
        $api->setCertificate($result['certificate'], $csrData['privateKey']);
        $privateKey = $csrData['privateKey'];
        
        // Save to database
        CertificateStorage::saveCertificate($deviceId, $result['certificate'], $csrData['privateKey']);
        echo "✓ Certificate saved to database\n";
    }
}

// Get current status to determine counters
echo "Getting device status...\n";
$status = $api->getStatus($deviceId);
echo "Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
echo "Last Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
echo "Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n\n";

if (($status['fiscalDayStatus'] ?? '') !== 'FiscalDayOpened') {
    echo "Opening fiscal day...\n";
    try {
        $openResult = $api->openDay($deviceId, date('Y-m-d\TH:i:s'));
        echo "✓ Fiscal day opened: " . ($openResult['fiscalDayNo'] ?? 'N/A') . "\n";
        $status = $api->getStatus($deviceId); // Refresh status
    } catch (Exception $e) {
        echo "⚠ " . $e->getMessage() . "\n";
    }
}

// Determine counters
// receiptGlobalNo should be lastReceiptGlobalNo + 1 (or 1 if null)
$lastReceiptGlobalNo = $status['lastReceiptGlobalNo'] ?? 0;
$receiptGlobalNo = $lastReceiptGlobalNo + 1;

// receiptCounter - check if we have previous receipts for this day
$fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
$previousReceipt = $primaryDb->getRow(
    "SELECT receipt_counter FROM fiscal_receipts WHERE device_id = :device_id AND fiscal_day_no = :fiscal_day_no ORDER BY receipt_counter DESC LIMIT 1",
    [':device_id' => $deviceId, ':fiscal_day_no' => $fiscalDayNo]
);
$receiptCounter = $previousReceipt ? ($previousReceipt['receipt_counter'] + 1) : 1;

echo "Using counters:\n";
echo "  Fiscal Day No: $fiscalDayNo\n";
echo "  Receipt Counter: $receiptCounter\n";
echo "  Receipt Global No: $receiptGlobalNo\n\n";

// Build minimal valid receipt
$receiptData = [
    'receiptType' => 'FiscalInvoice',
    'receiptCurrency' => 'USD',
    'receiptCounter' => $receiptCounter,
    'receiptGlobalNo' => $receiptGlobalNo,
    'receiptDate' => date('Y-m-d\TH:i:s'),
    'invoiceNo' => 'TEST-' . time(),
    'receiptTotal' => 115.00,
    'receiptLinesTaxInclusive' => true,
    'receiptLines' => [
        [
            'receiptLineType' => 'Sale',
            'receiptLineNo' => 1,
            'receiptLineHSCode' => '00000000',
            'receiptLineName' => 'Test Product',
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

// Get previous receipt hash
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
    $privateKey
);

$receiptData['receiptDeviceSignature'] = $deviceSignature;

echo "Submitting receipt...\n";
try {
    $result = $api->submitReceipt($deviceId, $receiptData);
    echo "✓ SUCCESS!\n";
    echo "  Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
    echo "  Receipt Global No: " . ($result['receiptGlobalNo'] ?? 'N/A') . "\n";
    echo "  Server Date: " . ($result['serverDate'] ?? 'N/A') . "\n";
    echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
    
    // Save to database
    if (isset($result['receiptID'])) {
        $primaryDb->insert('fiscal_receipts', [
            'invoice_id' => 0, // Test receipt
            'branch_id' => $device['branch_id'] ?? 1,
            'device_id' => $deviceId,
            'fiscal_day_no' => $fiscalDayNo,
            'receipt_type' => 'FiscalInvoice',
            'receipt_currency' => 'USD',
            'receipt_counter' => $receiptCounter,
            'receipt_global_no' => $result['receiptGlobalNo'],
            'invoice_no' => $receiptData['invoiceNo'],
            'receipt_date' => $receiptData['receiptDate'],
            'receipt_total' => $receiptData['receiptTotal'],
            'receipt_hash' => $deviceSignature['hash'],
            'receipt_device_signature' => json_encode($deviceSignature),
            'receipt_server_signature' => json_encode($result['receiptServerSignature'] ?? []),
            'receipt_id' => $result['receiptID'],
            'status' => 'Valid'
        ]);
        echo "  ✓ Receipt saved to database\n";
    }
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    
    // Show full error if available
    if (strpos($e->getMessage(), 'Response:') !== false) {
        $parts = explode('Response:', $e->getMessage());
        if (isset($parts[1])) {
            echo "\nFull response:\n";
            echo $parts[1] . "\n";
        }
    }
}

echo "\n=== Test Complete ===\n";

