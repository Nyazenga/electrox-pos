<?php
/**
 * Test QR Code Generation with BOTH Device Signature and Server Signature
 * Generates TWO QR codes to compare which one ZIMRA verifies correctly
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

/**
 * Generate QR data from signature (detailed logging)
 */
function generateQrDataFromSignature($signatureData, $signatureType, $logFile) {
    logToFile("", $logFile);
    logToFile("  === GENERATING QR DATA FROM $signatureType ===", $logFile);
    
    if (!isset($signatureData['signature']) || empty($signatureData['signature'])) {
        logToFile("  ERROR: Signature data missing", $logFile);
        return null;
    }
    
    $signatureBase64 = $signatureData['signature'];
    logToFile("  Step 1: Signature (base64, first 50 chars): " . substr($signatureBase64, 0, 50) . "...", $logFile);
    logToFile("  Step 1: Signature (base64) length: " . strlen($signatureBase64) . " characters", $logFile);
    
    // Decode base64 to binary
    $signatureBinary = base64_decode($signatureBase64);
    if ($signatureBinary === false) {
        logToFile("  ERROR: Failed to decode base64 signature", $logFile);
        return null;
    }
    logToFile("  Step 2: Base64 decode to binary", $logFile);
    logToFile("  Step 2: Binary length: " . strlen($signatureBinary) . " bytes", $logFile);
    
    // Convert binary to hexadecimal
    $signatureHex = bin2hex($signatureBinary);
    logToFile("  Step 3: Convert binary to hexadecimal (bin2hex)", $logFile);
    logToFile("  Step 3: Hex length: " . strlen($signatureHex) . " characters", $logFile);
    logToFile("  Step 3: Hex (first 100 chars): " . substr($signatureHex, 0, 100) . "...", $logFile);
    
    // Generate MD5 hash
    $md5Hash = md5($signatureHex);
    logToFile("  Step 4: Generate MD5 hash of hexadecimal string", $logFile);
    logToFile("  Step 4: MD5 hash (lowercase): " . $md5Hash, $logFile);
    logToFile("  Step 4: MD5 hash length: " . strlen($md5Hash) . " characters", $logFile);
    
    // Get first 16 characters (uppercase)
    $qrData = strtoupper(substr($md5Hash, 0, 16));
    logToFile("  Step 5: Take first 16 characters and convert to uppercase", $logFile);
    logToFile("  Step 5: QR Data (final): " . $qrData, $logFile);
    logToFile("  Step 5: QR Data length: " . strlen($qrData) . " characters", $logFile);
    
    logToFile("  ✓ QR Data generated: $qrData", $logFile);
    
    return $qrData;
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
    
    // Get status - assume fiscal day is already open
    $status = $fiscalService->getFiscalDayStatus();
    logToFile("Current Fiscal Day Status:", $logFile);
    logToFile("  Status: {$status['fiscalDayStatus']}", $logFile);
    logToFile("  Fiscal Day No: {$status['lastFiscalDayNo']}", $logFile);
    logToFile("  Last Receipt Global No: {$status['lastReceiptGlobalNo']}", $logFile);
    logToFile("", $logFile);
    
    if ($status['fiscalDayStatus'] !== 'FiscalDayOpened') {
        logToFile("ERROR: Fiscal day is not open. Current status: {$status['fiscalDayStatus']}", $logFile);
        logToFile("Please open a fiscal day first.", $logFile);
        exit(1);
    }
    
    $fiscalDayNo = $status['lastFiscalDayNo'] ?? 0;
    $lastReceiptGlobalNo = $status['lastReceiptGlobalNo'] ?? 0;
    $expectedReceiptGlobalNo = $lastReceiptGlobalNo + 1;
    
    logToFile("=" . str_repeat("=", 78) . "=", $logFile);
    logToFile(" QR CODE COMPARISON TEST - Device 30199", $logFile);
    logToFile("=" . str_repeat("=", 78) . "=", $logFile);
    logToFile("Fiscal Day No: $fiscalDayNo", $logFile);
    logToFile("Expected Receipt Global No: $expectedReceiptGlobalNo", $logFile);
    logToFile("", $logFile);
    
    // Get previous receipt hash for chaining
    $previousReceiptHash = null;
    if ($lastReceiptGlobalNo > 0) {
        $previousReceipt = $db->getRow(
            "SELECT receipt_hash FROM fiscal_receipts 
             WHERE device_id = :device_id AND receipt_global_no = :global_no 
             ORDER BY id DESC LIMIT 1",
            [':device_id' => $deviceId, ':global_no' => $lastReceiptGlobalNo]
        );
        if ($previousReceipt && !empty($previousReceipt['receipt_hash'])) {
            $previousReceiptHash = $previousReceipt['receipt_hash'];
            logToFile("Previous Receipt Hash (for chaining): $previousReceiptHash", $logFile);
        }
    }
    logToFile("", $logFile);
    
    logToFile(str_repeat("=", 80), $logFile);
    logToFile(" SUBMITTING RECEIPT", $logFile);
    logToFile(str_repeat("=", 80), $logFile);
    
    // Prepare receipt data
    $receiptData = [
        'deviceID' => $deviceId,
        'receiptType' => 'FiscalInvoice',
        'receiptCurrency' => 'USD',
        'invoiceNo' => 'TEST-QR-' . date('YmdHis'),
        'receiptDate' => date('Y-m-d\TH:i:s'),
        'receiptLinesTaxInclusive' => true,
        'receiptLines' => [
            [
                'receiptLineType' => 'Sale',
                'receiptLineNo' => 1,
                'receiptLineHSCode' => '04021099',
                'receiptLineName' => "QR Test Item",
                'receiptLinePrice' => floatval(15.00),
                'receiptLineQuantity' => floatval(1),
                'receiptLineTotal' => floatval(15.00),
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
                'taxAmount' => round(15.00 * 0.155 / 1.155, 2),
                'salesAmountWithTax' => floatval(15.00)
            ]
        ],
        'receiptTotal' => floatval(15.00),
        'receiptPayments' => [
            [
                'moneyTypeCode' => 0,
                'paymentAmount' => floatval(15.00)
            ]
        ],
        'receiptPrintForm' => 'InvoiceA4'
    ];
    
    // Get interface log file
    $interfaceLogFile = APP_PATH . '/logs/interface_payload_log.txt';
    
    try {
        $result = $fiscalService->submitReceipt(0, $receiptData, 0, $previousReceiptHash);
        
        // Read ACTUAL payload from interface_payload_log.txt
        $actualPayload = null;
        if (file_exists($interfaceLogFile)) {
            $fileContent = file_get_contents($interfaceLogFile);
            $lines = explode("\n", $fileContent);
            for ($j = count($lines) - 1; $j >= 0; $j--) {
                if (strpos($lines[$j], 'JSON Payload:') !== false) {
                    if (isset($lines[$j + 1])) {
                        $jsonLine = trim($lines[$j + 1]);
                        $actualPayload = json_decode($jsonLine, true);
                        if ($actualPayload) break;
                    }
                }
            }
        }
        
        logToFile("PAYLOAD SENT TO ZIMRA:", $logFile);
        if ($actualPayload) {
            logToFile(json_encode($actualPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $logFile);
        } else {
            logToFile("(Could not read payload)", $logFile);
        }
        logToFile("", $logFile);
        
        logToFile("ZIMRA RESPONSE:", $logFile);
        $zimraResponse = [
            'receiptID' => $result['receiptID'] ?? null,
            'receiptGlobalNo' => $result['receiptGlobalNo'] ?? null,
            'serverDate' => $result['serverDate'] ?? null,
            'operationID' => $result['operationID'] ?? null,
            'receiptServerSignature' => $result['receiptServerSignature'] ?? null,
            'validationErrors' => $result['validationErrors'] ?? null
        ];
        logToFile(json_encode($zimraResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $logFile);
        logToFile("", $logFile);
        
        // Extract signatures
        $deviceSignature = null;
        if (isset($actualPayload['receipt']['receiptDeviceSignature'])) {
            $deviceSignature = $actualPayload['receipt']['receiptDeviceSignature'];
        }
        
        $serverSignature = null;
        if (isset($result['receiptServerSignature'])) {
            $serverSignature = $result['receiptServerSignature'];
        }
        
        // Get QR URL from config
        $config = $db->getRow(
            "SELECT qr_url FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
            [':branch_id' => $branchId, ':device_id' => $deviceId]
        );
        
        if (empty($config['qr_url'])) {
            logToFile("ERROR: QR URL not found in config", $logFile);
            exit(1);
        }
        
        $qrUrl = $config['qr_url'];
        $receiptGlobalNo = $result['receiptGlobalNo'] ?? $expectedReceiptGlobalNo;
        $receiptDate = $result['serverDate'] ?? $receiptData['receiptDate'];
        
        logToFile(str_repeat("=", 80), $logFile);
        logToFile(" QR CODE GENERATION - COMPARISON", $logFile);
        logToFile(str_repeat("=", 80), $logFile);
        logToFile("QR URL: $qrUrl", $logFile);
        logToFile("Device ID: $deviceId", $logFile);
        logToFile("Receipt Global No: $receiptGlobalNo", $logFile);
        logToFile("Receipt Date: $receiptDate", $logFile);
        logToFile("", $logFile);
        
        // Generate QR data from DEVICE SIGNATURE
        $qrDataFromDevice = null;
        if ($deviceSignature) {
            logToFile("METHOD 1: Using ReceiptDeviceSignature (what we sent to ZIMRA)", $logFile);
            $qrDataFromDevice = generateQrDataFromSignature($deviceSignature, 'DEVICE SIGNATURE', $logFile);
        } else {
            logToFile("METHOD 1: ERROR - Device signature not found in payload", $logFile);
        }
        
        logToFile("", $logFile);
        logToFile(str_repeat("-", 80), $logFile);
        logToFile("", $logFile);
        
        // Generate QR data from SERVER SIGNATURE
        $qrDataFromServer = null;
        if ($serverSignature) {
            logToFile("METHOD 2: Using receiptServerSignature (what ZIMRA sent back)", $logFile);
            $qrDataFromServer = generateQrDataFromSignature($serverSignature, 'SERVER SIGNATURE', $logFile);
        } else {
            logToFile("METHOD 2: ERROR - Server signature not found in response", $logFile);
        }
        
        logToFile("", $logFile);
        logToFile(str_repeat("=", 80), $logFile);
        logToFile(" QR CODE URLs GENERATED", $logFile);
        logToFile(str_repeat("=", 80), $logFile);
        logToFile("", $logFile);
        
        // Generate QR Code URL #1 (using device signature)
        if ($qrDataFromDevice) {
            logToFile("QR CODE #1 (Using Device Signature):", $logFile);
            $qrCode1 = ZimraQRCode::generateQRCode($qrUrl, $deviceId, $receiptDate, $receiptGlobalNo, $qrDataFromDevice);
            logToFile("  QR Data: $qrDataFromDevice", $logFile);
            logToFile("  QR Code URL: " . $qrCode1['qrCode'], $logFile);
            logToFile("  Verification Code: " . $qrCode1['verificationCode'], $logFile);
            logToFile("", $logFile);
            logToFile("  >>> TEST THIS URL: " . $qrCode1['qrCode'] . " <<<", $logFile);
            logToFile("", $logFile);
        }
        
        // Generate QR Code URL #2 (using server signature)
        if ($qrDataFromServer) {
            logToFile("QR CODE #2 (Using Server Signature):", $logFile);
            $qrCode2 = ZimraQRCode::generateQRCode($qrUrl, $deviceId, $receiptDate, $receiptGlobalNo, $qrDataFromServer);
            logToFile("  QR Data: $qrDataFromServer", $logFile);
            logToFile("  QR Code URL: " . $qrCode2['qrCode'], $logFile);
            logToFile("  Verification Code: " . $qrCode2['verificationCode'], $logFile);
            logToFile("", $logFile);
            logToFile("  >>> TEST THIS URL: " . $qrCode2['qrCode'] . " <<<", $logFile);
            logToFile("", $logFile);
        }
        
        logToFile(str_repeat("=", 80), $logFile);
        logToFile(" SUMMARY", $logFile);
        logToFile(str_repeat("=", 80), $logFile);
        logToFile("Receipt Global No: $receiptGlobalNo", $logFile);
        logToFile("Receipt ID: " . ($result['receiptID'] ?? 'N/A'), $logFile);
        logToFile("", $logFile);
        
        if ($qrDataFromDevice) {
            logToFile("QR CODE #1 (Device Signature):", $logFile);
            logToFile("  QR Data: $qrDataFromDevice", $logFile);
            logToFile("  URL: " . $qrCode1['qrCode'], $logFile);
            logToFile("", $logFile);
        }
        
        if ($qrDataFromServer) {
            logToFile("QR CODE #2 (Server Signature):", $logFile);
            logToFile("  QR Data: $qrDataFromServer", $logFile);
            logToFile("  URL: " . $qrCode2['qrCode'], $logFile);
            logToFile("", $logFile);
        }
        
        logToFile("Test both URLs above and see which one ZIMRA verifies correctly!", $logFile);
        logToFile("", $logFile);
        logToFile("Log file: $logFile", $logFile);
        
    } catch (Exception $e) {
        logToFile("ERROR: " . $e->getMessage(), $logFile);
        logToFile("Stack trace: " . $e->getTraceAsString(), $logFile);
        exit(1);
    }
    
} catch (Exception $e) {
    logToFile("FATAL ERROR: " . $e->getMessage(), $logFile);
    exit(1);
}

