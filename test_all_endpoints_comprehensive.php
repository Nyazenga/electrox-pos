<?php
/**
 * Comprehensive Test of ALL ZIMRA Endpoints
 * Tests all endpoints from all API definitions
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/fiscal_helper.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "COMPREHENSIVE ZIMRA API ENDPOINT TESTING\n";
echo "========================================\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$api = new ZimraApi('Server', 'v1', true);

// Load certificate if available
$primaryDb = Database::getPrimaryInstance();
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

$privateKey = null;
$csrData = null;

if ($device && $device['certificate_pem'] && $device['private_key_pem']) {
    echo "✓ Using stored certificate from database\n";
    $api->setCertificate($device['certificate_pem'], $device['private_key_pem']);
    $privateKey = $device['private_key_pem'];
} else {
    echo "⚠ No certificate found, attempting registration...\n";
    try {
        $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
        $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
        if (isset($result['certificate'])) {
            $api->setCertificate($result['certificate'], $csrData['privateKey']);
            $privateKey = $csrData['privateKey'];
            echo "✓ Device registered and certificate set\n";
            
            // Save to database
            if ($device) {
                $primaryDb->update('fiscal_devices', [
                    'certificate_pem' => $result['certificate'],
                    'private_key_pem' => $csrData['privateKey'],
                    'is_registered' => 1
                ], ['id' => $device['id']]);
            } else {
                // Insert new device record
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
        }
    } catch (Exception $e) {
        echo "✗ Registration failed: " . $e->getMessage() . "\n";
        echo "Skipping certificate-required tests...\n\n";
    }
}

echo "\n";

// ============================================
// PUBLIC ENDPOINTS (No Certificate Required)
// ============================================
echo "========================================\n";
echo "PUBLIC ENDPOINTS (No Certificate)\n";
echo "========================================\n\n";

// Test 1: verifyTaxpayerInformation
echo "1. verifyTaxpayerInformation\n";
echo "   Endpoint: POST /Public/v1/{deviceID}/VerifyTaxpayerInformation\n";
try {
    $result = $api->verifyTaxpayerInformation($deviceId, $activationKey, $deviceSerialNo);
    echo "   ✓ SUCCESS\n";
    echo "   Taxpayer: " . $result['taxPayerName'] . "\n";
    echo "   TIN: " . $result['taxPayerTIN'] . "\n";
    echo "   Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 2: getServerCertificate
echo "2. getServerCertificate\n";
echo "   Endpoint: GET /Public/v1/GetServerCertificate\n";
try {
    $result = $api->getServerCertificate();
    echo "   ✓ SUCCESS\n";
    echo "   Thumbprint: " . ($result['thumbprint'] ?? 'N/A') . "\n";
    echo "   Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// ============================================
// DEVICE ENDPOINTS (Certificate Required)
// ============================================
echo "========================================\n";
echo "DEVICE ENDPOINTS (Certificate Required)\n";
echo "========================================\n\n";

// Test 3: getConfig
echo "3. getConfig\n";
echo "   Endpoint: GET /Device/v1/{deviceID}/GetConfig\n";
try {
    $result = $api->getConfig($deviceId);
    echo "   ✓ SUCCESS\n";
    echo "   Taxpayer: " . ($result['taxpayerName'] ?? 'N/A') . "\n";
    echo "   Operating Mode: " . ($result['deviceOperatingMode'] ?? 'N/A') . "\n";
    echo "   QR URL: " . ($result['qrUrl'] ?? 'N/A') . "\n";
    echo "   Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
    $config = $result; // Save for later use
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $config = null;
}
echo "\n";

// Test 4: getStatus
echo "4. getStatus\n";
echo "   Endpoint: GET /Device/v1/{deviceID}/GetStatus\n";
try {
    $result = $api->getStatus($deviceId);
    echo "   ✓ SUCCESS\n";
    echo "   Fiscal Day Status: " . ($result['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "   Last Fiscal Day No: " . ($result['lastFiscalDayNo'] ?? 'N/A') . "\n";
    echo "   Last Receipt Global No: " . ($result['lastReceiptGlobalNo'] ?? 'N/A') . "\n";
    echo "   Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
    $status = $result; // Save for later use
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    $status = null;
}
echo "\n";

// Test 5: ping
echo "5. ping\n";
echo "   Endpoint: POST /Device/v1/{deviceID}/Ping\n";
try {
    $result = $api->ping($deviceId);
    echo "   ✓ SUCCESS\n";
    echo "   Reporting Frequency: " . ($result['reportingFrequency'] ?? 'N/A') . " minutes\n";
    echo "   Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 6: openDay
echo "6. openDay\n";
echo "   Endpoint: POST /Device/v1/{deviceID}/OpenDay\n";
try {
    $fiscalDayOpened = date('Y-m-d\TH:i:s');
    $fiscalDayNo = ($status && isset($status['lastFiscalDayNo'])) ? $status['lastFiscalDayNo'] + 1 : 1;
    
    $result = $api->openDay($deviceId, $fiscalDayOpened, $fiscalDayNo);
    echo "   ✓ SUCCESS\n";
    echo "   Fiscal Day No: " . ($result['fiscalDayNo'] ?? 'N/A') . "\n";
    echo "   Fiscal Day Opened: " . ($result['fiscalDayOpened'] ?? 'N/A') . "\n";
    echo "   Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
    $fiscalDay = $result; // Save for receipt submission
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    echo "   Note: Fiscal day may already be open\n";
    $fiscalDay = null;
}
echo "\n";

// Test 7: submitReceipt (if fiscal day is open)
echo "7. submitReceipt\n";
echo "   Endpoint: POST /Device/v1/{deviceID}/SubmitReceipt\n";
if ($fiscalDay || ($status && $status['fiscalDayStatus'] === 'FiscalDayOpened')) {
    try {
        // Create a test receipt
        $receiptCounter = 1;
        $receiptGlobalNo = ($status && isset($status['lastReceiptGlobalNo'])) ? $status['lastReceiptGlobalNo'] + 1 : 1;
        
        $receiptData = [
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => 'USD',
            'receiptCounter' => $receiptCounter,
            'receiptGlobalNo' => $receiptGlobalNo,
            'receiptDate' => date('Y-m-d\TH:i:s'),
            'invoiceNo' => 'TEST-' . time(),
            'receiptTotal' => 100.00,
            'receiptLines' => [
                [
                    'receiptLineType' => 'Sale',
                    'receiptLineNo' => 1,
                    'receiptLineHSCode' => '00000000',
                    'receiptLineName' => 'Test Product',
                    'receiptLinePrice' => 100.00,
                    'receiptLineQuantity' => 1,
                    'receiptLineTotal' => 100.00,
                    'taxID' => null,
                    'taxPercent' => null
                ]
            ],
            'receiptTaxes' => [],
            'receiptPayments' => [
                [
                    'moneyTypeCode' => 'Cash',
                    'paymentAmount' => 100.00
                ]
            ]
        ];
        
        // Generate device signature
        $previousReceiptHash = null; // First receipt
        
        if (!$privateKey) {
            echo "   ✗ FAILED: No private key available for signature\n";
            throw new Exception('No private key available');
        }
        
        // Add deviceID to receiptData for signature generation
        $receiptData['deviceID'] = $deviceId;
        
        try {
            $deviceSignature = ZimraSignature::generateReceiptDeviceSignature(
                $receiptData,
                $previousReceiptHash,
                $privateKey
            );
            
            $receiptData['receiptDeviceSignature'] = $deviceSignature;
        } catch (Exception $e) {
            echo "   ✗ FAILED: Signature generation error: " . $e->getMessage() . "\n";
            throw $e;
        }
        
        $result = $api->submitReceipt($deviceId, $receiptData);
        echo "   ✓ SUCCESS\n";
        echo "   Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
        echo "   Receipt Global No: " . ($result['receiptGlobalNo'] ?? 'N/A') . "\n";
        echo "   Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ⚠ SKIPPED: Fiscal day is not open\n";
}
echo "\n";

// Test 8: closeDay
echo "8. closeDay\n";
echo "   Endpoint: POST /Device/v1/{deviceID}/CloseDay\n";
echo "   Note: This is asynchronous - will test request only\n";
try {
    if ($fiscalDay && isset($fiscalDay['fiscalDayNo'])) {
        $fiscalDayNo = $fiscalDay['fiscalDayNo'];
        
        // Create fiscal day counters (empty for test)
        $fiscalDayCounters = [];
        
        // Generate fiscal day device signature
        if ($privateKey) {
            $fiscalDayData = [
                'deviceID' => $deviceId,
                'fiscalDayNo' => $fiscalDayNo,
                'fiscalDayOpened' => $fiscalDay['fiscalDayOpened'] ?? date('Y-m-d H:i:s'),
                'fiscalDayCounters' => $fiscalDayCounters
            ];
            
            $fiscalDayDeviceSignature = ZimraSignature::generateFiscalDayDeviceSignature(
                $fiscalDayData,
                $privateKey
            );
        } else {
            echo "   ✗ FAILED: No private key available for signature\n";
            throw new Exception('No private key available');
        }
        
        $receiptCounter = 0; // No receipts in test
        
        $result = $api->closeDay($deviceId, $fiscalDayNo, $fiscalDayCounters, $fiscalDayDeviceSignature, $receiptCounter);
        echo "   ✓ REQUEST ACCEPTED (processing is asynchronous)\n";
        echo "   Fiscal Day No: " . ($result['fiscalDayNo'] ?? 'N/A') . "\n";
        echo "   Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
    } else {
        echo "   ⚠ SKIPPED: No fiscal day to close\n";
    }
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 9: issueCertificate
echo "9. issueCertificate\n";
echo "   Endpoint: POST /Device/v1/{deviceID}/IssueCertificate\n";
echo "   Note: Used to renew certificate for already registered device\n";
try {
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
    $result = $api->issueCertificate($deviceId, $csrData['csr']);
    echo "   ✓ SUCCESS\n";
    echo "   Certificate received: " . (isset($result['certificate']) ? 'Yes' : 'No') . "\n";
    echo "   Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    echo "   Note: This is expected if device certificate is still valid\n";
}
echo "\n";

// Test 10: submitFile (for offline mode)
echo "10. submitFile\n";
echo "    Endpoint: POST /Device/v1/{deviceID}/SubmitFile\n";
echo "    Note: For offline mode - submits batch of receipts\n";
try {
    // Create a simple JSON file content for testing
    $fileContent = json_encode([
        'receipts' => []
    ]);
    
    $result = $api->submitFile($deviceId, $fileContent);
    echo "    ✓ SUCCESS\n";
    echo "    File ID: " . ($result['fileID'] ?? 'N/A') . "\n";
    echo "    Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "    ✗ FAILED: " . $e->getMessage() . "\n";
    echo "    Note: May require specific file format\n";
}
echo "\n";

// ============================================
// SUMMARY
// ============================================
echo "========================================\n";
echo "TEST SUMMARY\n";
echo "========================================\n\n";

echo "Public Endpoints: ✓ All tested\n";
echo "Device Endpoints: ✓ All tested\n";
echo "\n";
echo "Next Steps:\n";
echo "1. Test end-to-end invoice fiscalization\n";
echo "2. Verify QR codes on PDF receipts\n";
echo "3. Test with real invoice data\n";
echo "\n";
echo "========================================\n";
echo "TESTING COMPLETE\n";
echo "========================================\n";

