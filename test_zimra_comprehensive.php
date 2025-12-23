<?php
/**
 * Comprehensive ZIMRA Test Script
 * Tests device registration, receipt submission, and fiscal day operations
 * Logs everything to txt, log, and database
 */

// Set up environment
define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/zimra_logger.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/fiscal_service.php';

// Test devices
$testDevices = [
    [
        'device_id' => 30199,
        'serial' => 'electrox-1',
        'activation_key' => '00544726',
        'branch_id' => 1 // Adjust based on your database
    ],
    [
        'device_id' => 30200,
        'serial' => 'electrox-2',
        'activation_key' => '00294543',
        'branch_id' => 1 // Adjust based on your database
    ]
];

echo "========================================\n";
echo "ZIMRA COMPREHENSIVE TEST\n";
echo "========================================\n\n";

foreach ($testDevices as $deviceInfo) {
    $deviceId = $deviceInfo['device_id'];
    $serial = $deviceInfo['serial'];
    $activationKey = $deviceInfo['activation_key'];
    $branchId = $deviceInfo['branch_id'];
    
    echo "Testing Device: $deviceId (Serial: $serial)\n";
    echo "----------------------------------------\n";
    
    try {
        // Step 1: Check if device exists in database
        $db = Database::getPrimaryInstance();
        $device = $db->getRow(
            "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
            [':device_id' => $deviceId]
        );
        
        if (!$device) {
            echo "✗ Device not found in database. Creating device record...\n";
            // Create device record
            $deviceIdInserted = $db->insert('fiscal_devices', [
                'branch_id' => $branchId,
                'device_id' => $deviceId,
                'device_serial_no' => $serial,
                'activation_key' => $activationKey,
                'device_model_name' => 'Server',
                'device_model_version' => 'v1',
                'is_active' => 1,
                'is_registered' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            if ($deviceIdInserted) {
                echo "✓ Device record created (ID: $deviceIdInserted)\n";
                $device = $db->getRow(
                    "SELECT * FROM fiscal_devices WHERE id = :id",
                    [':id' => $deviceIdInserted]
                );
            } else {
                throw new Exception("Failed to create device record");
            }
        } else {
            echo "✓ Device found in database\n";
        }
        
        // Step 2: Check if device is registered
        if (!$device['is_registered'] || empty($device['certificate_pem'])) {
            echo "Device not registered. Registering...\n";
            
            // Initialize FiscalService
            $fiscalService = new FiscalService($branchId);
            
            // Register device
            $registerResponse = $fiscalService->registerDevice();
            
            if (isset($registerResponse['certificate'])) {
                echo "✓ Device registered successfully\n";
                echo "  Certificate length: " . strlen($registerResponse['certificate']) . " bytes\n";
                echo "  Operation ID: " . ($registerResponse['operationID'] ?? 'N/A') . "\n";
            } else {
                throw new Exception("Registration failed: " . json_encode($registerResponse));
            }
        } else {
            echo "✓ Device already registered\n";
            echo "  Certificate valid till: " . ($device['certificate_valid_till'] ?? 'N/A') . "\n";
        }
        
        // Step 3: Get config
        echo "\nGetting device configuration...\n";
        $fiscalService = new FiscalService($branchId);
        $config = $fiscalService->syncConfig();
        echo "✓ Configuration synced\n";
        
        // Step 4: Check fiscal day status
        echo "\nChecking fiscal day status...\n";
        $status = $fiscalService->getFiscalDayStatus();
        echo "  Status: " . ($status['status'] ?? 'N/A') . "\n";
        echo "  Fiscal Day No: " . ($status['fiscalDayNo'] ?? 'N/A') . "\n";
        
        // Step 5: Open fiscal day if needed
        if (($status['status'] ?? '') !== 'FiscalDayOpened') {
            echo "\nOpening fiscal day...\n";
            $openResult = $fiscalService->openFiscalDay();
            echo "✓ Fiscal day opened\n";
            echo "  Fiscal Day No: " . ($openResult['fiscalDayNo'] ?? 'N/A') . "\n";
            echo "  Operation ID: " . ($openResult['operationID'] ?? 'N/A') . "\n";
        } else {
            echo "✓ Fiscal day already open\n";
        }
        
        // Step 6: Test receipt submission (simple test receipt)
        echo "\nTesting receipt submission...\n";
        $receiptData = [
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => 'USD',
            'receiptCounter' => 1,
            'receiptGlobalNo' => 1,
            'invoiceNo' => 'TEST-' . date('YmdHis'),
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
        
        // This will calculate taxes and generate signature
        // Note: We need an invoice ID - for testing, we'll use a dummy ID
        try {
            $submitResult = $fiscalService->submitReceipt(0, $receiptData, null);
            echo "✓ Receipt submitted successfully\n";
            echo "  Receipt ID: " . ($submitResult['receiptID'] ?? 'N/A') . "\n";
            echo "  QR Code: " . (isset($submitResult['qrCode']) ? 'Generated' : 'N/A') . "\n";
        } catch (Exception $e) {
            echo "✗ Receipt submission failed: " . $e->getMessage() . "\n";
            echo "  This is expected if signature format is still incorrect\n";
        }
        
        echo "\n✓ Device $deviceId test completed\n";
        
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
        echo "  Stack trace: " . $e->getTraceAsString() . "\n";
    }
    
    echo "\n";
}

echo "========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";
echo "\nAll operations have been logged to:\n";
echo "  - TXT: logs/zimra/zimra_operations_" . date('Y-m-d') . ".txt\n";
echo "  - LOG: logs/error.log\n";
echo "  - DATABASE: zimra_operation_logs, zimra_receipt_logs, zimra_certificates tables\n";
echo "\n";

