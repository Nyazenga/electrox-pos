<?php
/**
 * COMPREHENSIVE DIAGNOSTIC SCRIPT FOR FISCAL DAY SIGNATURE ISSUE
 * 
 * This script will help identify the DEFINITIVE cause of BadCertificateSignature errors
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = isset($argv[1]) ? intval($argv[1]) : 30199;

echo "================================================================================\n";
echo "FISCAL DAY SIGNATURE ISSUE - COMPREHENSIVE DIAGNOSTIC\n";
echo "================================================================================\n";
echo "Device ID: $deviceId\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $primaryDb = Database::getPrimaryInstance();
    $device = $primaryDb->getRow(
        "SELECT branch_id FROM fiscal_devices WHERE device_id = :device_id AND is_active = 1",
        [':device_id' => $deviceId]
    );
    
    if (!$device) {
        die("ERROR: Device $deviceId not found\n");
    }
    
    $branchId = $device['branch_id'];
    $fiscalService = new FiscalService($branchId, $deviceId);
    
    // ===========================================================================
    // STEP 1: GET FISCAL DAY STATUS
    // ===========================================================================
    echo "STEP 1: Getting fiscal day status from ZIMRA...\n";
    echo "--------------------------------------------------------------------------------\n";
    $status = $fiscalService->getFiscalDayStatus();
    echo "Status: " . ($status['fiscalDayStatus'] ?? 'Unknown') . "\n";
    echo "Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
    echo "Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n";
    
    if (isset($status['fiscalDayClosingErrorCode'])) {
        echo "ERROR CODE: " . $status['fiscalDayClosingErrorCode'] . "\n";
    }
    echo "\n";
    
    $fiscalDayNo = $status['lastFiscalDayNo'] ?? null;
    if (!$fiscalDayNo) {
        die("ERROR: No fiscal day number available\n");
    }
    
    // ===========================================================================
    // STEP 2: GET FISCAL DAY FROM DATABASE
    // ===========================================================================
    echo "STEP 2: Getting fiscal day record from database...\n";
    echo "--------------------------------------------------------------------------------\n";
    $db = Database::getInstance();
    $fiscalDay = $db->getRow(
        "SELECT * FROM fiscal_days WHERE device_id = :device_id AND fiscal_day_no = :fiscal_day_no ORDER BY id DESC LIMIT 1",
        [':device_id' => $deviceId, ':fiscal_day_no' => $fiscalDayNo]
    );
    
    if (!$fiscalDay) {
        die("ERROR: Fiscal day record not found in database\n");
    }
    
    echo "Fiscal Day ID: " . $fiscalDay['id'] . "\n";
    echo "Fiscal Day No: " . $fiscalDay['fiscal_day_no'] . "\n";
    echo "Opened: " . $fiscalDay['fiscal_day_opened'] . "\n\n";
    
    // ===========================================================================
    // STEP 3: GET RECEIPTS FOR THIS FISCAL DAY
    // ===========================================================================
    echo "STEP 3: Getting receipts for this fiscal day...\n";
    echo "--------------------------------------------------------------------------------\n";
    $receipts = $db->getRows(
        "SELECT * FROM fiscal_receipts 
         WHERE device_id = :device_id AND fiscal_day_no = :fiscal_day_no
         AND submission_status = 'Submitted'
         ORDER BY receipt_counter ASC",
        [':device_id' => $deviceId, ':fiscal_day_no' => $fiscalDayNo]
    );
    
    echo "Total receipts: " . count($receipts) . "\n";
    
    if (empty($receipts)) {
        die("ERROR: No receipts found for this fiscal day\n");
    }
    
    // Analyze receipts by type and tax
    $receiptsByType = [];
    $taxesSeen = [];
    
    foreach ($receipts as $receipt) {
        $type = $receipt['receipt_type'] ?? 'Unknown';
        if (!isset($receiptsByType[$type])) {
            $receiptsByType[$type] = 0;
        }
        $receiptsByType[$type]++;
        
        // Get taxes for this receipt
        $taxes = $db->getRows(
            "SELECT * FROM fiscal_receipt_taxes WHERE fiscal_receipt_id = :id",
            [':id' => $receipt['id']]
        );
        
        foreach ($taxes as $tax) {
            $taxKey = $tax['tax_id'] . '|' . ($tax['tax_percent'] ?? 'exempt');
            if (!isset($taxesSeen[$taxKey])) {
                $taxesSeen[$taxKey] = [
                    'taxID' => $tax['tax_id'],
                    'taxPercent' => $tax['tax_percent'],
                    'taxCode' => $tax['tax_code'] ?? null,
                    'count' => 0
                ];
            }
            $taxesSeen[$taxKey]['count']++;
        }
    }
    
    echo "\nReceipts by type:\n";
    foreach ($receiptsByType as $type => $count) {
        echo "  - $type: $count\n";
    }
    
    echo "\nTaxes seen in receipts:\n";
    foreach ($taxesSeen as $key => $taxInfo) {
        $percentStr = $taxInfo['taxPercent'] !== null ? $taxInfo['taxPercent'] . '%' : 'exempt';
        $codeStr = $taxInfo['taxCode'] ? " (code: " . $taxInfo['taxCode'] . ")" : '';
        echo "  - Tax ID " . $taxInfo['taxID'] . " @ $percentStr$codeStr: " . $taxInfo['count'] . " times\n";
    }
    echo "\n";
    
    // ===========================================================================
    // STEP 4: CALCULATE COUNTERS (USE REFLECTION TO ACCESS PRIVATE METHOD)
    // ===========================================================================
    echo "STEP 4: Calculating fiscal day counters...\n";
    echo "--------------------------------------------------------------------------------\n";
    $reflection = new ReflectionClass($fiscalService);
    $calculateMethod = $reflection->getMethod('calculateFiscalDayCounters');
    $calculateMethod->setAccessible(true);
    
    $counters = $calculateMethod->invoke($fiscalService, $fiscalDay['id']);
    
    echo "Total counters: " . count($counters) . "\n\n";
    
    echo "Counters (BEFORE SORTING IN SIGNATURE GENERATION):\n";
    foreach ($counters as $idx => $counter) {
        echo "Counter $idx:\n";
        echo "  Type: " . $counter['fiscalCounterType'] . "\n";
        echo "  Currency: " . $counter['fiscalCounterCurrency'] . "\n";
        if (isset($counter['fiscalCounterTaxID'])) {
            echo "  Tax ID: " . ($counter['fiscalCounterTaxID'] ?? 'NULL') . "\n";
        }
        if (isset($counter['fiscalCounterTaxPercent'])) {
            echo "  Tax Percent: " . $counter['fiscalCounterTaxPercent'] . "\n";
        } elseif (strpos($counter['fiscalCounterType'], 'ByTax') !== false) {
            echo "  Tax Percent: NULL (EXEMPT)\n";
        }
        if (isset($counter['fiscalCounterMoneyType'])) {
            echo "  Money Type: " . $counter['fiscalCounterMoneyType'] . "\n";
        }
        echo "  Value: " . $counter['fiscalCounterValue'] . "\n";
        echo "\n";
    }
    
    // ===========================================================================
    // STEP 5: GENERATE SIGNATURE STRING (WITHOUT SIGNING)
    // ===========================================================================
    echo "STEP 5: Building signature string (documenting exact format)...\n";
    echo "================================================================================\n";
    
    $fiscalDayData = [
        'deviceID' => $deviceId,
        'fiscalDayNo' => $fiscalDay['fiscal_day_no'],
        'fiscalDayOpened' => $fiscalDay['fiscal_day_opened'],
        'fiscalDayCounters' => $counters
    ];
    
    // Manually build to show exact process
    $parts = [];
    
    // Part 1: deviceID
    $parts[] = strval($fiscalDayData['deviceID']);
    echo "Part 1 (deviceID): " . $parts[0] . "\n";
    
    // Part 2: fiscalDayNo
    $parts[] = strval($fiscalDayData['fiscalDayNo']);
    echo "Part 2 (fiscalDayNo): " . $parts[1] . "\n";
    
    // Part 3: fiscalDayDate (YYYY-MM-DD)
    $date = new DateTime($fiscalDayData['fiscalDayOpened']);
    $parts[] = $date->format('Y-m-d');
    echo "Part 3 (fiscalDayDate): " . $parts[2] . "\n\n";
    
    // Part 4: fiscalDayCounters (this is complex - will be built by buildCountersString)
    echo "Part 4 (fiscalDayCounters) - Building counter strings...\n";
    echo "--------------------------------------------------------------------------------\n";
    
    // Call the actual method to get the real result
    $reflection = new ReflectionClass('ZimraSignature');
    $buildCountersMethod = $reflection->getMethod('buildCountersString');
    $buildCountersMethod->setAccessible(true);
    
    $countersString = $buildCountersMethod->invoke(null, $counters);
    $parts[] = $countersString;
    
    echo "Full counters string: $countersString\n";
    echo "Length: " . strlen($countersString) . " characters\n\n";
    
    // Full signature string
    $signatureString = implode('', $parts);
    echo "COMPLETE SIGNATURE STRING:\n";
    echo "--------------------------------------------------------------------------------\n";
    echo "$signatureString\n";
    echo "--------------------------------------------------------------------------------\n";
    echo "Length: " . strlen($signatureString) . " characters\n\n";
    
    // Generate hash
    $hash = hash('sha256', $signatureString, true);
    $hashBase64 = base64_encode($hash);
    
    echo "SHA-256 Hash (base64): $hashBase64\n\n";
    
    // ===========================================================================
    // STEP 6: CHECK CERTIFICATE
    // ===========================================================================
    echo "STEP 6: Checking certificate...\n";
    echo "================================================================================\n";
    
    $certData = CertificateStorage::loadCertificate($deviceId);
    
    if (!$certData || !$certData['certificate'] || !$certData['privateKey']) {
        die("ERROR: Certificate not found in storage\n");
    }
    
    $cert = openssl_x509_read($certData['certificate']);
    if ($cert) {
        $certDetails = openssl_x509_parse($cert);
        $certFingerprint = openssl_x509_fingerprint($cert, 'sha256', false);
        
        echo "Certificate Subject: " . ($certDetails['subject']['CN'] ?? 'N/A') . "\n";
        echo "Certificate Fingerprint (SHA256): $certFingerprint\n";
        echo "Valid From: " . date('Y-m-d H:i:s', $certDetails['validFrom_time_t']) . "\n";
        echo "Valid To: " . date('Y-m-d H:i:s', $certDetails['validTo_time_t']) . "\n";
        echo "Is Expired: " . (time() > $certDetails['validTo_time_t'] ? 'YES - EXPIRED!' : 'No') . "\n\n";
    }
    
    // Verify private key matches certificate
    $privateKey = openssl_pkey_get_private($certData['privateKey']);
    if (!$privateKey) {
        echo "ERROR: Private key is invalid or corrupted!\n";
        echo "OpenSSL Error: " . openssl_error_string() . "\n\n";
    } else {
        $certPubKey = openssl_pkey_get_public($certData['certificate']);
        $keyDetails = openssl_pkey_get_details($privateKey);
        $certKeyDetails = openssl_pkey_get_details($certPubKey);
        
        if ($keyDetails && $certKeyDetails && isset($keyDetails['key']) && isset($certKeyDetails['key'])) {
            if ($keyDetails['key'] === $certKeyDetails['key']) {
                echo "✓ Certificate and private key match correctly\n\n";
            } else {
                echo "✗ ERROR: Certificate and private key DO NOT MATCH!\n";
                echo "This is a CRITICAL issue - the certificate and key are mismatched!\n\n";
            }
        }
    }
    
    // ===========================================================================
    // STEP 7: TEST SIGNATURE GENERATION
    // ===========================================================================
    echo "STEP 7: Testing signature generation with private key...\n";
    echo "================================================================================\n";
    
    try {
        $deviceSignature = ZimraSignature::generateFiscalDayDeviceSignature(
            $fiscalDayData,
            $certData['privateKey']
        );
        
        echo "✓ Signature generated successfully\n";
        echo "Hash: " . $deviceSignature['hash'] . "\n";
        echo "Signature (first 100 chars): " . substr($deviceSignature['signature'], 0, 100) . "...\n";
        echo "Signature length: " . strlen($deviceSignature['signature']) . " characters\n\n";
    } catch (Exception $e) {
        echo "✗ ERROR: Failed to generate signature\n";
        echo "Error: " . $e->getMessage() . "\n\n";
    }
    
    // ===========================================================================
    // SUMMARY
    // ===========================================================================
    echo "================================================================================\n";
    echo "DIAGNOSTIC SUMMARY\n";
    echo "================================================================================\n";
    echo "Device: $deviceId\n";
    echo "Fiscal Day: $fiscalDayNo\n";
    echo "Receipts: " . count($receipts) . "\n";
    echo "Counters: " . count($counters) . "\n";
    echo "Signature String Length: " . strlen($signatureString) . "\n";
    echo "Hash: $hashBase64\n";
    echo "\n";
    echo "NEXT STEPS:\n";
    echo "1. Compare this signature string with ZIMRA documentation examples\n";
    echo "2. Verify counter sorting order matches documentation Section 13.3.1\n";
    echo "3. Check if certificate is the same one used for successful receipt submissions\n";
    echo "4. Review logs/error.log for detailed counter building information\n";
    echo "================================================================================\n";
    
} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

