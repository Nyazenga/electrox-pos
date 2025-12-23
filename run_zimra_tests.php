<?php
/**
 * Combined ZIMRA Test Script
 * Verifies/updates test devices and runs comprehensive tests
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/zimra_logger.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$testDevices = [
    [
        'device_id' => 30199,
        'serial' => 'electrox-1',
        'activation_key' => '00544726',
        'branch_id' => 1
    ],
    [
        'device_id' => 30200,
        'serial' => 'electrox-2',
        'activation_key' => '00294543',
        'branch_id' => 1
    ]
];

echo "========================================\n";
echo "ZIMRA COMPREHENSIVE TEST\n";
echo "========================================\n\n";

try {
    $db = Database::getPrimaryInstance();
    echo "✓ Database connection established\n\n";
} catch (Exception $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($testDevices as $deviceInfo) {
    $deviceId = $deviceInfo['device_id'];
    $serial = $deviceInfo['serial'];
    $activationKey = $deviceInfo['activation_key'];
    $branchId = $deviceInfo['branch_id'];
    
    echo "========================================\n";
    echo "Device: $deviceId (Serial: $serial)\n";
    echo "========================================\n\n";
    
    try {
        // Step 1: Verify/Update device in database
        echo "Step 1: Verifying device in database...\n";
        $device = $db->getRow(
            "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
            [':device_id' => $deviceId]
        );
        
        if ($device) {
            echo "  ✓ Device found (ID: {$device['id']})\n";
            
            // Update device details
            $db->update('fiscal_devices', [
                'device_serial_no' => $serial,
                'activation_key' => $activationKey,
                'device_model_name' => 'Server',
                'device_model_version' => 'v1',
                'is_active' => 1,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $device['id']]);
            echo "  ✓ Device details updated\n";
        } else {
            echo "  ✗ Device not found. Creating...\n";
            $deviceIdInserted = $db->insert('fiscal_devices', [
                'branch_id' => $branchId,
                'device_id' => $deviceId,
                'device_serial_no' => $serial,
                'activation_key' => $activationKey,
                'device_model_name' => 'Server',
                'device_model_version' => 'v1',
                'is_active' => 1,
                'is_registered' => 0,
                'operating_mode' => 'Online',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            if ($deviceIdInserted) {
                echo "  ✓ Device record created (ID: $deviceIdInserted)\n";
                $device = $db->getRow(
                    "SELECT * FROM fiscal_devices WHERE id = :id",
                    [':id' => $deviceIdInserted]
                );
            } else {
                throw new Exception("Failed to create device record");
            }
        }
        
        // Step 2: Check registration status
        echo "\nStep 2: Checking registration status...\n";
        if (!$device['is_registered'] || empty($device['certificate_pem'])) {
            echo "  Device not registered. Registering...\n";
            echo "  (This may take 30-60 seconds...)\n";
            
            try {
                $fiscalService = new FiscalService($branchId);
                $registerResponse = $fiscalService->registerDevice();
                
                if (isset($registerResponse['certificate'])) {
                    echo "  ✓ Device registered successfully\n";
                    echo "    Certificate length: " . strlen($registerResponse['certificate']) . " bytes\n";
                    echo "    Operation ID: " . ($registerResponse['operationID'] ?? 'N/A') . "\n";
                } else {
                    echo "  ✗ Registration failed: " . json_encode($registerResponse) . "\n";
                    echo "    Continuing with test...\n";
                }
            } catch (Exception $e) {
                echo "  ✗ Registration error: " . $e->getMessage() . "\n";
                echo "    Continuing with test...\n";
            }
        } else {
            echo "  ✓ Device already registered\n";
            echo "    Certificate valid till: " . ($device['certificate_valid_till'] ?? 'N/A') . "\n";
        }
        
        // Step 3: Sync configuration
        echo "\nStep 3: Syncing configuration...\n";
        $fiscalService = new FiscalService($branchId);
        try {
            $config = $fiscalService->syncConfig();
            echo "  ✓ Configuration synced\n";
        } catch (Exception $e) {
            echo "  ⚠ Configuration sync failed: " . $e->getMessage() . "\n";
            echo "    Continuing with test...\n";
        }
        
        // Step 4: Check fiscal day status
        echo "\nStep 4: Checking fiscal day status...\n";
        try {
            $status = $fiscalService->getFiscalDayStatus();
            echo "  Status: " . ($status['status'] ?? 'N/A') . "\n";
            echo "  Fiscal Day No: " . ($status['fiscalDayNo'] ?? 'N/A') . "\n";
            
            // Step 5: Open fiscal day if needed
            if (($status['status'] ?? '') !== 'FiscalDayOpened') {
                echo "\nStep 5: Opening fiscal day...\n";
                $openResult = $fiscalService->openFiscalDay();
                echo "  ✓ Fiscal day opened\n";
                echo "    Fiscal Day No: " . ($openResult['fiscalDayNo'] ?? 'N/A') . "\n";
                echo "    Operation ID: " . ($openResult['operationID'] ?? 'N/A') . "\n";
            } else {
                echo "\nStep 5: Fiscal day already open\n";
            }
        } catch (Exception $e) {
            echo "  ⚠ Fiscal day check failed: " . $e->getMessage() . "\n";
        }
        
        // Step 6: Test receipt submission
        echo "\nStep 6: Testing receipt submission...\n";
        $receiptData = [
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => 'USD',
            'receiptCounter' => 1,
            'receiptGlobalNo' => 1,
            'invoiceNo' => 'TEST-' . $deviceId . '-' . date('YmdHis'),
            'receiptDate' => date('Y-m-d\TH:i:s'),
            'receiptLines' => [
                [
                    'receiptLineType' => 'Sale',
                    'receiptLineNo' => 1,
                    'receiptLineHSCode' => '04021099',
                    'receiptLineName' => 'Test Item',
                    'receiptLinePrice' => 10.00,
                    'receiptLineQuantity' => 1,
                    'receiptLineTotal' => 10.00,
                    'taxCode' => '',
                    'taxPercent' => 0,
                    'taxID' => 2 // Zero rate
                ]
            ],
            'receiptPayments' => [
                [
                    'moneyTypeCode' => 'Cash',
                    'paymentAmount' => 10.00
                ]
            ]
        ];
        
        try {
            $submitResult = $fiscalService->submitReceipt(0, $receiptData, null);
            echo "  ✓ Receipt submitted successfully\n";
            echo "    Receipt ID: " . ($submitResult['receiptID'] ?? 'N/A') . "\n";
            echo "    QR Code: " . (isset($submitResult['qrCode']) ? 'Generated' : 'N/A') . "\n";
            
            if (isset($submitResult['validationErrors'])) {
                echo "    ⚠ Validation Errors: " . count($submitResult['validationErrors']) . "\n";
                foreach ($submitResult['validationErrors'] as $error) {
                    echo "      - " . ($error['validationErrorCode'] ?? 'Unknown') . "\n";
                }
            }
        } catch (Exception $e) {
            echo "  ✗ Receipt submission failed: " . $e->getMessage() . "\n";
            if (strpos($e->getMessage(), 'RCPT020') !== false) {
                echo "    ⚠ RCPT020 Error: Invalid signature - check logs for signature string\n";
            }
        }
        
        echo "\n✓ Device $deviceId test completed\n";
        
    } catch (Exception $e) {
        echo "\n✗ Error for device $deviceId: " . $e->getMessage() . "\n";
        if (strpos($e->getTraceAsString(), 'Stack trace') !== false) {
            echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
    }
    
    echo "\n";
}

echo "========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";
echo "\nAll operations logged to:\n";
echo "  - TXT: logs/zimra/zimra_operations_" . date('Y-m-d') . ".txt\n";
echo "  - LOG: logs/error.log\n";
echo "  - DATABASE: zimra_operation_logs, zimra_receipt_logs, zimra_certificates\n";
echo "\n";

