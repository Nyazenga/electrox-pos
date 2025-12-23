<?php
/**
 * Test script with detailed ZIMRA payload and response logging
 * Creates human-readable logs for sharing with ZIMRA support
 * 
 * FIXES:
 * - Opens fiscal day first
 * - Gets taxpayerDayMaxHrs from config
 * - Validates receiptDate against RCPT014, RCPT030, RCPT031, RCPT041
 * - Starts from receipt #1 with no previous hash
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';
require_once APP_PATH . '/includes/fiscal_helper.php';
require_once APP_PATH . '/includes/zimra_signature.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create log file with timestamp
$logDir = APP_PATH . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/zimra_detailed_test_' . date('Y-m-d_H-i-s') . '.txt';

function logToFile($message, $logFile) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    echo $logMessage;
}

function logSeparator($title, $logFile) {
    $separator = str_repeat('=', 80);
    logToFile("", $logFile);
    logToFile($separator, $logFile);
    logToFile("  $title", $logFile);
    logToFile($separator, $logFile);
    logToFile("", $logFile);
}

function logJson($data, $title, $logFile) {
    logToFile("$title:", $logFile);
    logToFile(str_repeat('-', 80), $logFile);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    logToFile($json, $logFile);
    logToFile(str_repeat('-', 80), $logFile);
    logToFile("", $logFile);
}

// Start logging
logSeparator("ZIMRA DETAILED TEST LOG - 3 CONSECUTIVE RECEIPTS", $logFile);
logToFile("Test Date: " . date('Y-m-d H:i:s'), $logFile);
logToFile("Device ID: 30199", $logFile);
logToFile("Branch ID: 1", $logFile);
logToFile("", $logFile);

$deviceId = 30199;
$branchId = 1;
$taxpayerDayMaxHrs = 24; // Default fallback
$fiscalDayOpened = null;

try {
    $db = Database::getPrimaryInstance();
    
    // Activate device
    logSeparator("ACTIVATING DEVICE", $logFile);
    $db->update('fiscal_devices', ['is_active' => 0], ['device_id' => 30200]); // Deactivate other device
    $db->update('fiscal_devices', ['is_active' => 1, 'branch_id' => $branchId], ['device_id' => $deviceId]);
    logToFile("✓ Activated device $deviceId for branch $branchId", $logFile);
    logToFile("", $logFile);
    
    // Get device for certificate access
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id AND branch_id = :branch_id",
        [':device_id' => $deviceId, ':branch_id' => $branchId]
    );
    
    if (!$device || empty($device['certificate_pem']) || empty($device['private_key_pem'])) {
        logToFile("✗ ERROR: Device not found or missing certificate/key", $logFile);
        exit(1);
    }
    
    // Get device config to check taxpayerDayMaxHrs
    logSeparator("GETTING DEVICE CONFIG FROM ZIMRA", $logFile);
    try {
        require_once APP_PATH . '/includes/zimra_api.php';
        $zimraApi = new ZimraApi(
            $device['certificate_pem'],
            $device['private_key_pem'],
            true // test mode
        );
        
        $config = $zimraApi->getConfig($deviceId);
        if (is_array($config) && isset($config['taxPayerDayMaxHrs'])) {
            $taxpayerDayMaxHrs = intval($config['taxPayerDayMaxHrs']);
            logToFile("✓ taxpayerDayMaxHrs from ZIMRA: $taxpayerDayMaxHrs hours", $logFile);
        } else {
            logToFile("⚠ taxpayerDayMaxHrs not found in config response, using default: $taxpayerDayMaxHrs hours", $logFile);
        }
        logJson($config, "ZIMRA Config Response", $logFile);
    } catch (Exception $e) {
        logToFile("⚠ Error calling getConfig: " . $e->getMessage() . " (using default $taxpayerDayMaxHrs hours)", $logFile);
        logToFile("", $logFile);
    }
    
    // Initialize fiscal service
    logSeparator("INITIALIZING FISCAL SERVICE", $logFile);
    $fiscalService = new FiscalService($branchId);
    logToFile("✓ Fiscal service initialized", $logFile);
    logToFile("", $logFile);
    
    // Get fiscal day status and open if needed
    logSeparator("GETTING FISCAL DAY STATUS", $logFile);
    try {
        $fiscalDayStatus = $fiscalService->getFiscalDayStatus();
        logJson($fiscalDayStatus, "ZIMRA Status Response", $logFile);
        
        $lastGlobalNo = $fiscalDayStatus['lastReceiptGlobalNo'] ?? 0;
        $lastFiscalDayNo = $fiscalDayStatus['lastFiscalDayNo'] ?? 0;
        $status = $fiscalDayStatus['fiscalDayStatus'] ?? 'Unknown';
        
        logToFile("Last Fiscal Day No: $lastFiscalDayNo", $logFile);
        logToFile("Last Receipt Global No: $lastGlobalNo", $logFile);
        logToFile("Fiscal Day Status: $status", $logFile);
        logToFile("", $logFile);
        
        if ($status === 'FiscalDayClosed') {
            logSeparator("OPENING FISCAL DAY", $logFile);
            try {
                $openResult = $fiscalService->openFiscalDay();
                logToFile("✓ Fiscal day opened", $logFile);
                logJson($openResult, "Open Fiscal Day Result", $logFile);
                
                // Get the fiscal day opened timestamp
                if (isset($openResult['fiscalDayOpened'])) {
                    $fiscalDayOpened = $openResult['fiscalDayOpened'];
                }
                
                // Also check from database
                $fiscalDay = $db->getRow(
                    "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id ORDER BY id DESC LIMIT 1",
                    [':branch_id' => $branchId, ':device_id' => $deviceId]
                );
                if ($fiscalDay && isset($fiscalDay['fiscal_day_opened'])) {
                    $fiscalDayOpened = $fiscalDay['fiscal_day_opened'];
                }
                
                sleep(2); // Wait for ZIMRA to process
            } catch (Exception $e) {
                logToFile("✗ Error opening fiscal day: " . $e->getMessage(), $logFile);
                exit(1);
            }
        } else {
            logToFile("✓ Fiscal day is already open", $logFile);
            
            // Get the fiscal day opened timestamp from database
            $fiscalDay = $db->getRow(
                "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND (status = 'FiscalDayOpened' OR status = 'FiscalDayCloseFailed') ORDER BY id DESC LIMIT 1",
                [':branch_id' => $branchId, ':device_id' => $deviceId]
            );
            if ($fiscalDay && isset($fiscalDay['fiscal_day_opened'])) {
                $fiscalDayOpened = $fiscalDay['fiscal_day_opened'];
            }
        }
        
        if ($fiscalDayOpened) {
            logToFile("✓ Fiscal day opened at: $fiscalDayOpened", $logFile);
            $fiscalDayOpenedTimestamp = strtotime($fiscalDayOpened);
            $maxReceiptDateTimestamp = $fiscalDayOpenedTimestamp + ($taxpayerDayMaxHrs * 3600);
            $maxReceiptDate = date('Y-m-d\TH:i:s', $maxReceiptDateTimestamp);
            logToFile("✓ Maximum allowed receiptDate (fiscal day opened + $taxpayerDayMaxHrs hours): $maxReceiptDate", $logFile);
            logToFile("", $logFile);
        }
        
        // Get updated status for counters
        $fiscalDayStatus = $fiscalService->getFiscalDayStatus();
        $lastGlobalNo = $fiscalDayStatus['lastReceiptGlobalNo'] ?? 0;
        
    } catch (Exception $e) {
        logToFile("✗ Error getting/opening fiscal day: " . $e->getMessage(), $logFile);
        exit(1);
    }
    
    // Prepare to submit 3 receipts starting from #1
    logSeparator("PREPARING TO SUBMIT 3 RECEIPTS", $logFile);
    $startCounter = 1; // Always start from 1 in new fiscal day
    $startGlobalNo = $lastGlobalNo + 1;
    
    logToFile("Starting Receipt Counter: $startCounter (first receipt in fiscal day - no previous hash)", $logFile);
    logToFile("Starting Receipt Global No: $startGlobalNo (lastReceiptGlobalNo + 1)", $logFile);
    logToFile("", $logFile);
    
    $previousReceiptHash = null; // First receipt has no previous hash
    $lastReceiptDate = null; // Track for RCPT030
    
    // Submit 3 consecutive receipts
    for ($i = 1; $i <= 3; $i++) {
        $receiptCounter = $startCounter + $i - 1;
        $receiptGlobalNo = $startGlobalNo + $i - 1;
        
        logSeparator("RECEIPT #$i - PREPARATION", $logFile);
        
        // CRITICAL: Calculate receiptDate to comply with all validation rules
        // RCPT014: receiptDate must be > fiscal day opened
        // RCPT030: receiptDate must be > previously submitted receiptDate
        // RCPT031: receiptDate must be <= current time (with small tolerance)
        // RCPT041: receiptDate must be <= fiscal day opened + taxpayerDayMaxHrs
        
        $currentTime = time();
        $receiptDateTimestamp = $currentTime;
        
        if ($fiscalDayOpened) {
            $fiscalDayOpenedTimestamp = strtotime($fiscalDayOpened);
            $maxReceiptDateTimestamp = $fiscalDayOpenedTimestamp + ($taxpayerDayMaxHrs * 3600);
            
            // RCPT014: Ensure receiptDate > fiscal day opened (use fiscal day opened + 1 second minimum)
            $minReceiptDateTimestamp = $fiscalDayOpenedTimestamp + 1;
            
            // RCPT030: Ensure receiptDate > last receipt date (use last receipt date + 1 second)
            if ($lastReceiptDate !== null) {
                $lastReceiptDateTimestamp = strtotime($lastReceiptDate);
                if ($lastReceiptDateTimestamp >= $minReceiptDateTimestamp) {
                    $minReceiptDateTimestamp = $lastReceiptDateTimestamp + 1;
                }
            }
            
            // Now ensure receiptDateTimestamp is within bounds
            if ($receiptDateTimestamp < $minReceiptDateTimestamp) {
                $receiptDateTimestamp = $minReceiptDateTimestamp;
            }
            
            if ($receiptDateTimestamp > $maxReceiptDateTimestamp) {
                $receiptDateTimestamp = $maxReceiptDateTimestamp - 1; // Subtract 1 second to be safe
                logToFile("⚠ WARNING: Adjusted receiptDate to meet RCPT041 (max allowed): " . date('Y-m-d\TH:i:s', $receiptDateTimestamp), $logFile);
            }
            
            // RCPT031: Ensure receiptDate <= current time
            if ($receiptDateTimestamp > $currentTime) {
                $receiptDateTimestamp = $currentTime;
            }
        }
        
        $receiptDate = date('Y-m-d\TH:i:s', $receiptDateTimestamp);
        $lastReceiptDate = $receiptDate; // Update for next receipt (RCPT030)
        
        logToFile("Receipt Date (validated for RCPT014, RCPT030, RCPT031, RCPT041): $receiptDate", $logFile);
        logToFile("", $logFile);
        
        $invoiceNo = "TEST-" . date('YmdHis') . "-$i";
        
        // Prepare receipt data
        $receiptData = [
            'deviceID' => $deviceId,
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => 'USD',
            'receiptCounter' => $receiptCounter,
            'receiptGlobalNo' => $receiptGlobalNo,
            'invoiceNo' => $invoiceNo,
            'receiptDate' => $receiptDate,
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
                    'taxPercent' => 15.5,
                    'taxCode' => 'A' // Needed for signature
                ]
            ],
            'receiptTaxes' => [
                [
                    'taxPercent' => 15.5,
                    'taxID' => 517,
                    'taxCode' => 'A', // Needed for signature
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
        
        logToFile("Receipt Counter: $receiptCounter", $logFile);
        logToFile("Receipt Global No: $receiptGlobalNo", $logFile);
        logToFile("Receipt Total: " . $receiptData['receiptTotal'], $logFile);
        logToFile("Previous Receipt Hash: " . ($previousReceiptHash ?? 'NULL (first receipt)'), $logFile);
        logToFile("", $logFile);
        
        // Prepare the EXACT payload that will be sent to ZIMRA
        logSeparator("RECEIPT #$i - PREPARING FINAL PAYLOAD (WITH SIGNATURE)", $logFile);
        
        // Make a copy for payload building
        $payloadReceiptData = $receiptData;
        
        // Generate signature (this is what fiscal_service.php does)
        // NOTE: Signature must be generated BEFORE removing taxCode
        $deviceSignature = ZimraSignature::generateReceiptDeviceSignature(
            $payloadReceiptData,
            $previousReceiptHash,
            $device['private_key_pem']
        );
        
        // Add signature to receipt data
        $payloadReceiptData['receiptDeviceSignature'] = $deviceSignature;
        
        // Remove taxCode from receiptLines and receiptTaxes (needed for signature but not in JSON)
        if (!empty($payloadReceiptData['receiptLines'])) {
            foreach ($payloadReceiptData['receiptLines'] as &$line) {
                unset($line['taxCode']);
            }
            unset($line);
        }
        
        if (!empty($payloadReceiptData['receiptTaxes'])) {
            foreach ($payloadReceiptData['receiptTaxes'] as &$tax) {
                unset($tax['taxCode']);
                // Reorder: taxPercent before taxID
                $reorderedTax = [];
                if (isset($tax['taxPercent'])) $reorderedTax['taxPercent'] = $tax['taxPercent'];
                if (isset($tax['taxID'])) $reorderedTax['taxID'] = $tax['taxID'];
                if (isset($tax['taxAmount'])) $reorderedTax['taxAmount'] = $tax['taxAmount'];
                if (isset($tax['salesAmountWithTax'])) $reorderedTax['salesAmountWithTax'] = $tax['salesAmountWithTax'];
                $tax = $reorderedTax;
            }
            unset($tax);
        }
        
        // Remove deviceID from receipt data (it's only in URL path, not JSON)
        unset($payloadReceiptData['deviceID']);
        
        // Build the final payload structure as sent to ZIMRA
        $finalPayload = [
            'Receipt' => $payloadReceiptData
        ];
        
        // Log the EXACT payload that will be sent
        logSeparator("RECEIPT #$i - EXACT PAYLOAD TO BE SENT TO ZIMRA", $logFile);
        logJson($finalPayload, "COMPLETE PAYLOAD (EXACTLY AS WILL BE SENT TO ZIMRA)", $logFile);
        
        if (isset($finalPayload['Receipt']['receiptDeviceSignature'])) {
            $sig = $finalPayload['Receipt']['receiptDeviceSignature'];
            logToFile("✓ receiptDeviceSignature IS in the payload:", $logFile);
            logToFile("  Hash: " . ($sig['hash'] ?? 'N/A'), $logFile);
            logToFile("  Signature (first 50 chars): " . substr($sig['signature'] ?? '', 0, 50) . "...", $logFile);
            logToFile("", $logFile);
        } else {
            logToFile("✗ ERROR: receiptDeviceSignature NOT found in prepared payload!", $logFile);
            logToFile("", $logFile);
        }
        
        logToFile("Note: previousReceiptHash = " . ($previousReceiptHash ?? 'NULL (first receipt)'), $logFile);
        logToFile("  - previousReceiptHash is NOT a JSON field (only used in signature string)", $logFile);
        logToFile("", $logFile);
        
        // Now submit the receipt
        logSeparator("RECEIPT #$i - SUBMITTING TO ZIMRA", $logFile);
        
        try {
            $result = $fiscalService->submitReceipt(
                0, // invoice_id
                $receiptData, // This will be modified by submitReceipt to add signature
                0, // sale_id
                $previousReceiptHash  // Used INSIDE signature string, NOT as JSON field
            );
            
            // After submission, log the response
            logSeparator("RECEIPT #$i - ZIMRA RESPONSE", $logFile);
            logJson($result, "COMPLETE ZIMRA RESPONSE (JSON Response Body)", $logFile);
            
            // Extract key information
            $receiptId = $result['receiptID'] ?? 'N/A';
            $serverDate = $result['serverDate'] ?? 'N/A';
            $operationId = $result['operationID'] ?? 'N/A';
            
            logToFile("Receipt ID: $receiptId", $logFile);
            logToFile("Server Date: $serverDate", $logFile);
            logToFile("Operation ID: $operationId", $logFile);
            logToFile("", $logFile);
            
            // Check for validation errors
            if (isset($result['validationErrors']) && !empty($result['validationErrors'])) {
                logSeparator("RECEIPT #$i - VALIDATION ERRORS", $logFile);
                foreach ($result['validationErrors'] as $error) {
                    $errorCode = $error['validationErrorCode'] ?? 'N/A';
                    $errorColor = $error['validationErrorColor'] ?? 'N/A';
                    $errorMessage = $error['validationErrorDescription'] ?? 'N/A';
                    logToFile("  [$errorColor] $errorCode: $errorMessage", $logFile);
                }
                logToFile("", $logFile);
            } else {
                logToFile("✓ No validation errors", $logFile);
                logToFile("", $logFile);
            }
            
            // Extract ZIMRA's hash for the next receipt
            if (isset($result['receiptServerSignature']['hash']) && !empty($result['receiptServerSignature']['hash'])) {
                $zimraHash = $result['receiptServerSignature']['hash'];
                logToFile("✓ ZIMRA Hash: " . substr($zimraHash, 0, 30) . "...", $logFile);
                $previousReceiptHash = $zimraHash; // Use ZIMRA's hash for next receipt
            } else {
                logToFile("⚠ No receiptServerSignature.hash in ZIMRA response (might be first receipt)", $logFile);
                // For first receipt, ZIMRA might not return hash, so use our generated hash
                $previousReceiptHash = $deviceSignature['hash'];
            }
            logToFile("", $logFile);
            
        } catch (Exception $e) {
            logSeparator("RECEIPT #$i - ERROR", $logFile);
            logToFile("✗ Error submitting receipt: " . $e->getMessage(), $logFile);
            logToFile("", $logFile);
            break; // Stop on error
        }
        
        sleep(1); // Small delay between receipts
    }
    
    logSeparator("TEST COMPLETE", $logFile);
    logToFile("Log file: $logFile", $logFile);
    
} catch (Exception $e) {
    logToFile("FATAL ERROR: " . $e->getMessage(), $logFile);
    logToFile("Stack trace: " . $e->getTraceAsString(), $logFile);
    exit(1);
}

