<?php
/**
 * Detailed test of submitReceipt endpoint
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Detailed submitReceipt Test ===\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$api = new ZimraApi('Server', 'v1', true);

// Load certificate and private key
$primaryDb = Database::getPrimaryInstance();
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

$privateKey = null;

if ($device && $device['certificate_pem'] && $device['private_key_pem']) {
    echo "✓ Using stored certificate\n";
    $api->setCertificate($device['certificate_pem'], $device['private_key_pem']);
    $privateKey = $device['private_key_pem'];
} else {
    echo "Registering device...\n";
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    if (isset($result['certificate'])) {
        $api->setCertificate($result['certificate'], $csrData['privateKey']);
        $privateKey = $csrData['privateKey'];
        echo "✓ Device registered\n";
    }
}

if (!$privateKey) {
    die("✗ No private key available\n");
}

// Check fiscal day status
echo "\nChecking fiscal day status...\n";
$status = $api->getStatus($deviceId);
echo "Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
echo "Last Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
echo "Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n\n";

// Use existing open day or open new one
$fiscalDayNo = ($status['lastFiscalDayNo'] ?? 0);
$receiptGlobalNo = ($status['lastReceiptGlobalNo'] ?? 0) + 1;
$receiptCounter = 1;

if ($status['fiscalDayStatus'] !== 'FiscalDayOpened') {
    echo "Opening fiscal day...\n";
    try {
        $openResult = $api->openDay($deviceId, date('Y-m-d\TH:i:s'), $fiscalDayNo + 1);
        $fiscalDayNo = $openResult['fiscalDayNo'];
        echo "✓ Fiscal day opened: " . $fiscalDayNo . "\n";
        $receiptGlobalNo = 1;
    } catch (Exception $e) {
        echo "⚠ " . $e->getMessage() . "\n";
        $fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
    }
} else {
    echo "✓ Fiscal day is already open\n";
    $fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
}

echo "\nCreating test receipt...\n";

// Build receipt data according to ZIMRA spec
// Note: deviceID is in path, not in body
$receiptData = [
    'receiptType' => 'FiscalInvoice',
    'receiptCurrency' => 'USD',
    'receiptCounter' => $receiptCounter,
    'receiptGlobalNo' => $receiptGlobalNo,
    'receiptDate' => date('Y-m-d\TH:i:s'),
    'invoiceNo' => 'TEST-' . time(),
    'receiptTotal' => 115.00, // Including tax
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
            'taxPercent' => 15.00
        ]
    ],
    'receiptTaxes' => [
        [
            'taxID' => 1,
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

// Add deviceID for signature generation (not sent in request body)
$receiptDataForSignature = $receiptData;
$receiptDataForSignature['deviceID'] = $deviceId;

echo "Receipt Data:\n";
echo "  Type: " . $receiptData['receiptType'] . "\n";
echo "  Currency: " . $receiptData['receiptCurrency'] . "\n";
echo "  Counter: " . $receiptData['receiptCounter'] . "\n";
echo "  Global No: " . $receiptData['receiptGlobalNo'] . "\n";
echo "  Total: " . $receiptData['receiptTotal'] . "\n\n";

// Generate signature
echo "Generating device signature...\n";
$previousReceiptHash = null;

try {
    // Verify private key format
    echo "Private key length: " . strlen($privateKey) . " bytes\n";
    echo "Private key starts with BEGIN: " . (strpos($privateKey, '-----BEGIN') !== false ? 'Yes' : 'No') . "\n";
    
    $deviceSignature = ZimraSignature::generateReceiptDeviceSignature(
        $receiptDataForSignature,
        $previousReceiptHash,
        $privateKey
    );
    
    echo "✓ Signature generated\n";
    echo "  Hash: " . substr($deviceSignature['hash'], 0, 20) . "...\n";
    echo "  Signature: " . substr($deviceSignature['signature'], 0, 20) . "...\n\n";
    
    // Add signature to receipt data (deviceID not included in request body)
    $receiptData['receiptDeviceSignature'] = $deviceSignature;
    
    // Show request body (without deviceID)
    echo "Request body (first 500 chars):\n";
    echo substr(json_encode($receiptData, JSON_PRETTY_PRINT), 0, 500) . "\n\n";
    
    // Submit receipt
    echo "Submitting receipt to ZIMRA...\n";
    $result = $api->submitReceipt($deviceId, $receiptData);
    
    echo "✓ SUCCESS!\n";
    echo "  Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
    echo "  Receipt Global No: " . ($result['receiptGlobalNo'] ?? 'N/A') . "\n";
    echo "  Server Date: " . ($result['serverDate'] ?? 'N/A') . "\n";
    echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
    
    if (isset($result['receiptServerSignature'])) {
        echo "  Server Signature received: Yes\n";
    }
    
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    echo "\nDebugging info:\n";
    echo "  Private key available: " . ($privateKey ? 'Yes' : 'No') . "\n";
    if ($privateKey) {
        echo "  Private key format check:\n";
        echo "    Has BEGIN: " . (strpos($privateKey, '-----BEGIN') !== false ? 'Yes' : 'No') . "\n";
        echo "    Has END: " . (strpos($privateKey, '-----END') !== false ? 'Yes' : 'No') . "\n";
        echo "    Length: " . strlen($privateKey) . " bytes\n";
    }
}

echo "\n=== Test Complete ===\n";

