<?php
/**
 * Clean test - Shows ONLY payload sent and response received
 * Starts from receipt #1 with NO previous hash
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';
require_once APP_PATH . '/includes/fiscal_helper.php';
require_once APP_PATH . '/includes/zimra_qrcode.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$logDir = APP_PATH . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/zimra_detailed_test_' . date('Y-m-d_H-i-s') . '.txt';

// Define constant for signature logging to use the same log file
define('ZIMRA_TEST_LOG_FILE', $logFile);

function logToFile($message, $logFile) {
    file_put_contents($logFile, $message . "\n", FILE_APPEND);
    echo $message . "\n";
}

$deviceId = 30199;
$branchId = 1;

try {
    $db = Database::getPrimaryInstance();
    
    // Activate device
    $db->update('fiscal_devices', ['is_active' => 0], ['device_id' => 30200]);
    $db->update('fiscal_devices', ['is_active' => 1, 'branch_id' => $branchId], ['device_id' => $deviceId]);
    
    // Get device
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id AND branch_id = :branch_id",
        [':device_id' => $deviceId, ':branch_id' => $branchId]
    );
    
    if (!$device || empty($device['certificate_pem']) || empty($device['private_key_pem'])) {
        logToFile("ERROR: Device not found or missing certificate/key", $logFile);
        exit(1);
    }
    
    // Initialize fiscal service
    $fiscalService = new FiscalService($branchId);
    
    // Get status and ensure fiscal day is open
    $status = $fiscalService->getFiscalDayStatus();
    if ($status['fiscalDayStatus'] === 'FiscalDayClosed') {
        logToFile("Fiscal day is closed. Opening new fiscal day...", $logFile);
        $fiscalService->openFiscalDay();
        sleep(2);
    }
    
    // Get updated status
    $status = $fiscalService->getFiscalDayStatus();
    $fiscalDayNo = $status['lastFiscalDayNo'] ?? 0;
    
    // CRITICAL: Check if this fiscal day has any receipts
    // If it does, the receiptCounter will NOT start from 1
    $existingReceipts = $db->getRow(
        "SELECT COUNT(*) as count FROM fiscal_receipts WHERE device_id = :device_id AND fiscal_day_no = :fiscal_day_no",
        [':device_id' => $deviceId, ':fiscal_day_no' => $fiscalDayNo]
    );
    
    if ($existingReceipts['count'] > 0) {
        logToFile("ERROR: Fiscal day #$fiscalDayNo already has {$existingReceipts['count']} receipt(s).", $logFile);
        logToFile("Receipt counter will continue from {$existingReceipts['count']}, NOT from 1.", $logFile);
        logToFile("", $logFile);
        logToFile("SOLUTION: Please close the fiscal day and run this test again to start from receipt #1.", $logFile);
        logToFile("", $logFile);
        exit(1);
    } else {
        logToFile("✓ Fiscal day #$fiscalDayNo is fresh - no receipts yet. Will start from receipt #1.", $logFile);
    }
    
    logToFile("=" . str_repeat("=", 78) . "=", $logFile);
    logToFile(" ZIMRA TEST - Device 30199 - 3 Consecutive Receipts", $logFile);
    logToFile("=" . str_repeat("=", 78) . "=", $logFile);
    logToFile("Fiscal Day No: $fiscalDayNo", $logFile);
    logToFile("", $logFile);
    
    $previousReceiptHash = null;
    
    for ($i = 1; $i <= 3; $i++) {
        logToFile(str_repeat("=", 80), $logFile);
        logToFile(" RECEIPT #$i", $logFile);
        logToFile(str_repeat("=", 80), $logFile);
        logToFile("Previous Hash: " . ($previousReceiptHash ?: 'NULL (first receipt)'), $logFile);
        logToFile("", $logFile);
        
        // Prepare receipt data
        $receiptData = [
            'deviceID' => $deviceId,
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => 'USD',
            'invoiceNo' => 'TEST-' . date('YmdHis') . '-' . $i,
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
                    'taxPercent' => 15.5,
                    'taxCode' => 'A'
                ]
            ],
            'receiptTaxes' => [
                [
                    'taxPercent' => 15.5,
                    'taxID' => 517,
                    'taxCode' => 'A',
                    'taxAmount' => round((10.00 + $i) * 0.155 / 1.155, 2),
                    'salesAmountWithTax' => floatval(10.00 + $i)
                ]
            ],
            'receiptTotal' => floatval(10.00 + $i),
            'receiptPayments' => [
                [
                    'moneyTypeCode' => 0,
                    'paymentAmount' => floatval(10.00 + $i)
                ]
            ],
            'receiptPrintForm' => 'InvoiceA4'
        ];
        
        // Submit - fiscal_service will calculate counter and generate signature
        // Pass null for first receipt to ensure no previous hash
        $hashForSubmit = ($i === 1) ? null : $previousReceiptHash;
        
        // Get line count before submission
        $interfaceLogFile = APP_PATH . '/logs/interface_payload_log.txt';
        $linesBefore = 0;
        if (file_exists($interfaceLogFile)) {
            $linesBefore = count(file($interfaceLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        }
        
        try {
            // We need to capture the RAW ZIMRA response before fiscal_service modifies it
            // Since fiscal_service doesn't return the raw response, we'll log what fiscal_service returns
            // but also note which fields are from ZIMRA vs generated locally
            $result = $fiscalService->submitReceipt(0, $receiptData, 0, $hashForSubmit);
            
            // Read ACTUAL payload from interface_payload_log.txt
            $actualPayload = null;
            if (file_exists($interfaceLogFile)) {
                $fileContent = file_get_contents($interfaceLogFile);
                // Find the last occurrence of "JSON Payload:" and get the JSON after it
                $lines = explode("\n", $fileContent);
                for ($j = count($lines) - 1; $j >= 0; $j--) {
                    if (strpos($lines[$j], 'JSON Payload:') !== false) {
                        // Next line should be the JSON
                        if (isset($lines[$j + 1])) {
                            $jsonLine = trim($lines[$j + 1]);
                            $actualPayload = json_decode($jsonLine, true);
                            if ($actualPayload) break;
                        }
                    }
                }
            }
            
            // LOG ACTUAL PAYLOAD SENT
            logToFile("PAYLOAD SENT TO ZIMRA:", $logFile);
            if ($actualPayload) {
                logToFile(json_encode($actualPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $logFile);
            } else {
                logToFile("(Could not read payload)", $logFile);
            }
            logToFile("", $logFile);
            
            // LOG RESPONSE FROM fiscal_service (this includes both ZIMRA response AND locally generated fields)
            logToFile("RESPONSE FROM fiscal_service->submitReceipt():", $logFile);
            logToFile("(NOTE: This includes ZIMRA's response PLUS locally generated qrCode, verificationCode, qrCodeImage)", $logFile);
            logToFile("", $logFile);
            logToFile("FIELDS FROM ZIMRA (as-is):", $logFile);
            $zimraFields = [
                'fiscalReceiptId' => $result['fiscalReceiptId'] ?? null,
                'receiptID' => $result['receiptID'] ?? null,
                'receiptGlobalNo' => $result['receiptGlobalNo'] ?? null,
                'serverDate' => $result['serverDate'] ?? null,
                'operationID' => $result['operationID'] ?? null,
                'receiptServerSignature' => $result['receiptServerSignature'] ?? null,
                'validationErrors' => $result['validationErrors'] ?? null
            ];
            logToFile(json_encode($zimraFields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $logFile);
            logToFile("", $logFile);
            logToFile("FIELDS GENERATED LOCALLY (NOT from ZIMRA):", $logFile);
            $localFields = [
                'qrCode' => $result['qrCode'] ?? null,
                'verificationCode' => $result['verificationCode'] ?? null,
                'qrCodeImage' => isset($result['qrCodeImage']) ? '[Base64 image data]' : null
            ];
            logToFile(json_encode($localFields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $logFile);
            logToFile("", $logFile);
            logToFile("COMPLETE RESPONSE (all fields combined):", $logFile);
            logToFile(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $logFile);
            logToFile("", $logFile);
            
            // TEST: Use OUR generated hash instead of ZIMRA's hash for next receipt
            // Extract OUR hash from the payload we sent (not ZIMRA's response)
            if (isset($actualPayload['receipt']['receiptDeviceSignature']['hash']) && !empty($actualPayload['receipt']['receiptDeviceSignature']['hash'])) {
                $previousReceiptHash = $actualPayload['receipt']['receiptDeviceSignature']['hash'];
                logToFile("✓ TEST: Using OUR generated hash for next receipt: " . $previousReceiptHash, $logFile);
                if (isset($result['receiptServerSignature']['hash']) && !empty($result['receiptServerSignature']['hash'])) {
                    $zimraHash = $result['receiptServerSignature']['hash'];
                    logToFile("  (ZIMRA's hash from response: " . $zimraHash . ")", $logFile);
                    logToFile("  (Hash match: " . ($previousReceiptHash === $zimraHash ? "YES" : "NO") . ")", $logFile);
                }
            } else {
                logToFile("⚠ Could not find our generated hash in payload, falling back to ZIMRA's hash", $logFile);
                // Fallback to ZIMRA's hash if ours is not available
                if (isset($result['receiptServerSignature']['hash']) && !empty($result['receiptServerSignature']['hash'])) {
                    $previousReceiptHash = $result['receiptServerSignature']['hash'];
                }
            }
            
            // Generate QR codes for comparison - MATCHING PYTHON LIBRARY EXACTLY
            logToFile("", $logFile);
            logToFile(str_repeat("-", 80), $logFile);
            logToFile(" QR CODE GENERATION - COMPARISON (MATCHING PYTHON LIBRARY)", $logFile);
            logToFile(str_repeat("-", 80), $logFile);
            
            // Get QR URL from config
            $config = $db->getRow(
                "SELECT qr_url FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
                [':branch_id' => $branchId, ':device_id' => $deviceId]
            );
            
            if (!empty($config['qr_url'])) {
                $qrUrl = $config['qr_url'];
                $receiptGlobalNo = $result['receiptGlobalNo'] ?? null;
                $receiptDate = $result['serverDate'] ?? $receiptData['receiptDate'];
                
                // Extract signatures and hashes
                $deviceSignature = null;
                if (isset($actualPayload['receipt']['receiptDeviceSignature']['signature'])) {
                    $deviceSignature = $actualPayload['receipt']['receiptDeviceSignature']['signature'];
                }
                
                $serverSignature = null;
                if (isset($result['receiptServerSignature']['signature'])) {
                    $serverSignature = $result['receiptServerSignature']['signature'];
                }
                
                $deviceHash = null;
                if (isset($actualPayload['receipt']['receiptDeviceSignature']['hash'])) {
                    $deviceHash = $actualPayload['receipt']['receiptDeviceSignature']['hash'];
                }
                
                $serverHash = null;
                if (isset($result['receiptServerSignature']['hash'])) {
                    $serverHash = $result['receiptServerSignature']['hash'];
                }
                
                // Python method: base64 decode -> hex -> bytes.fromhex(hex) -> MD5 -> first 16 chars
                // This is: base64_decode -> bin2hex -> hex2bin -> md5 -> substr 0,16
                
                // QR CODE #1: Device SIGNATURE - Python method (hex string -> bytes -> MD5)
                if ($deviceSignature && $receiptGlobalNo) {
                    $byteArray = base64_decode($deviceSignature);
                    $hexStr = bin2hex($byteArray);
                    // Python: bytes.fromhex(hex_str) - converts hex string back to bytes
                    $hexBytes = hex2bin($hexStr); // This is bytes.fromhex() equivalent
                    $md5Hash = md5($hexBytes); // MD5 of bytes (not hex string!)
                    $qrData1 = substr($md5Hash, 0, 16); // Lowercase, no uppercase conversion
                    $qrCode1 = ZimraQRCode::generateQRCode($qrUrl, $deviceId, $receiptDate, $receiptGlobalNo, strtoupper($qrData1));
                    logToFile("", $logFile);
                    logToFile("QR CODE #1 (Device SIGNATURE - Python method: hex->bytes->MD5):", $logFile);
                    logToFile("  Signature (base64): " . substr($deviceSignature, 0, 50) . "...", $logFile);
                    logToFile("  Hex string length: " . strlen($hexStr), $logFile);
                    logToFile("  MD5 hash: $md5Hash", $logFile);
                    logToFile("  QR Data (first 16): $qrData1", $logFile);
                    logToFile("  >>> TEST THIS URL: " . $qrCode1['qrCode'] . " <<<", $logFile);
                }
                
                // QR CODE #2: Device SIGNATURE - Our old method (hex string -> MD5 of hex string)
                if ($deviceSignature && $receiptGlobalNo) {
                    $byteArray = base64_decode($deviceSignature);
                    $hexStr = bin2hex($byteArray);
                    $md5Hash = md5($hexStr); // MD5 of hex STRING (not bytes)
                    $qrData2 = substr($md5Hash, 0, 16);
                    $qrCode2 = ZimraQRCode::generateQRCode($qrUrl, $deviceId, $receiptDate, $receiptGlobalNo, strtoupper($qrData2));
                    logToFile("", $logFile);
                    logToFile("QR CODE #2 (Device SIGNATURE - Old method: hex string->MD5):", $logFile);
                    logToFile("  MD5 hash: $md5Hash", $logFile);
                    logToFile("  QR Data: $qrData2", $logFile);
                    logToFile("  >>> TEST THIS URL: " . $qrCode2['qrCode'] . " <<<", $logFile);
                }
                
                // QR CODE #3: Server SIGNATURE - Python method
                if ($serverSignature && $receiptGlobalNo) {
                    $byteArray = base64_decode($serverSignature);
                    $hexStr = bin2hex($byteArray);
                    $hexBytes = hex2bin($hexStr);
                    $md5Hash = md5($hexBytes);
                    $qrData3 = substr($md5Hash, 0, 16);
                    $qrCode3 = ZimraQRCode::generateQRCode($qrUrl, $deviceId, $receiptDate, $receiptGlobalNo, strtoupper($qrData3));
                    logToFile("", $logFile);
                    logToFile("QR CODE #3 (Server SIGNATURE - Python method: hex->bytes->MD5):", $logFile);
                    logToFile("  MD5 hash: $md5Hash", $logFile);
                    logToFile("  QR Data: $qrData3", $logFile);
                    logToFile("  >>> TEST THIS URL: " . $qrCode3['qrCode'] . " <<<", $logFile);
                }
                
                // QR CODE #4: Server SIGNATURE - Our old method
                if ($serverSignature && $receiptGlobalNo) {
                    $byteArray = base64_decode($serverSignature);
                    $hexStr = bin2hex($byteArray);
                    $md5Hash = md5($hexStr);
                    $qrData4 = substr($md5Hash, 0, 16);
                    $qrCode4 = ZimraQRCode::generateQRCode($qrUrl, $deviceId, $receiptDate, $receiptGlobalNo, strtoupper($qrData4));
                    logToFile("", $logFile);
                    logToFile("QR CODE #4 (Server SIGNATURE - Old method: hex string->MD5):", $logFile);
                    logToFile("  MD5 hash: $md5Hash", $logFile);
                    logToFile("  QR Data: $qrData4", $logFile);
                    logToFile("  >>> TEST THIS URL: " . $qrCode4['qrCode'] . " <<<", $logFile);
                }
                
                // QR CODE #5: Device HASH - Use hex directly (first 16 chars)
                if ($deviceHash && $receiptGlobalNo) {
                    $hashBinary = base64_decode($deviceHash);
                    $hashHex = bin2hex($hashBinary);
                    $qrData5 = strtoupper(substr($hashHex, 0, 16));
                    $qrCode5 = ZimraQRCode::generateQRCode($qrUrl, $deviceId, $receiptDate, $receiptGlobalNo, $qrData5);
                    logToFile("", $logFile);
                    logToFile("QR CODE #5 (Device HASH - Hex first 16 chars):", $logFile);
                    logToFile("  QR Data: $qrData5", $logFile);
                    logToFile("  >>> TEST THIS URL: " . $qrCode5['qrCode'] . " <<<", $logFile);
                }
                
                // QR CODE #6: Device HASH - MD5 of hex bytes
                if ($deviceHash && $receiptGlobalNo) {
                    $hashBinary = base64_decode($deviceHash);
                    $hashHex = bin2hex($hashBinary);
                    $hexBytes = hex2bin($hashHex);
                    $md5Hash = md5($hexBytes);
                    $qrData6 = substr($md5Hash, 0, 16);
                    $qrCode6 = ZimraQRCode::generateQRCode($qrUrl, $deviceId, $receiptDate, $receiptGlobalNo, strtoupper($qrData6));
                    logToFile("", $logFile);
                    logToFile("QR CODE #6 (Device HASH - Python method: hex->bytes->MD5):", $logFile);
                    logToFile("  QR Data: $qrData6", $logFile);
                    logToFile("  >>> TEST THIS URL: " . $qrCode6['qrCode'] . " <<<", $logFile);
                }
                
                // QR CODE #7: Server HASH - Use hex directly
                if ($serverHash && $receiptGlobalNo) {
                    $hashBinary = base64_decode($serverHash);
                    $hashHex = bin2hex($hashBinary);
                    $qrData7 = strtoupper(substr($hashHex, 0, 16));
                    $qrCode7 = ZimraQRCode::generateQRCode($qrUrl, $deviceId, $receiptDate, $receiptGlobalNo, $qrData7);
                    logToFile("", $logFile);
                    logToFile("QR CODE #7 (Server HASH - Hex first 16 chars):", $logFile);
                    logToFile("  QR Data: $qrData7", $logFile);
                    logToFile("  >>> TEST THIS URL: " . $qrCode7['qrCode'] . " <<<", $logFile);
                }
                
                // QR CODE #8: Server HASH - MD5 of hex bytes
                if ($serverHash && $receiptGlobalNo) {
                    $hashBinary = base64_decode($serverHash);
                    $hashHex = bin2hex($hashBinary);
                    $hexBytes = hex2bin($hashHex);
                    $md5Hash = md5($hexBytes);
                    $qrData8 = substr($md5Hash, 0, 16);
                    $qrCode8 = ZimraQRCode::generateQRCode($qrUrl, $deviceId, $receiptDate, $receiptGlobalNo, strtoupper($qrData8));
                    logToFile("", $logFile);
                    logToFile("QR CODE #8 (Server HASH - Python method: hex->bytes->MD5):", $logFile);
                    logToFile("  QR Data: $qrData8", $logFile);
                    logToFile("  >>> TEST THIS URL: " . $qrCode8['qrCode'] . " <<<", $logFile);
                }
            }
            
        } catch (Exception $e) {
            logToFile("ERROR: " . $e->getMessage(), $logFile);
            logToFile("", $logFile);
            break;
        }
        
        sleep(1);
    }
    
    logToFile(str_repeat("=", 80), $logFile);
    logToFile("TEST COMPLETE", $logFile);
    logToFile("Log file: $logFile", $logFile);
    
} catch (Exception $e) {
    logToFile("FATAL ERROR: " . $e->getMessage(), $logFile);
    exit(1);
}
