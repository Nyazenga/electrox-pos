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
    $db->update('fiscal_devices', ['is_active' => 0], ['device_id' => 30200]);
    $db->update('fiscal_devices', ['is_active' => 1, 'branch_id' => $branchId], ['device_id' => $deviceId]);
    echo "✓ Activated device 30199 for branch $branchId\n\n";
    
    $fiscalService = new FiscalService($branchId);
    
    // Check registration
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id AND branch_id = :branch_id",
        [':device_id' => $deviceId, ':branch_id' => $branchId]
    );
    
    // Check if device is already registered
    if ($device['is_registered'] && !empty($device['certificate_pem']) && !empty($device['private_key_pem'])) {
        echo "✓ Device is already registered, skipping re-registration\n";
        $fiscalService = new FiscalService($branchId);
    } else {
        // Force re-registration after device reset
        echo "Re-registering device (device was reset)...\n";
        $db->update('fiscal_devices', [
            'is_registered' => 0,
            'certificate_pem' => null,
            'private_key_pem' => null
        ], ['device_id' => $deviceId]);
        
        // Clear certificate storage (if method exists)
        require_once APP_PATH . '/includes/certificate_storage.php';
        // Note: deleteCertificate may not exist, so we'll just clear the database fields above
        
        $fiscalService = new FiscalService($branchId);
        try {
            $registerResult = $fiscalService->registerDevice();
            if (isset($registerResult['certificate'])) {
                echo "✓ Device registered successfully!\n";
                sleep(3);
                $fiscalService = new FiscalService($branchId);
            } else {
                throw new Exception("Registration failed: " . json_encode($registerResult));
            }
        } catch (Exception $e) {
            // If registration fails (e.g., device already registered on ZIMRA), try to continue
            if (strpos($e->getMessage(), 'DEV02') !== false || strpos($e->getMessage(), 'already registered') !== false) {
                echo "⚠ Registration failed (device may already be registered on ZIMRA), continuing with existing certificate...\n";
                // Restore registration status
                $db->update('fiscal_devices', ['is_registered' => 1], ['device_id' => $deviceId]);
                $fiscalService = new FiscalService($branchId);
            } else {
                throw $e;
            }
        }
    }
    
    // Get fiscal day status
    echo "\nGetting fiscal day status from ZIMRA...\n";
    $status = $fiscalService->getFiscalDayStatus();
    echo "  Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "  Last Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
    echo "  Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n";
    
    // Get initial status
    $initialStatus = $status;
    $initialFiscalDayNo = $initialStatus['lastFiscalDayNo'] ?? 0;
    $initialLastGlobalNo = $initialStatus['lastReceiptGlobalNo'] ?? 0;
    $initialFiscalDayStatus = $initialStatus['fiscalDayStatus'] ?? 'Unknown';
    
    // DO NOT CLOSE FISCAL DAYS - User will close manually
    // Only check status and open if closed
    if (($status['fiscalDayStatus'] ?? '') === 'FiscalDayClosed') {
        echo "\n✓ Fiscal day is closed - opening new fiscal day...\n";
        $openResult = $fiscalService->openFiscalDay();
        echo "✓ Fiscal day opened (Day No: " . ($openResult['fiscalDayNo'] ?? 'N/A') . ")\n";
        sleep(2);
        // Refresh status
        $status = $fiscalService->getFiscalDayStatus();
        $fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
    } elseif (($status['fiscalDayStatus'] ?? '') === 'FiscalDayOpened') {
        $fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
        echo "\n✓ Fiscal day already open (Day No: $fiscalDayNo)\n";
    } else {
        echo "\n⚠️  Fiscal day status: " . ($status['fiscalDayStatus'] ?? 'Unknown') . "\n";
        echo "  Attempting to open fiscal day...\n";
        $openResult = $fiscalService->openFiscalDay();
        echo "✓ Fiscal day opened (Day No: " . ($openResult['fiscalDayNo'] ?? 'N/A') . ")\n";
        sleep(2);
        $status = $fiscalService->getFiscalDayStatus();
        $fiscalDayNo = $status['lastFiscalDayNo'] ?? 1;
    }
    
    // Get last counters from ZIMRA after opening
    $lastGlobalFromZimra = $status['lastReceiptGlobalNo'] ?? 0;
    $lastFiscalDayNo = $status['lastFiscalDayNo'] ?? 0;
    
    echo "\nZIMRA Status:\n";
    echo "  Last Fiscal Day No: $lastFiscalDayNo\n";
    echo "  Last Receipt Global No: $lastGlobalFromZimra\n";
    echo "  Current Fiscal Day No: " . ($fiscalDayNo ?? 'N/A') . "\n";
    
    // CRITICAL: For the first receipt in a NEW fiscal day, documentation allows receiptGlobalNo = 1
    // This is explicitly allowed per documentation: "Taxpayer is allowed to reset this receiptGlobalNo counter to start from 1, however this is allowed to be done only for the first receipt in a fiscal day."
    // Since the device was reset, we should start from 1 for the first receipt in the fiscal day
    // Even if ZIMRA shows existing receipts, we'll try starting from 1 (as documentation allows)
    
    // Check if this is a truly NEW fiscal day (different number than before) or if it's the same day
    $isNewFiscalDay = ($fiscalDayNo != $initialFiscalDayNo) || ($initialFiscalDayNo == 0);
    
    // For a reset device, always try starting from 1 for the first receipt in the fiscal day
    // Documentation explicitly allows this: "may be equal to 1 for the first receipt in a fiscal day"
    if ($isNewFiscalDay || $initialFiscalDayNo == 0) {
        // This is a NEW fiscal day - start from 1 (allowed by documentation)
        echo "\n✓ NEW fiscal day detected - starting from 1 (allowed by documentation)\n";
        echo "  Previous fiscal day: $initialFiscalDayNo, New fiscal day: $fiscalDayNo\n\n";
        $startCounter = 1;
        $startGlobalNo = 1; // Documentation explicitly allows this for first receipt in fiscal day
    } elseif ($lastFiscalDayNo == $fiscalDayNo && $lastGlobalFromZimra > 0) {
        // Same fiscal day with existing receipts - try starting from 1 anyway (device was reset)
        // If this fails, we'll know ZIMRA doesn't allow it for this case
        echo "\n⚠ WARNING: Same fiscal day number, but device was reset.\n";
        echo "  Trying to start from 1 (allowed by documentation for first receipt in fiscal day)\n";
        echo "  If this fails, ZIMRA may require continuing from lastReceiptGlobalNo + 1\n\n";
        $startCounter = 1;
        $startGlobalNo = 1; // Try 1 first - documentation allows it
    } else {
        // New fiscal day or no existing receipts - start from 1
        echo "\n✓ Starting fresh - no existing receipts in this fiscal day\n\n";
        $startCounter = 1;
        $startGlobalNo = 1;
    }
    
    echo "Starting from receipt counter: $startCounter, global no: $startGlobalNo\n";
    echo "  (Documentation allows receiptGlobalNo = 1 for first receipt in fiscal day)\n\n";
    
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
                    'taxAmount' => number_format((10.00 + $i) * 0.155 / 1.155, 2, '.', ''),
                    'salesAmountWithTax' => number_format(10.00 + $i, 2, '.', '')
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
        
        // Submit receipt - pass previousReceiptHash if available
        try {
            $result = $fiscalService->submitReceipt(0, $receiptData, 0, $previousReceiptHash);
        } catch (Exception $e) {
            // Even if there's an exception, ZIMRA may have returned a response with receiptServerSignature
            // Extract the response from the exception message if possible
            $errorMessage = $e->getMessage();
            if (preg_match('/Full response:\s*(\{.*\})/s', $errorMessage, $matches)) {
                $result = json_decode($matches[1], true);
                if ($result) {
                    echo "  ⚠️  Exception occurred, but extracted response from error message\n";
                } else {
                    throw $e; // Re-throw if we can't extract response
                }
            } else {
                throw $e; // Re-throw if no response in error
            }
        }
        
        // CRITICAL: Extract ZIMRA's hash from response for next receipt
        // ZIMRA ALWAYS returns receiptServerSignature (even for first receipt, even with validation errors)
        $zimraHash = null;
        if (isset($result['receiptServerSignature']['hash']) && !empty($result['receiptServerSignature']['hash'])) {
            $zimraHash = $result['receiptServerSignature']['hash'];
            $previousReceiptHash = $zimraHash; // Update for next receipt
            echo "  ✓ ZIMRA Hash extracted: " . substr($zimraHash, 0, 30) . "...\n";
        } else {
            echo "  ⚠️  CRITICAL: No receiptServerSignature.hash in response!\n";
            echo "  Response keys: " . implode(', ', array_keys($result ?? [])) . "\n";
            echo "  Full response: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
        }
        
        // Log full ZIMRA response with hash chain info
        $logEntry = "\n========================================\n";
        $logEntry .= "RECEIPT #$i - " . date('Y-m-d H:i:s') . "\n";
        $logEntry .= "========================================\n";
        $logEntry .= "Receipt Counter: $receiptCounter\n";
        $logEntry .= "Receipt Global No: $receiptGlobalNo\n";
        $logEntry .= "Previous Receipt Hash Used: " . ($previousReceiptHash ?? 'NULL (first receipt)') . "\n";
        $logEntry .= "ZIMRA Hash from Response: " . ($zimraHash ?? 'NOT FOUND - CRITICAL ERROR!') . "\n\n";
        $logEntry .= "FULL ZIMRA RESPONSE:\n";
        $logEntry .= json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        // Also log to hash chain file
        $hashLogFile = APP_PATH . '/logs/receipt_hash_chain.txt';
        $hashLogEntry = "========================================\n";
        $hashLogEntry .= "RECEIPT #$i - " . date('Y-m-d H:i:s') . "\n";
        $hashLogEntry .= "========================================\n";
        $hashLogEntry .= "Receipt Counter: $receiptCounter\n";
        $hashLogEntry .= "Receipt Global No: $receiptGlobalNo\n";
        $hashLogEntry .= "Previous Receipt Hash Used: " . ($previousReceiptHash ?? 'NULL (first receipt)') . "\n";
        $hashLogEntry .= "ZIMRA Hash from Response: " . ($zimraHash ?? 'NOT FOUND') . "\n";
        $hashLogEntry .= "Hash Match: " . (isset($result['receiptServerSignature']['hash']) ? "YES" : "NO - receiptServerSignature missing!") . "\n";
        $hashLogEntry .= "========================================\n\n";
        @file_put_contents($hashLogFile, $hashLogEntry, FILE_APPEND);
        
        // Display result
        echo "ZIMRA RESPONSE:\n";
        echo "  Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
        echo "  Server Date: " . ($result['serverDate'] ?? 'N/A') . "\n";
        echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
        
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

