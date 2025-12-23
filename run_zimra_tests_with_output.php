<?php
/**
 * Combined ZIMRA Test Script with File Output
 * Verifies/updates test devices and runs comprehensive tests
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Output to both console and file
$outputFile = __DIR__ . '/zimra_test_results_' . date('Y-m-d_H-i-s') . '.txt';
$output = fopen($outputFile, 'w');

function writeOutput($message, $fileHandle = null) {
    echo $message;
    if ($fileHandle) {
        fwrite($fileHandle, $message);
        fflush($fileHandle);
    }
}

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

writeOutput("========================================\n", $output);
writeOutput("ZIMRA COMPREHENSIVE TEST\n", $output);
writeOutput("Started: " . date('Y-m-d H:i:s') . "\n", $output);
writeOutput("========================================\n\n", $output);

try {
    $db = Database::getPrimaryInstance();
    writeOutput("✓ Database connection established\n\n", $output);
} catch (Exception $e) {
    writeOutput("✗ Database connection failed: " . $e->getMessage() . "\n", $output);
    fclose($output);
    exit(1);
}

foreach ($testDevices as $deviceInfo) {
    $deviceId = $deviceInfo['device_id'];
    $serial = $deviceInfo['serial'];
    $activationKey = $deviceInfo['activation_key'];
    $branchId = $deviceInfo['branch_id'];
    
    writeOutput("========================================\n", $output);
    writeOutput("Device: $deviceId (Serial: $serial)\n", $output);
    writeOutput("========================================\n\n", $output);
    
    try {
        // Step 1: Verify/Update device in database
        writeOutput("Step 1: Verifying device in database...\n", $output);
        $device = $db->getRow(
            "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
            [':device_id' => $deviceId]
        );
        
        if ($device) {
            writeOutput("  ✓ Device found (ID: {$device['id']})\n", $output);
            
            // Update device details
            $db->update('fiscal_devices', [
                'device_serial_no' => $serial,
                'activation_key' => $activationKey,
                'device_model_name' => 'Server',
                'device_model_version' => 'v1',
                'is_active' => 1,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $device['id']]);
            writeOutput("  ✓ Device details updated\n", $output);
        } else {
            writeOutput("  ✗ Device not found. Creating...\n", $output);
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
                writeOutput("  ✓ Device record created (ID: $deviceIdInserted)\n", $output);
                $device = $db->getRow(
                    "SELECT * FROM fiscal_devices WHERE id = :id",
                    [':id' => $deviceIdInserted]
                );
            } else {
                throw new Exception("Failed to create device record");
            }
        }
        
        // Step 2: Check registration status
        writeOutput("\nStep 2: Checking registration status...\n", $output);
        if (!$device['is_registered'] || empty($device['certificate_pem'])) {
            writeOutput("  Device not registered. Registering...\n", $output);
            writeOutput("  (This may take 30-60 seconds...)\n", $output);
            
            try {
                $fiscalService = new FiscalService($branchId);
                $registerResponse = $fiscalService->registerDevice();
                
                if (isset($registerResponse['certificate'])) {
                    writeOutput("  ✓ Device registered successfully\n", $output);
                    writeOutput("    Certificate length: " . strlen($registerResponse['certificate']) . " bytes\n", $output);
                    writeOutput("    Operation ID: " . ($registerResponse['operationID'] ?? 'N/A') . "\n", $output);
                } else {
                    writeOutput("  ✗ Registration failed: " . json_encode($registerResponse) . "\n", $output);
                    writeOutput("    Continuing with test...\n", $output);
                }
            } catch (Exception $e) {
                writeOutput("  ✗ Registration error: " . $e->getMessage() . "\n", $output);
                writeOutput("    Continuing with test...\n", $output);
            }
        } else {
            writeOutput("  ✓ Device already registered\n", $output);
            writeOutput("    Certificate valid till: " . ($device['certificate_valid_till'] ?? 'N/A') . "\n", $output);
        }
        
        // Step 3: Sync configuration
        writeOutput("\nStep 3: Syncing configuration...\n", $output);
        $fiscalService = new FiscalService($branchId);
        try {
            $config = $fiscalService->syncConfig();
            writeOutput("  ✓ Configuration synced\n", $output);
        } catch (Exception $e) {
            writeOutput("  ⚠ Configuration sync failed: " . $e->getMessage() . "\n", $output);
            writeOutput("    Continuing with test...\n", $output);
        }
        
        // Step 4: Check fiscal day status
        writeOutput("\nStep 4: Checking fiscal day status...\n", $output);
        try {
            $status = $fiscalService->getFiscalDayStatus();
            writeOutput("  Status: " . ($status['status'] ?? 'N/A') . "\n", $output);
            writeOutput("  Fiscal Day No: " . ($status['fiscalDayNo'] ?? 'N/A') . "\n", $output);
            
            // Step 5: Open fiscal day if needed
            if (($status['status'] ?? '') !== 'FiscalDayOpened') {
                writeOutput("\nStep 5: Opening fiscal day...\n", $output);
                $openResult = $fiscalService->openFiscalDay();
                writeOutput("  ✓ Fiscal day opened\n", $output);
                writeOutput("    Fiscal Day No: " . ($openResult['fiscalDayNo'] ?? 'N/A') . "\n", $output);
                writeOutput("    Operation ID: " . ($openResult['operationID'] ?? 'N/A') . "\n", $output);
            } else {
                writeOutput("\nStep 5: Fiscal day already open\n", $output);
            }
        } catch (Exception $e) {
            writeOutput("  ⚠ Fiscal day check failed: " . $e->getMessage() . "\n", $output);
        }
        
        // Step 6: Test receipt submission
        writeOutput("\nStep 6: Testing receipt submission...\n", $output);
        
        // Prepare receipt data with all required fields
        $lineTotal = 10.00;
        $taxPercent = 0;
        $taxID = 2; // Zero rate
        $taxCode = 'C';
        $receiptTotal = $lineTotal; // For zero tax
        
        // Calculate taxes array
        $receiptTaxes = [
            [
                'taxID' => $taxID,
                'taxCode' => $taxCode,
                'taxPercent' => $taxPercent,
                'taxAmount' => 0.00,
                'salesAmountWithTax' => $lineTotal
            ]
        ];
        
        $receiptData = [
            'deviceID' => $deviceId, // Required for signature
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => 'USD',
            'receiptCounter' => 1,
            'receiptGlobalNo' => 1,
            'invoiceNo' => 'TEST-' . $deviceId . '-' . date('YmdHis'),
            'receiptDate' => date('Y-m-d\TH:i:s'),
            'receiptLinesTaxInclusive' => true,
            'receiptLines' => [
                [
                    'receiptLineType' => 'Sale',
                    'receiptLineNo' => 1,
                    'receiptLineHSCode' => '04021099',
                    'receiptLineName' => 'Test Item',
                    'receiptLinePrice' => $lineTotal,
                    'receiptLineQuantity' => 1,
                    'receiptLineTotal' => $lineTotal,
                    'taxCode' => $taxCode,
                    'taxPercent' => $taxPercent,
                    'taxID' => $taxID
                ]
            ],
            'receiptTaxes' => $receiptTaxes, // Required!
            'receiptTotal' => $receiptTotal, // Required!
            'receiptPayments' => [
                [
                    'moneyTypeCode' => 'Cash',
                    'paymentAmount' => $receiptTotal
                ]
            ]
        ];
        
        try {
            $submitResult = $fiscalService->submitReceipt(0, $receiptData, null);
            writeOutput("  ✓ Receipt submitted successfully\n", $output);
            writeOutput("    Receipt ID: " . ($submitResult['receiptID'] ?? 'N/A') . "\n", $output);
            writeOutput("    QR Code: " . (isset($submitResult['qrCode']) ? 'Generated' : 'N/A') . "\n", $output);
            
            if (isset($submitResult['validationErrors'])) {
                writeOutput("    ⚠ Validation Errors: " . count($submitResult['validationErrors']) . "\n", $output);
                foreach ($submitResult['validationErrors'] as $error) {
                    writeOutput("      - " . ($error['validationErrorCode'] ?? 'Unknown') . "\n", $output);
                }
            }
        } catch (Exception $e) {
            writeOutput("  ✗ Receipt submission failed: " . $e->getMessage() . "\n", $output);
            if (strpos($e->getMessage(), 'RCPT020') !== false) {
                writeOutput("    ⚠ RCPT020 Error: Invalid signature - check logs for signature string\n", $output);
            }
        }
        
        writeOutput("\n✓ Device $deviceId test completed\n", $output);
        
    } catch (Exception $e) {
        writeOutput("\n✗ Error for device $deviceId: " . $e->getMessage() . "\n", $output);
        writeOutput("  Stack trace:\n" . $e->getTraceAsString() . "\n", $output);
    }
    
    writeOutput("\n", $output);
}

writeOutput("========================================\n", $output);
writeOutput("TEST COMPLETE\n", $output);
writeOutput("Completed: " . date('Y-m-d H:i:s') . "\n", $output);
writeOutput("========================================\n", $output);
writeOutput("\nAll operations logged to:\n", $output);
writeOutput("  - TXT: logs/zimra/zimra_operations_" . date('Y-m-d') . ".txt\n", $output);
writeOutput("  - LOG: logs/error.log\n", $output);
writeOutput("  - DATABASE: zimra_operation_logs, zimra_receipt_logs, zimra_certificates\n", $output);
writeOutput("  - TEST RESULTS: $outputFile\n", $output);
writeOutput("\n", $output);

fclose($output);
echo "\nTest results saved to: $outputFile\n";

