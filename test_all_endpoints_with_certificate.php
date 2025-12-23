<?php
/**
 * Test ALL endpoints with certificate loaded from database
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "TEST ALL ENDPOINTS WITH CERTIFICATE\n";
echo "========================================\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$primaryDb = Database::getPrimaryInstance();
$results = [];

// Load certificate from database
echo "Loading certificate from database...\n";
$certData = CertificateStorage::loadCertificate($deviceId);

if (!$certData) {
    echo "✗ No certificate found. Attempting registration...\n";
    
    $api = new ZimraApi('Server', 'v1', true);
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    
    if (isset($result['certificate'])) {
        CertificateStorage::saveCertificate($deviceId, $result['certificate'], $csrData['privateKey']);
        $certData = CertificateStorage::loadCertificate($deviceId);
    }
}

if (!$certData) {
    die("✗ Could not load or register certificate\n");
}

echo "✓ Certificate loaded\n";
echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n\n";

// Initialize API with certificate
$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);

// Test all endpoints
echo "========================================\n";
echo "TESTING ENDPOINTS\n";
echo "========================================\n\n";

// 1. getConfig
echo "1. getConfig\n";
try {
    $result = $api->getConfig($deviceId);
    echo "   ✓ SUCCESS\n";
    echo "   Operating Mode: " . ($result['deviceOperatingMode'] ?? 'N/A') . "\n";
    echo "   QR URL: " . ($result['qrUrl'] ?? 'N/A') . "\n";
    $results['getConfig'] = 'SUCCESS';
    $config = $result;
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['getConfig'] = 'FAILED';
    $config = null;
}
echo "\n";

// 2. getStatus
echo "2. getStatus\n";
try {
    $result = $api->getStatus($deviceId);
    echo "   ✓ SUCCESS\n";
    echo "   Fiscal Day Status: " . ($result['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "   Last Fiscal Day No: " . ($result['lastFiscalDayNo'] ?? 'N/A') . "\n";
    echo "   Last Receipt Global No: " . ($result['lastReceiptGlobalNo'] ?? 'N/A') . "\n";
    $results['getStatus'] = 'SUCCESS';
    $status = $result;
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['getStatus'] = 'FAILED';
    $status = null;
}
echo "\n";

// 3. ping
echo "3. ping\n";
try {
    $result = $api->ping($deviceId);
    echo "   ✓ SUCCESS\n";
    echo "   Reporting Frequency: " . ($result['reportingFrequency'] ?? 'N/A') . " minutes\n";
    $results['ping'] = 'SUCCESS';
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['ping'] = 'FAILED';
}
echo "\n";

// 4. openDay (if not already open)
echo "4. openDay\n";
try {
    if ($status && $status['fiscalDayStatus'] === 'FiscalDayOpened') {
        echo "   ⚠ SKIPPED: Fiscal day already open\n";
        $results['openDay'] = 'SKIPPED';
        $fiscalDay = ['fiscalDayNo' => $status['lastFiscalDayNo'] ?? 1];
    } else {
        $fiscalDayOpened = date('Y-m-d\TH:i:s');
        $fiscalDayNo = ($status && isset($status['lastFiscalDayNo'])) ? $status['lastFiscalDayNo'] + 1 : 1;
        $result = $api->openDay($deviceId, $fiscalDayOpened, $fiscalDayNo);
        echo "   ✓ SUCCESS\n";
        echo "   Fiscal Day No: " . ($result['fiscalDayNo'] ?? 'N/A') . "\n";
        $results['openDay'] = 'SUCCESS';
        $fiscalDay = $result;
    }
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['openDay'] = 'FAILED';
    $fiscalDay = null;
}
echo "\n";

// 5. submitReceipt
echo "5. submitReceipt\n";
try {
    if (!$status || $status['fiscalDayStatus'] !== 'FiscalDayOpened') {
        echo "   ⚠ SKIPPED: Fiscal day not open\n";
        $results['submitReceipt'] = 'SKIPPED';
    } else {
        $fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
        $lastReceiptGlobalNo = $status['lastReceiptGlobalNo'] ?? 0;
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
        
        $result = $api->submitReceipt($deviceId, $receiptData);
        echo "   ✓ SUCCESS\n";
        echo "   Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
        echo "   Receipt Global No: " . ($result['receiptGlobalNo'] ?? 'N/A') . "\n";
        $results['submitReceipt'] = 'SUCCESS';
    }
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['submitReceipt'] = 'FAILED';
}
echo "\n";

// Summary
echo "========================================\n";
echo "SUMMARY\n";
echo "========================================\n\n";

$success = 0;
$failed = 0;
$skipped = 0;

foreach ($results as $endpoint => $status) {
    $icon = $status === 'SUCCESS' ? '✓' : ($status === 'SKIPPED' ? '⚠' : '✗');
    echo "$icon $endpoint: $status\n";
    if ($status === 'SUCCESS') $success++;
    elseif ($status === 'FAILED') $failed++;
    else $skipped++;
}

echo "\nTotal: " . count($results) . " endpoints\n";
echo "✓ Success: $success\n";
echo "✗ Failed: $failed\n";
echo "⚠ Skipped: $skipped\n";

