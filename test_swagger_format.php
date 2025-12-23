<?php
/**
 * Test submitReceipt with exact Swagger format
 * Based on Swagger documentation
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Testing submitReceipt with Swagger Format ===\n\n";

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

if ($device && $device['certificate_pem'] && $device['private_key_pem']) {
    $api->setCertificate($device['certificate_pem'], $device['private_key_pem']);
    $privateKey = $device['private_key_pem'];
} else {
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    if (isset($result['certificate'])) {
        $api->setCertificate($result['certificate'], $csrData['privateKey']);
        $privateKey = $csrData['privateKey'];
    }
}

// Get status
$status = $api->getStatus($deviceId);
echo "Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
$fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
$lastReceiptGlobalNo = $status['lastReceiptGlobalNo'] ?? 0;
$receiptGlobalNo = $lastReceiptGlobalNo + 1;
$receiptCounter = 1;

echo "Receipt Global No: $receiptGlobalNo\n";
echo "Receipt Counter: $receiptCounter\n\n";

// Build receipt according to Swagger SubmitReceiptRequest schema
// Based on Swagger, the request body should match ReceiptDto structure
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
            'taxCode' => 'A' // Add taxCode
        ]
    ],
    'receiptTaxes' => [
        [
            'taxID' => 1,
            'taxCode' => 'A', // Add taxCode
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

// Generate signature (deviceID needed for signature, not in request body)
$receiptDataForSignature = $receiptData;
$receiptDataForSignature['deviceID'] = $deviceId;

$previousReceiptHash = null;
$deviceSignature = ZimraSignature::generateReceiptDeviceSignature(
    $receiptDataForSignature,
    $previousReceiptHash,
    $privateKey
);

$receiptData['receiptDeviceSignature'] = $deviceSignature;

echo "Submitting receipt...\n";
echo "Request body structure:\n";
echo json_encode($receiptData, JSON_PRETTY_PRINT) . "\n\n";

try {
    $result = $api->submitReceipt($deviceId, $receiptData);
    echo "✓ SUCCESS!\n";
    echo "Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
    echo "Receipt Global No: " . ($result['receiptGlobalNo'] ?? 'N/A') . "\n";
    echo "Server Date: " . ($result['serverDate'] ?? 'N/A') . "\n";
    echo "Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    
    // Try to get more details
    if (strpos($e->getMessage(), '400') !== false || strpos($e->getMessage(), 'Bad Request') !== false) {
        echo "\nPossible issues:\n";
        echo "1. Missing required fields\n";
        echo "2. Wrong data types\n";
        echo "3. Invalid field values\n";
        echo "4. Receipt format doesn't match Swagger schema\n";
    }
}

echo "\n=== Test Complete ===\n";

