<?php
/**
 * FINAL COMPREHENSIVE TEST - ALL ZIMRA ENDPOINTS
 * Tests all endpoints from all API definitions
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "FINAL COMPREHENSIVE ZIMRA API TESTING\n";
echo "========================================\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$api = new ZimraApi('Server', 'v1', true);
$results = [];

// Load certificate
$primaryDb = Database::getPrimaryInstance();
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

$privateKey = null;
$csrData = null;

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
        
        // Save to database
        if ($device) {
            $primaryDb->update('fiscal_devices', [
                'certificate_pem' => $result['certificate'],
                'private_key_pem' => $csrData['privateKey'],
                'is_registered' => 1
            ], ['id' => $device['id']]);
        } else {
            $branch = $primaryDb->getRow("SELECT id FROM branches WHERE branch_code = 'HO' OR branch_name LIKE '%Head Office%' LIMIT 1");
            if ($branch) {
                $primaryDb->insert('fiscal_devices', [
                    'branch_id' => $branch['id'],
                    'device_id' => $deviceId,
                    'device_serial_no' => $deviceSerialNo,
                    'activation_key' => $activationKey,
                    'device_model_name' => 'Server',
                    'device_model_version' => 'v1',
                    'certificate_pem' => $result['certificate'],
                    'private_key_pem' => $csrData['privateKey'],
                    'is_registered' => 1,
                    'is_active' => 1
                ]);
            }
        }
        echo "✓ Certificate saved to database\n";
        
        // Reload device
        $device = $primaryDb->getRow(
            "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
            [':device_id' => $deviceId]
        );
    }
}

echo "\n";

// ============================================
// PUBLIC ENDPOINTS
// ============================================
echo "========================================\n";
echo "PUBLIC ENDPOINTS\n";
echo "========================================\n\n";

// 1. verifyTaxpayerInformation
echo "1. verifyTaxpayerInformation\n";
echo "   POST /Public/v1/{deviceID}/VerifyTaxpayerInformation\n";
try {
    $result = $api->verifyTaxpayerInformation($deviceId, $activationKey, $deviceSerialNo);
    echo "   ✓ SUCCESS\n";
    echo "   Taxpayer: " . $result['taxPayerName'] . "\n";
    echo "   TIN: " . $result['taxPayerTIN'] . "\n";
    $results['verifyTaxpayerInformation'] = 'SUCCESS';
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['verifyTaxpayerInformation'] = 'FAILED';
}
echo "\n";

// 2. getServerCertificate
echo "2. getServerCertificate\n";
echo "   GET /Public/v1/GetServerCertificate\n";
try {
    $result = $api->getServerCertificate();
    echo "   ✓ SUCCESS\n";
    echo "   Thumbprint: " . ($result['thumbprint'] ?? 'N/A') . "\n";
    $results['getServerCertificate'] = 'SUCCESS';
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['getServerCertificate'] = 'FAILED';
}
echo "\n";

// 3. registerDevice
echo "3. registerDevice\n";
echo "   POST /Public/v1/{deviceID}/RegisterDevice\n";
try {
    if (!$device || !$device['certificate_pem']) {
        $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
        $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
        echo "   ✓ SUCCESS\n";
        echo "   Certificate received: Yes\n";
        $results['registerDevice'] = 'SUCCESS';
    } else {
        echo "   ⚠ SKIPPED: Device already registered\n";
        $results['registerDevice'] = 'SKIPPED';
    }
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['registerDevice'] = 'FAILED';
}
echo "\n";

// ============================================
// DEVICE ENDPOINTS
// ============================================
echo "========================================\n";
echo "DEVICE ENDPOINTS\n";
echo "========================================\n\n";

// 4. getConfig
echo "4. getConfig\n";
echo "   GET /Device/v1/{deviceID}/GetConfig\n";
try {
    $result = $api->getConfig($deviceId);
    echo "   ✓ SUCCESS\n";
    echo "   Operating Mode: " . ($result['deviceOperatingMode'] ?? 'N/A') . "\n";
    echo "   QR URL: " . ($result['qrUrl'] ?? 'N/A') . "\n";
    $config = $result;
    $results['getConfig'] = 'SUCCESS';
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['getConfig'] = 'FAILED';
    $config = null;
}
echo "\n";

// 5. getStatus
echo "5. getStatus\n";
echo "   GET /Device/v1/{deviceID}/GetStatus\n";
try {
    $result = $api->getStatus($deviceId);
    echo "   ✓ SUCCESS\n";
    echo "   Fiscal Day Status: " . ($result['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "   Last Fiscal Day No: " . ($result['lastFiscalDayNo'] ?? 'N/A') . "\n";
    echo "   Last Receipt Global No: " . ($result['lastReceiptGlobalNo'] ?? 'N/A') . "\n";
    $status = $result;
    $results['getStatus'] = 'SUCCESS';
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['getStatus'] = 'FAILED';
    $status = null;
}
echo "\n";

// 6. ping
echo "6. ping\n";
echo "   POST /Device/v1/{deviceID}/Ping\n";
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

// 7. openDay
echo "7. openDay\n";
echo "   POST /Device/v1/{deviceID}/OpenDay\n";
try {
    if ($status && $status['fiscalDayStatus'] === 'FiscalDayOpened') {
        echo "   ⚠ SKIPPED: Fiscal day already open\n";
        $fiscalDay = ['fiscalDayNo' => $status['lastFiscalDayNo'] ?? 1];
        $results['openDay'] = 'SKIPPED';
    } else {
        $fiscalDayOpened = date('Y-m-d\TH:i:s');
        $fiscalDayNo = ($status && isset($status['lastFiscalDayNo'])) ? $status['lastFiscalDayNo'] + 1 : 1;
        $result = $api->openDay($deviceId, $fiscalDayOpened, $fiscalDayNo);
        echo "   ✓ SUCCESS\n";
        echo "   Fiscal Day No: " . ($result['fiscalDayNo'] ?? 'N/A') . "\n";
        $fiscalDay = $result;
        $results['openDay'] = 'SUCCESS';
    }
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['openDay'] = 'FAILED';
    $fiscalDay = null;
}
echo "\n";

// 8. submitReceipt
echo "8. submitReceipt\n";
echo "   POST /Device/v1/{deviceID}/SubmitReceipt\n";
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
            $privateKey
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

// 9. closeDay
echo "9. closeDay\n";
echo "   POST /Device/v1/{deviceID}/CloseDay\n";
echo "   Note: Asynchronous operation\n";
try {
    if ($status && $status['fiscalDayStatus'] === 'FiscalDayOpened') {
        $fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
        $fiscalDayCounters = []; // Empty for test
        
        $fiscalDayData = [
            'deviceID' => $deviceId,
            'fiscalDayNo' => $fiscalDayNo,
            'fiscalDayOpened' => date('Y-m-d H:i:s'),
            'fiscalDayCounters' => $fiscalDayCounters
        ];
        
        $fiscalDayDeviceSignature = ZimraSignature::generateFiscalDayDeviceSignature(
            $fiscalDayData,
            $privateKey
        );
        
        $receiptCounter = 0;
        $result = $api->closeDay($deviceId, $fiscalDayNo, $fiscalDayCounters, $fiscalDayDeviceSignature, $receiptCounter);
        echo "   ✓ REQUEST ACCEPTED (asynchronous)\n";
        echo "   Fiscal Day No: " . ($result['fiscalDayNo'] ?? 'N/A') . "\n";
        $results['closeDay'] = 'SUCCESS';
    } else {
        echo "   ⚠ SKIPPED: No open fiscal day to close\n";
        $results['closeDay'] = 'SKIPPED';
    }
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $results['closeDay'] = 'FAILED';
}
echo "\n";

// 10. issueCertificate
echo "10. issueCertificate\n";
echo "    POST /Device/v1/{deviceID}/IssueCertificate\n";
try {
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
    $result = $api->issueCertificate($deviceId, $csrData['csr']);
    echo "    ✓ SUCCESS\n";
    echo "    Certificate received: Yes\n";
    $results['issueCertificate'] = 'SUCCESS';
} catch (Exception $e) {
    echo "    ✗ FAILED: " . $e->getMessage() . "\n";
    $results['issueCertificate'] = 'FAILED';
}
echo "\n";

// 11. submitFile
echo "11. submitFile\n";
echo "    POST /Device/v1/{deviceID}/SubmitFile\n";
try {
    // Create a minimal valid JSON file for offline mode
    $fileContent = json_encode([
        'receipts' => []
    ]);
    
    $result = $api->submitFile($deviceId, $fileContent);
    echo "    ✓ SUCCESS\n";
    echo "    File ID: " . ($result['fileID'] ?? 'N/A') . "\n";
    $results['submitFile'] = 'SUCCESS';
} catch (Exception $e) {
    echo "    ✗ FAILED: " . $e->getMessage() . "\n";
    $results['submitFile'] = 'FAILED';
}
echo "\n";

// ============================================
// SUMMARY
// ============================================
echo "========================================\n";
echo "TEST SUMMARY\n";
echo "========================================\n\n";

$successCount = 0;
$failedCount = 0;
$skippedCount = 0;

foreach ($results as $endpoint => $status) {
    $statusIcon = $status === 'SUCCESS' ? '✓' : ($status === 'SKIPPED' ? '⚠' : '✗');
    echo "$statusIcon $endpoint: $status\n";
    
    if ($status === 'SUCCESS') $successCount++;
    elseif ($status === 'FAILED') $failedCount++;
    else $skippedCount++;
}

echo "\n";
echo "Total: " . count($results) . " endpoints\n";
echo "✓ Success: $successCount\n";
echo "✗ Failed: $failedCount\n";
echo "⚠ Skipped: $skippedCount\n";

echo "\n========================================\n";
echo "ALL ENDPOINTS TESTED\n";
echo "========================================\n";

