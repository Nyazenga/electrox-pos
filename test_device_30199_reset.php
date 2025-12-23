<?php
/**
 * Test device 30199 after ZIMRA reset
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$deviceId = 30199;
$branchId = 1;

echo "========================================\n";
echo "TESTING DEVICE 30199 (AFTER RESET)\n";
echo "========================================\n\n";

try {
    // First, ensure device 30199 is active for branch 1
    $db = Database::getPrimaryInstance();
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
        [':device_id' => $deviceId]
    );
    
    if ($device) {
        // Set device 30199 as active and deactivate 30200
        $db->update('fiscal_devices', ['is_active' => 0], ['device_id' => 30200]);
        $db->update('fiscal_devices', ['is_active' => 1, 'branch_id' => $branchId], ['device_id' => $deviceId]);
        echo "✓ Activated device 30199 for branch $branchId\n\n";
    }
    
    $fiscalService = new FiscalService($branchId);
    
    // Step 1: Verify device in database
    echo "Step 1: Verifying device in database...\n";
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id AND branch_id = :branch_id",
        [':device_id' => $deviceId, ':branch_id' => $branchId]
    );
    
    if (!$device) {
        echo "  Device not found. Creating...\n";
        $deviceId_db = $db->insert('fiscal_devices', [
            'branch_id' => $branchId,
            'device_id' => $deviceId,
            'device_serial_no' => 'electrox-1',
            'activation_key' => '00544726',
            'device_model_name' => 'Server',
            'device_model_version' => 'v1',
            'is_registered' => 0
        ]);
        echo "  ✓ Device created (ID: $deviceId_db)\n";
    } else {
        echo "  ✓ Device found (ID: {$device['id']})\n";
    }
    
    // Step 2: Re-register device (since it was reset)
    echo "\nStep 2: Re-registering device (reset requires new registration)...\n";
    
    // Clear registration status since ZIMRA reset the device
    $db->update('fiscal_devices', [
        'is_registered' => 0,
        'certificate_pem' => null,
        'private_key_pem' => null
    ], ['device_id' => $deviceId]);
    
    echo "  ✓ Cleared registration status in database\n";
    
    // Re-instantiate FiscalService to pick up the cleared status
    $fiscalService = new FiscalService($branchId);
    
    try {
        $registerResult = $fiscalService->registerDevice();
        if (isset($registerResult['certificate'])) {
            echo "  ✓ Device registered successfully!\n";
            echo "    Certificate received and saved\n";
        } else {
            throw new Exception("Registration failed - no certificate received");
        }
        sleep(3); // Wait for certificate to be processed
        
        // Re-instantiate again to load the new certificate
        $fiscalService = new FiscalService($branchId);
    } catch (Exception $e) {
        echo "  ✗ Registration failed: " . $e->getMessage() . "\n";
        throw $e;
    }
    
    // Step 3: Get fiscal day status
    echo "\nStep 3: Getting fiscal day status...\n";
    try {
        $status = $fiscalService->getFiscalDayStatus();
        echo "  Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
        echo "  Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
        echo "  Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n";
        
        // Open fiscal day if not open
        if (($status['fiscalDayStatus'] ?? '') !== 'FiscalDayOpened') {
            echo "\n  Opening fiscal day...\n";
            $openResult = $fiscalService->openFiscalDay();
            echo "  ✓ Fiscal day opened (Day No: " . ($openResult['fiscalDayNo'] ?? 'N/A') . ")\n";
        } else {
            echo "  ✓ Fiscal day already open\n";
        }
    } catch (Exception $e) {
        echo "  ⚠ " . $e->getMessage() . "\n";
        // Try to open anyway
        try {
            $fiscalService->openFiscalDay();
            echo "  ✓ Fiscal day opened\n";
        } catch (Exception $e2) {
            echo "  ✗ Could not open fiscal day: " . $e2->getMessage() . "\n";
            throw $e2;
        }
    }
    
    // Step 4: Submit receipt with counter = 1 (first receipt after reset)
    echo "\nStep 4: Submitting test receipt with counter = 1...\n";
    
    $receiptData = [
        'deviceID' => $deviceId,
        'receiptType' => 'FISCALINVOICE',
        'receiptCurrency' => 'USD',
        'receiptCounter' => 1, // First receipt in fiscal day
        'receiptGlobalNo' => 1, // First global receipt
        'invoiceNo' => 'DEVICE-30199-RESET-TEST-' . date('YmdHis'),
        'receiptDate' => date('Y-m-d\TH:i:s'),
        'receiptLinesTaxInclusive' => true,
        'receiptLines' => [
            [
                'receiptLineType' => 'Sale',
                'receiptLineNo' => 1,
                'receiptLineHSCode' => '04021099',
                'receiptLineName' => 'Test Item After Reset',
                'receiptLinePrice' => 10.00,
                'receiptLineQuantity' => 1,
                'receiptLineTotal' => 10.00,
                'taxCode' => 'C',
                'taxPercent' => 0,
                'taxID' => 2
            ]
        ],
        'receiptTaxes' => [
            [
                'taxID' => 2,
                'taxCode' => 'C',
                'taxPercent' => 0,
                'taxAmount' => 0,
                'salesAmountWithTax' => 10.00
            ]
        ],
        'receiptTotal' => 10.00,
        'receiptPayments' => [
            [
                'moneyTypeCode' => 'Cash',
                'paymentAmount' => 10.00
            ]
        ]
    ];
    
    echo "  Receipt data prepared:\n";
    echo "    Counter: " . $receiptData['receiptCounter'] . "\n";
    echo "    Global No: " . $receiptData['receiptGlobalNo'] . "\n";
    echo "    Total: " . $receiptData['receiptTotal'] . "\n\n";
    
    $result = $fiscalService->submitReceipt(0, $receiptData);
    
    echo "\n========================================\n";
    echo "RESULT:\n";
    echo "========================================\n";
    echo "Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
    echo "Server Date: " . ($result['serverDate'] ?? 'N/A') . "\n";
    
    if (!empty($result['validationErrors'])) {
        echo "\nValidation Errors:\n";
        foreach ($result['validationErrors'] as $error) {
            $code = $error['validationErrorCode'] ?? 'N/A';
            $color = $error['validationErrorColor'] ?? 'N/A';
            echo "  - $code ($color)\n";
            
            if ($code === 'RCPT011') {
                echo "    ⚠ RCPT011: Receipt counter issue\n";
            } elseif ($code === 'RCPT020') {
                echo "    ⚠ RCPT020: Invalid signature (should be fixed!)\n";
            }
        }
        echo "\n✗ RECEIPT HAD VALIDATION ERRORS\n";
    } else {
        echo "\n✓✓✓ SUCCESS! NO VALIDATION ERRORS!\n";
        echo "   Receipt accepted by ZIMRA!\n";
        echo "   Device 30199 is now working correctly!\n";
    }
    
    // Check hash comparison
    if (isset($result['receiptServerSignature']['hash'])) {
        echo "\nHash Comparison:\n";
        echo "  ZIMRA Hash: " . substr($result['receiptServerSignature']['hash'], 0, 30) . "...\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    
    if (strpos($e->getMessage(), 'RCPT011') !== false) {
        echo "\n⚠ RCPT011 error - counter issue\n";
    } elseif (strpos($e->getMessage(), 'RCPT020') !== false) {
        echo "\n⚠ RCPT020 error - signature issue (should be fixed!)\n";
    }
}

echo "\n========================================\n";

