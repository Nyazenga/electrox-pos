<?php
/**
 * Test device 30199 - Send 3 consecutive receipts with full ZIMRA response logging
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
echo "TESTING DEVICE 30199 - 3 CONSECUTIVE RECEIPTS\n";
echo "========================================\n\n";

// Log file for ZIMRA responses
$logFile = APP_PATH . '/logs/device_30199_test_responses.txt';
@file_put_contents($logFile, "========================================\n");
@file_put_contents($logFile, "DEVICE 30199 TEST - " . date('Y-m-d H:i:s') . "\n");
@file_put_contents($logFile, "========================================\n\n", FILE_APPEND);

try {
    $db = Database::getPrimaryInstance();
    
    // Activate device 30199
    $db->update('fiscal_devices', ['is_active' => 0], ['device_id' => 30199]);
    $db->update('fiscal_devices', ['is_active' => 1, 'branch_id' => $branchId], ['device_id' => $deviceId]);
    echo "✓ Activated device 30199 for branch $branchId\n\n";
    
    $fiscalService = new FiscalService($branchId);
    
    // Check registration
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id AND branch_id = :branch_id",
        [':device_id' => $deviceId, ':branch_id' => $branchId]
    );
    
    if (!$device || !$device['is_registered'] || empty($device['certificate_pem'])) {
        echo "Device not registered. Registering...\n";
        $db->update('fiscal_devices', [
            'is_registered' => 0,
            'certificate_pem' => null,
            'private_key_pem' => null
        ], ['device_id' => $deviceId]);
        
        $fiscalService = new FiscalService($branchId);
        $registerResult = $fiscalService->registerDevice();
        if (isset($registerResult['certificate'])) {
            echo "✓ Device registered successfully!\n";
            sleep(3);
            $fiscalService = new FiscalService($branchId);
        } else {
            throw new Exception("Registration failed");
        }
    } else {
        echo "✓ Device already registered\n";
    }
    
    // Get/open fiscal day
    echo "\nGetting fiscal day status...\n";
    $status = $fiscalService->getFiscalDayStatus();
    echo "  Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    
    if (($status['fiscalDayStatus'] ?? '') !== 'FiscalDayOpened') {
        echo "Opening fiscal day...\n";
        $openResult = $fiscalService->openFiscalDay();
        echo "✓ Fiscal day opened (Day No: " . ($openResult['fiscalDayNo'] ?? 'N/A') . ")\n";
        $fiscalDayNo = $openResult['fiscalDayNo'] ?? 1;
    } else {
        $fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
        echo "✓ Fiscal day already open (Day No: $fiscalDayNo)\n";
    }
    
    // For reset devices, start from counter 1, global no 1
    // Check ZIMRA status to see if device was actually reset
    $lastGlobalFromZimra = $status['lastReceiptGlobalNo'] ?? 0;
    
    if ($lastGlobalFromZimra > 0) {
        echo "\n⚠ WARNING: ZIMRA shows lastReceiptGlobalNo = $lastGlobalFromZimra\n";
        echo "  Device may not be fully reset. Will try to continue from ZIMRA's last receipt.\n\n";
        $startCounter = 1; // Still try counter 1 for new fiscal day
        $startGlobalNo = $lastGlobalFromZimra + 1;
    } else {
        echo "\n✓ Device appears reset - starting fresh from counter 1, global no 1\n\n";
        $startCounter = 1;
        $startGlobalNo = 1;
    }
    
    echo "Starting from receipt counter: $startCounter, global no: $startGlobalNo\n\n";
    
    $previousReceiptHash = null;
    
    // Send 3 consecutive receipts
    for ($i = 1; $i <= 3; $i++) {
        $receiptCounter = $startCounter + $i - 1;
        $receiptGlobalNo = $startGlobalNo + $i - 1;
        
        echo "========================================\n";
        echo "RECEIPT #$i (Counter: $receiptCounter, Global No: $receiptGlobalNo)\n";
        echo "========================================\n";
        
        $receiptData = [
            'deviceID' => $deviceId,
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => 'USD',
            'receiptCounter' => $receiptCounter,
            'receiptGlobalNo' => $receiptGlobalNo,
            'invoiceNo' => 'DEVICE-30199-TEST-' . $i . '-' . date('YmdHis'),
            'receiptDate' => date('Y-m-d\TH:i:s'),
            'receiptLinesTaxInclusive' => true,
            'receiptLines' => [
                [
                    'receiptLineType' => 'Sale',
                    'receiptLineNo' => 1,
                    'receiptLineHSCode' => '04021099',
                    'receiptLineName' => "Test Item #$i",
                    'receiptLinePrice' => floatval(10.00 + $i),
                    'receiptLineQuantity' => floatval(1),
                    'receiptLineTotal' => floatval(10.00 + $i),
                    'taxID' => 517,
                    'taxPercent' => 15.5
                ]
            ],
            'receiptTaxes' => [
                [
                    'taxPercent' => 15.5,
                    'taxID' => 517,
                    'taxAmount' => round((10.00 + $i) * 0.155 / 1.155, 2),
                    'salesAmountWithTax' => floatval(10.00 + $i)
                ]
            ],
            'receiptTotal' => floatval(10.00 + $i),
            'receiptPayments' => [
                [
                    'moneyTypeCode' => 0, // Cash
                    'paymentAmount' => floatval(10.00 + $i)
                ]
            ],
            'receiptPrintForm' => 'InvoiceA4'
        ];
        
        // Remove taxCode from receiptLines and receiptTaxes
        foreach ($receiptData['receiptLines'] as &$line) {
            unset($line['taxCode']);
        }
        unset($line);
        
        foreach ($receiptData['receiptTaxes'] as &$tax) {
            unset($tax['taxCode']);
        }
        unset($tax);
        
        echo "Receipt Data:\n";
        echo "  Counter: $receiptCounter\n";
        echo "  Global No: $receiptGlobalNo\n";
        echo "  Total: " . $receiptData['receiptTotal'] . "\n";
        echo "  Previous Hash: " . ($previousReceiptHash ? substr($previousReceiptHash, 0, 30) . '...' : 'NULL (first receipt)') . "\n\n";
        
        // Submit receipt
        $result = $fiscalService->submitReceipt(0, $receiptData, 0);
        
        // Log full ZIMRA response
        $logEntry = "\n========================================\n";
        $logEntry .= "RECEIPT #$i - " . date('Y-m-d H:i:s') . "\n";
        $logEntry .= "========================================\n";
        $logEntry .= "Receipt Counter: $receiptCounter\n";
        $logEntry .= "Receipt Global No: $receiptGlobalNo\n";
        $logEntry .= "Previous Receipt Hash: " . ($previousReceiptHash ?? 'NULL') . "\n\n";
        $logEntry .= "FULL ZIMRA RESPONSE:\n";
        $logEntry .= json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        // Display result
        echo "ZIMRA RESPONSE:\n";
        echo "  Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
        echo "  Server Date: " . ($result['serverDate'] ?? 'N/A') . "\n";
        echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
        
        if (isset($result['receiptServerSignature']['hash'])) {
            $zimraHash = $result['receiptServerSignature']['hash'];
            echo "  ZIMRA Hash: $zimraHash\n";
            $previousReceiptHash = $zimraHash; // Use ZIMRA's hash for next receipt
        }
        
        if (!empty($result['validationErrors'])) {
            echo "\n  VALIDATION ERRORS:\n";
            foreach ($result['validationErrors'] as $error) {
                $code = $error['validationErrorCode'] ?? 'N/A';
                $color = $error['validationErrorColor'] ?? 'N/A';
                echo "    - $code ($color)\n";
            }
            echo "\n✗ RECEIPT #$i HAD VALIDATION ERRORS\n";
        } else {
            echo "\n✓✓✓ RECEIPT #$i SUCCESSFUL - NO VALIDATION ERRORS!\n";
        }
        
        echo "\n";
        sleep(2); // Wait between receipts
    }
    
    echo "========================================\n";
    echo "TEST COMPLETE\n";
    echo "========================================\n";
    echo "Full ZIMRA responses logged to: $logFile\n";
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
    
    $errorLog = "\n========================================\n";
    $errorLog .= "ERROR - " . date('Y-m-d H:i:s') . "\n";
    $errorLog .= "========================================\n";
    $errorLog .= $e->getMessage() . "\n";
    $errorLog .= "Stack Trace:\n" . $e->getTraceAsString() . "\n\n";
    @file_put_contents($logFile, $errorLog, FILE_APPEND);
}

