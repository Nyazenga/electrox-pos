<?php
/**
 * Test script for device 30199 only
 * After manual fiscal day closure
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

try {
    $db = Database::getPrimaryInstance();
    
    // Ensure device is active
    $device = $db->getRow("SELECT * FROM fiscal_devices WHERE device_id = :device_id AND branch_id = :branch_id", [':device_id' => $deviceId, ':branch_id' => $branchId]);
    if (!$device || !$device['is_active']) {
        echo "✗ Device $deviceId is not active for branch $branchId.\n";
        exit;
    }
    
    // Re-register device if needed (after reset)
    if (!$device['is_registered'] || empty($device['certificate_pem'])) {
        echo "Re-registering device (device was reset)...\n";
        $fiscalService = new FiscalService($branchId);
        $regResult = $fiscalService->registerDevice();
        if ($regResult) {
            echo "✓ Device registered successfully!\n";
        } else {
            echo "✗ Device registration failed.\n";
            exit;
        }
    }
    
    $fiscalService = new FiscalService($branchId);
    
    // STEP 1: Get status from ZIMRA (source of truth) - BEFORE any operations
    echo "\nGetting fiscal day status from ZIMRA (initial check)...\n";
    $initialStatus = $fiscalService->getFiscalDayStatus();
    
    $initialFiscalDayStatus = $initialStatus['fiscalDayStatus'] ?? 'Unknown';
    $initialFiscalDayNo = $initialStatus['lastFiscalDayNo'] ?? 0;
    $initialLastReceiptGlobalNo = $initialStatus['lastReceiptGlobalNo'] ?? 0;
    
    echo "  Status: $initialFiscalDayStatus\n";
    echo "  Fiscal Day No: " . ($initialFiscalDayNo > 0 ? $initialFiscalDayNo : 'N/A (no fiscal day yet)') . "\n";
    echo "  Last Receipt Global No: " . ($initialLastReceiptGlobalNo > 0 ? $initialLastReceiptGlobalNo : 'N/A (no receipts yet)') . "\n";
    
    // STEP 2: Handle fiscal day status properly
    if ($initialFiscalDayStatus === 'FiscalDayCloseFailed') {
        echo "\n⚠️  CRITICAL: Fiscal day close previously failed!\n";
        echo "  ZIMRA still has this fiscal day open with existing receipts.\n";
        echo "  Attempting to close it properly before proceeding...\n\n";
        
        try {
            $closeResult = $fiscalService->closeFiscalDay();
            echo "✓ Fiscal day closed successfully\n";
            sleep(3); // Wait for ZIMRA to process (closeDay is asynchronous)
            
            // Get status again after closing
            $status = $fiscalService->getFiscalDayStatus();
            $initialFiscalDayStatus = $status['fiscalDayStatus'] ?? 'Unknown';
            echo "  New Status: $initialFiscalDayStatus\n\n";
        } catch (Exception $e) {
            echo "✗ Could not close fiscal day: " . $e->getMessage() . "\n";
            echo "\n⚠️  Cannot proceed - fiscal day must be closed first.\n";
            exit;
        }
    }
    
    // STEP 3: Handle fiscal day - close if open with receipts, then open new one
    if ($initialFiscalDayStatus === 'FiscalDayOpened') {
        echo "\n⚠️  Fiscal day is already open (Day No: $initialFiscalDayNo)\n";
        if ($initialLastReceiptGlobalNo > 0) {
            echo "  Last receipt global no: $initialLastReceiptGlobalNo\n";
            echo "  Closing fiscal day to start fresh...\n";
            try {
                $closeResult = $fiscalService->closeFiscalDay();
                echo "✓ Fiscal day closed successfully\n";
                sleep(3); // Wait for ZIMRA to process (closeDay is asynchronous)
                
                // Get status again after closing
                $status = $fiscalService->getFiscalDayStatus();
                $fiscalDayStatus = $status['fiscalDayStatus'] ?? 'Unknown';
                echo "  New Status: $fiscalDayStatus\n\n";
            } catch (Exception $e) {
                echo "✗ Could not close fiscal day: " . $e->getMessage() . "\n";
                echo "  Will try to continue with existing fiscal day...\n\n";
                $status = $initialStatus;
                $fiscalDayStatus = $initialFiscalDayStatus;
                $lastFiscalDayNo = $initialFiscalDayNo;
                $lastReceiptGlobalNo = $initialLastReceiptGlobalNo;
            }
        } else {
            echo "  No existing receipts - can use this fiscal day\n\n";
            $status = $initialStatus;
            $fiscalDayStatus = $initialFiscalDayStatus;
            $lastFiscalDayNo = $initialFiscalDayNo;
            $lastReceiptGlobalNo = $initialLastReceiptGlobalNo;
        }
    }
    
    // Now open a new fiscal day if needed
    if ($fiscalDayStatus !== 'FiscalDayOpened') {
        echo "\nOpening fiscal day...\n";
        try {
            $openResult = $fiscalService->openFiscalDay();
            $openedFiscalDayNo = $openResult['fiscalDayNo'] ?? 0;
            echo "✓ Fiscal day opened (Day No: $openedFiscalDayNo)\n";
            sleep(2); // Wait a bit
            
            // Get status again after opening
            $status = $fiscalService->getFiscalDayStatus();
            $fiscalDayStatus = $status['fiscalDayStatus'] ?? 'Unknown';
            $lastFiscalDayNo = $status['lastFiscalDayNo'] ?? 0;
            $lastReceiptGlobalNo = $status['lastReceiptGlobalNo'] ?? 0;
        } catch (Exception $e) {
            echo "✗ Could not open fiscal day: " . $e->getMessage() . "\n";
            exit;
        }
    } else {
        echo "\n✓ Fiscal day already open\n";
    }
    
    // STEP 4: Determine starting counters
    // Get the final status after all operations
    $finalStatus = $fiscalService->getFiscalDayStatus();
    $finalFiscalDayNo = $finalStatus['lastFiscalDayNo'] ?? 0;
    $finalLastGlobalNo = $finalStatus['lastReceiptGlobalNo'] ?? 0;
    $finalFiscalDayStatus = $finalStatus['fiscalDayStatus'] ?? 'Unknown';
    
    echo "\nFinal Status After Operations:\n";
    echo "  Status: $finalFiscalDayStatus\n";
    echo "  Fiscal Day No: $finalFiscalDayNo\n";
    echo "  Last Receipt Global No: $finalLastGlobalNo\n";
    
    // Check if we just opened a NEW fiscal day
    $fiscalDayWasJustOpened = false;
    if ($initialFiscalDayStatus !== 'FiscalDayOpened' && $finalFiscalDayStatus === 'FiscalDayOpened') {
        // Status changed from closed to opened
        if ($finalFiscalDayNo > $initialFiscalDayNo || $initialFiscalDayNo == 0) {
            $fiscalDayWasJustOpened = true;
        }
    }
    
    if ($fiscalDayWasJustOpened) {
        // We just opened a NEW fiscal day
        // receiptCounter: MUST be 1 (first receipt in new fiscal day)
        // receiptGlobalNo: Can be 1 (allowed by documentation) OR continue from last + 1
        // Documentation: "receiptGlobalNo may be equal to 1 for the first receipt in a fiscal day"
        echo "\n✓ NEW fiscal day just opened (Day No: $finalFiscalDayNo)\n";
        echo "  Previous fiscal day: " . ($initialFiscalDayNo > 0 ? $initialFiscalDayNo : 'None') . ", New fiscal day: $finalFiscalDayNo\n";
        if ($initialLastReceiptGlobalNo > 0) {
            echo "  Last receipt global no from previous fiscal day: $initialLastReceiptGlobalNo\n";
            echo "  Trying: Counter 1 (new fiscal day), Global No 1 (allowed by documentation for first receipt in fiscal day)\n";
            echo "  If this fails, will try Global No " . ($initialLastReceiptGlobalNo + 1) . " (continuing sequence)\n\n";
            // Try starting from 1 first (documentation allows this)
            $startCounter = 1; // MUST be 1 for first receipt in fiscal day
            $startGlobalNo = 1; // Try 1 first - documentation explicitly allows this
        } else {
            echo "  Starting from: Counter 1, Global No 1 (allowed by documentation)\n\n";
            $startCounter = 1;
            $startGlobalNo = 1; // Documentation allows this for first receipt in fiscal day
        }
    } elseif ($finalFiscalDayStatus === 'FiscalDayOpened' && $finalLastGlobalNo > 0) {
        // Fiscal day was already open and has receipts
        // Check database for last receipt counter in this fiscal day
        $lastReceiptInFiscalDay = $db->getRow(
            "SELECT receipt_counter, receipt_global_no 
             FROM fiscal_receipts 
             WHERE device_id = :device_id 
             AND fiscal_day_no = :fiscal_day_no 
             AND submission_status = 'Submitted'
             ORDER BY receipt_counter DESC, receipt_global_no DESC 
             LIMIT 1",
            [
                ':device_id' => $deviceId,
                ':fiscal_day_no' => $finalFiscalDayNo
            ]
        );
        
        if ($lastReceiptInFiscalDay && isset($lastReceiptInFiscalDay['receipt_counter'])) {
            // We have a receipt in our DB for this fiscal day - continue from last counter + 1
            $lastCounter = intval($lastReceiptInFiscalDay['receipt_counter']);
            echo "\n⚠️  Fiscal day already open with existing receipts\n";
            echo "  Fiscal Day No: $finalFiscalDayNo\n";
            echo "  Last Receipt Counter (from DB): $lastCounter\n";
            echo "  Last Receipt Global No (from ZIMRA): $finalLastGlobalNo\n";
            echo "  Starting from: Counter " . ($lastCounter + 1) . ", Global No " . ($finalLastGlobalNo + 1) . "\n\n";
            $startCounter = $lastCounter + 1;
            $startGlobalNo = $finalLastGlobalNo + 1;
        } else {
            // No receipts in our DB for this fiscal day - ZIMRA has receipts we don't know about
            // CRITICAL: We don't know the last receiptCounter in this fiscal day
            // We'll try starting from counter 1, but if it fails with RCPT011, we'll need to try higher counters
            echo "\n⚠️  Fiscal day already open with existing receipts (not in our DB)\n";
            echo "  Fiscal Day No: $finalFiscalDayNo\n";
            echo "  Last Receipt Global No (from ZIMRA): $finalLastGlobalNo\n";
            echo "  Starting from: Counter 1 (will retry with higher counter if RCPT011), Global No " . ($finalLastGlobalNo + 1) . "\n";
            echo "  ⚠️  WARNING: Counter may need to be higher if ZIMRA already has receipts in this fiscal day!\n\n";
            $startCounter = 1; // Try 1 first - will retry if needed
            $startGlobalNo = $finalLastGlobalNo + 1; // Continue global sequence
        }
    } else {
        // No existing receipts - start from 1
        echo "\n✓ No existing receipts - starting from 1 (allowed by documentation)\n\n";
        $startCounter = 1;
        $startGlobalNo = 1;
    }
    
    // If fiscal day close failed, we're stuck in the same fiscal day
    // We MUST continue from the last receipt numbers
    if ($finalFiscalDayStatus === 'FiscalDayCloseFailed') {
        echo "\n⚠️  CRITICAL: Fiscal day close failed - cannot open new fiscal day!\n";
        echo "  We must continue from existing receipts in fiscal day $finalFiscalDayNo\n";
        if ($finalLastGlobalNo > 0) {
            echo "  Last Receipt Global No: $finalLastGlobalNo\n";
            echo "  Starting from: Counter 1 (will retry if RCPT011), Global No " . ($finalLastGlobalNo + 1) . "\n\n";
            $startCounter = 1; // Try 1 first
            $startGlobalNo = $finalLastGlobalNo + 1; // Must continue global sequence
        }
    }
    
    echo "Starting from receipt counter: $startCounter, global no: $startGlobalNo\n\n";
    
    // STEP 5: Send 3 consecutive receipts
    $previousReceiptHash = null;
    $logFile = APP_PATH . '/logs/device_30199_test_responses.txt';
    @file_put_contents($logFile, "DEVICE 30199 TEST - " . date('Y-m-d H:i:s') . "\n========================================\n\n", FILE_APPEND);
    
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
            'invoiceNo' => 'DEVICE-' . $deviceId . '-TEST-' . $i . '-' . date('YmdHis'),
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
        
        // Log full ZIMRA response
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
    echo "DEVICE 30199 TEST COMPLETE\n";
    echo "========================================\n";
    echo "Full ZIMRA responses logged to: $logFile\n\n";
    
} catch (Exception $e) {
    echo "\n✗ ERROR:\n";
    echo "  " . $e->getMessage() . "\n";
    echo "  Stack Trace:\n" . $e->getTraceAsString() . "\n\n";
}

echo "\n========================================\n";

