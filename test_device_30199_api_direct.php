<?php
/**
 * Direct API Test for Device 30199
 * Captures exact error messages to diagnose fiscal day opening issue
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Set time limit for long-running requests
set_time_limit(60);

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/database.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/fiscal_service.php';

echo "========================================\n";
echo "DEVICE 30199 DIRECT API TEST\n";
echo "========================================\n\n";

$deviceId = 30199;
$db = Database::getPrimaryInstance();

// ============================================
// STEP 1: Device Configuration Check
// ============================================
echo "STEP 1: Device Configuration\n";
echo str_repeat("-", 50) . "\n";

$device = $db->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if (!$device) {
    die("✗ FATAL: Device 30199 NOT FOUND in database!\n");
}

echo "✓ Device found\n";
echo "  Device ID: " . $device['device_id'] . "\n";
echo "  Branch ID: " . $device['branch_id'] . "\n";
echo "  Serial No: " . ($device['device_serial_no'] ?? 'N/A') . "\n";
echo "  Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
echo "  Active: " . ($device['is_active'] ? 'Yes' : 'No') . "\n";
echo "  Model: " . ($device['device_model_name'] ?? 'N/A') . "\n";
echo "  Version: " . ($device['device_model_version'] ?? 'N/A') . "\n";

if (!$device['is_registered']) {
    die("\n✗ FATAL: Device is NOT registered! Cannot proceed.\n");
}

// ============================================
// STEP 2: Certificate Loading
// ============================================
echo "\nSTEP 2: Certificate Loading\n";
echo str_repeat("-", 50) . "\n";

$certData = null;
$certSource = '';

// Try CertificateStorage first
$certData = CertificateStorage::loadCertificate($deviceId);
if ($certData) {
    $certSource = 'CertificateStorage';
    echo "✓ Certificate loaded via CertificateStorage::loadCertificate()\n";
    echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
    echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n";
    
    // Check certificate format
    if (strpos($certData['certificate'], '-----BEGIN CERTIFICATE-----') === false) {
        echo "  ⚠ WARNING: Certificate missing PEM header!\n";
    }
    if (strpos($certData['privateKey'], '-----BEGIN') === false) {
        echo "  ⚠ WARNING: Private key missing PEM header!\n";
    }
    
    if (isset($certData['validTill']) && $certData['validTill']) {
        $expiry = strtotime($certData['validTill']);
        $now = time();
        if ($expiry < $now) {
            echo "  ⚠ WARNING: Certificate EXPIRED! (" . date('Y-m-d H:i:s', $expiry) . ")\n";
        } else {
            $daysLeft = round(($expiry - $now) / 86400);
            echo "  ✓ Certificate valid until: " . $certData['validTill'] . " ($daysLeft days remaining)\n";
        }
    }
} else {
    echo "✗ CertificateStorage::loadCertificate() returned NULL\n";
    echo "  Checking why...\n";
    
    // Debug: Check the exact query CertificateStorage uses
    $check = $db->getRow(
        "SELECT certificate_pem, private_key_pem, certificate_valid_till, is_registered 
         FROM fiscal_devices 
         WHERE device_id = :device_id AND is_registered = 1",
        [':device_id' => $deviceId]
    );
    
    if (!$check) {
        echo "  ✗ Query with is_registered=1 returns no rows\n";
        echo "  → Checking without is_registered filter...\n";
        $check2 = $db->getRow(
            "SELECT certificate_pem, private_key_pem, is_registered 
             FROM fiscal_devices 
             WHERE device_id = :device_id",
            [':device_id' => $deviceId]
        );
        if ($check2) {
            echo "  → Device found but is_registered = " . ($check2['is_registered'] ? '1' : '0') . "\n";
        }
    } elseif (!$check['certificate_pem'] || !$check['private_key_pem']) {
        echo "  ✗ Certificate or key is NULL in database\n";
    }
    
    // Try fallback from device record
    if ($device['certificate_pem'] && $device['private_key_pem']) {
        echo "  → Using fallback from device record\n";
        $certData = [
            'certificate' => $device['certificate_pem'],
            'privateKey' => $device['private_key_pem'],
            'validTill' => $device['certificate_valid_till'] ?? null
        ];
        $certSource = 'Device Record (Fallback)';
        echo "  ✓ Certificate loaded from device record\n";
        echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
        echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n";
        
        // Try to decrypt if needed
        if (strpos($certData['privateKey'], '-----BEGIN') === false) {
            echo "  → Private key appears encrypted, attempting decryption...\n";
            try {
                $certData['privateKey'] = CertificateStorage::decryptPrivateKey($certData['privateKey']);
                echo "  ✓ Private key decrypted successfully\n";
            } catch (Exception $e) {
                echo "  ⚠ Decryption failed: " . $e->getMessage() . "\n";
                echo "  → Using as-is (may be plain text)\n";
            }
        }
    } else {
        die("\n✗ FATAL: No certificate found anywhere! Cannot proceed.\n");
    }
}

// ============================================
// STEP 3: Initialize API Client
// ============================================
echo "\nSTEP 3: Initialize ZIMRA API Client\n";
echo str_repeat("-", 50) . "\n";

try {
    $api = new ZimraApi(
        $device['device_model_name'] ?? 'Server',
        $device['device_model_version'] ?? 'v1',
        true // Use test environment
    );
    echo "✓ API client created\n";
    echo "  Base URL: " . (defined('ZIMRA_API_TEST_URL') ? ZIMRA_API_TEST_URL : 'https://fdmsapitest.zimra.co.zw') . "\n";
    
    // Set certificate
    if ($certData) {
        $api->setCertificate($certData['certificate'], $certData['privateKey']);
        echo "✓ Certificate set in API client\n";
        echo "  Source: $certSource\n";
        
        if (!$api->hasCertificate()) {
            die("\n✗ FATAL: Certificate not properly set in API client!\n");
        }
        echo "✓ Certificate verified in API client\n";
    } else {
        die("\n✗ FATAL: No certificate to set!\n");
    }
    
} catch (Exception $e) {
    die("\n✗ FATAL: Error initializing API client: " . $e->getMessage() . "\n");
}

// ============================================
// STEP 4: Direct API Call Test
// ============================================
echo "\nSTEP 4: Direct API Call (getStatus)\n";
echo str_repeat("=", 50) . "\n";
echo "Calling: GET /Device/v1/{$deviceId}/GetStatus\n";
echo str_repeat("=", 50) . "\n\n";

$directApiSuccess = false;
$directApiError = null;
$directApiResponse = null;

try {
    echo "Making API call...\n";
    $startTime = microtime(true);
    
    $directApiResponse = $api->getStatus($deviceId);
    
    $endTime = microtime(true);
    $duration = round(($endTime - $startTime) * 1000, 2);
    
    echo "✓✓✓ DIRECT API CALL SUCCESS! ✓✓✓\n";
    echo "Response time: {$duration}ms\n\n";
    
    $directApiSuccess = true;
    
    echo "Response Data:\n";
    echo json_encode($directApiResponse, JSON_PRETTY_PRINT) . "\n\n";
    
    if (isset($directApiResponse['fiscalDayStatus'])) {
        echo "✓ Fiscal Day Status: " . $directApiResponse['fiscalDayStatus'] . "\n";
        if (isset($directApiResponse['lastFiscalDayNo'])) {
            echo "✓ Last Fiscal Day No: " . $directApiResponse['lastFiscalDayNo'] . "\n";
        }
        if (isset($directApiResponse['lastReceiptGlobalNo'])) {
            echo "✓ Last Receipt Global No: " . $directApiResponse['lastReceiptGlobalNo'] . "\n";
        }
    } else {
        echo "⚠ WARNING: Response missing 'fiscalDayStatus' field!\n";
        echo "This explains why the error occurs.\n";
        echo "Available fields: " . implode(', ', array_keys($directApiResponse)) . "\n";
    }
    
} catch (Exception $e) {
    $directApiSuccess = false;
    $directApiError = $e;
    
    echo "✗✗✗ DIRECT API CALL FAILED! ✗✗✗\n\n";
    echo "EXACT ERROR MESSAGE:\n";
    echo str_repeat("-", 50) . "\n";
    echo $e->getMessage() . "\n";
    echo str_repeat("-", 50) . "\n\n";
    
    echo "Error Type: " . get_class($e) . "\n";
    echo "Error Code: " . ($e->getCode() ?: 'N/A') . "\n\n";
    
    // Detailed error analysis
    $errorMsg = $e->getMessage();
    echo "=== ERROR ANALYSIS ===\n";
    
    if (strpos($errorMsg, '401') !== false || strpos($errorMsg, 'Unauthorized') !== false) {
        echo "DIAGNOSIS: Certificate Authentication Failed (401 Unauthorized)\n\n";
        echo "Possible causes:\n";
        echo "  1. Certificate expired (but we checked - valid until " . ($certData['validTill'] ?? 'N/A') . ")\n";
        echo "  2. Certificate revoked by ZIMRA\n";
        echo "  3. Certificate CN (Common Name) doesn't match device ID format\n";
        echo "  4. Certificate not properly issued by ZIMRA\n";
        echo "  5. Certificate format invalid\n\n";
        echo "SOLUTION: Re-issue certificate or verify with ZIMRA\n";
    } elseif (strpos($errorMsg, '404') !== false || strpos($errorMsg, 'Not Found') !== false) {
        echo "DIAGNOSIS: Device Not Found (404 Not Found)\n\n";
        echo "Possible causes:\n";
        echo "  1. Device not registered with ZIMRA\n";
        echo "  2. Device ID incorrect\n";
        echo "  3. Device removed from ZIMRA system\n\n";
        echo "SOLUTION: Register device with ZIMRA\n";
    } elseif (strpos($errorMsg, 'Failed to connect') !== false || strpos($errorMsg, 'CURL Error') !== false) {
        echo "DIAGNOSIS: Network Connectivity Issue\n\n";
        echo "Possible causes:\n";
        echo "  1. Cannot reach ZIMRA API server\n";
        echo "  2. Firewall blocking connection\n";
        echo "  3. DNS resolution failure\n";
        echo "  4. SSL/TLS handshake failure\n";
        echo "  5. Connection timeout\n\n";
        echo "SOLUTION: Check network connectivity, firewall, DNS\n";
    } elseif (strpos($errorMsg, 'timeout') !== false) {
        echo "DIAGNOSIS: Connection Timeout\n\n";
        echo "Possible causes:\n";
        echo "  1. Network latency too high\n";
        echo "  2. ZIMRA API server slow or down\n";
        echo "  3. Timeout setting too low\n\n";
        echo "SOLUTION: Check network, increase timeout, verify ZIMRA API status\n";
    } elseif (strpos($errorMsg, 'SSL') !== false || strpos($errorMsg, 'certificate') !== false) {
        echo "DIAGNOSIS: SSL/Certificate Error\n\n";
        echo "Possible causes:\n";
        echo "  1. Certificate format invalid\n";
        echo "  2. Certificate chain incomplete\n";
        echo "  3. SSL handshake failure\n\n";
        echo "SOLUTION: Verify certificate format, check certificate chain\n";
    } else {
        echo "DIAGNOSIS: Unknown error type\n";
        echo "Full error message above contains details\n";
    }
    
    echo "\n=== FULL ERROR TRACE ===\n";
    echo $e->getTraceAsString() . "\n";
}

// ============================================
// STEP 5: Test via FiscalService
// ============================================
echo "\n\nSTEP 5: Test via FiscalService\n";
echo str_repeat("=", 50) . "\n";

$fiscalServiceSuccess = false;
$fiscalServiceError = null;
$fiscalServiceResponse = null;

try {
    echo "Initializing FiscalService...\n";
    $fiscalService = new FiscalService($device['branch_id']);
    echo "✓ FiscalService initialized\n";
    
    echo "\nCalling getFiscalDayStatus()...\n";
    $fiscalServiceResponse = $fiscalService->getFiscalDayStatus();
    
    if ($fiscalServiceResponse === null) {
        echo "✗✗✗ getFiscalDayStatus() returned NULL ✗✗✗\n";
        echo "This is the EXACT problem causing the error!\n";
        echo "\nThe method caught an exception and returned null.\n";
        echo "Check PHP error logs for: 'FISCAL DAY STATUS ERROR'\n";
    } elseif (!isset($fiscalServiceResponse['fiscalDayStatus'])) {
        echo "✗✗✗ Response missing 'fiscalDayStatus' field ✗✗✗\n";
        echo "Response: " . json_encode($fiscalServiceResponse, JSON_PRETTY_PRINT) . "\n";
    } else {
        $fiscalServiceSuccess = true;
        echo "✓✓✓ FiscalService SUCCESS! ✓✓✓\n";
        echo "Fiscal Day Status: " . $fiscalServiceResponse['fiscalDayStatus'] . "\n";
        if (isset($fiscalServiceResponse['lastFiscalDayNo'])) {
            echo "Last Fiscal Day No: " . $fiscalServiceResponse['lastFiscalDayNo'] . "\n";
        }
    }
    
} catch (Exception $e) {
    $fiscalServiceError = $e;
    echo "✗✗✗ FiscalService Error ✗✗✗\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Class: " . get_class($e) . "\n";
}

// ============================================
// STEP 6: Summary & Recommendations
// ============================================
echo "\n\n";
echo str_repeat("=", 50) . "\n";
echo "SUMMARY & RECOMMENDATIONS\n";
echo str_repeat("=", 50) . "\n\n";

if ($directApiSuccess) {
    echo "✓ Direct API call: SUCCESS\n";
    if ($fiscalServiceSuccess) {
        echo "✓ FiscalService call: SUCCESS\n";
        echo "\n✓✓✓ ALL TESTS PASSED - API IS WORKING ✓✓✓\n";
        echo "\nIf you're still getting errors in the UI, check:\n";
        echo "  1. Error handling in modules/settings/fiscalization.php\n";
        echo "  2. Session/request handling\n";
        echo "  3. Browser console for JavaScript errors\n";
    } else {
        echo "✗ FiscalService call: FAILED\n";
        echo "\nISSUE: Direct API works but FiscalService fails\n";
        echo "This suggests an issue in FiscalService initialization or certificate loading.\n";
        echo "\nCheck:\n";
        echo "  1. FiscalService constructor certificate loading\n";
        echo "  2. CertificateStorage::loadCertificate() for device 30199\n";
        echo "  3. Error logs for FiscalService initialization errors\n";
    }
} else {
    echo "✗ Direct API call: FAILED\n";
    echo "\nROOT CAUSE IDENTIFIED:\n";
    echo "The API call itself is failing. See error details above.\n";
    echo "\nEXACT ERROR: " . ($directApiError ? $directApiError->getMessage() : 'Unknown') . "\n";
    echo "\nRECOMMENDED ACTIONS:\n";
    
    if ($directApiError) {
        $errorMsg = $directApiError->getMessage();
        if (strpos($errorMsg, '401') !== false || strpos($errorMsg, 'Unauthorized') !== false) {
            echo "  1. Contact ZIMRA to verify certificate status\n";
            echo "  2. Re-issue certificate if revoked\n";
            echo "  3. Verify certificate CN matches device ID format\n";
        } elseif (strpos($errorMsg, '404') !== false) {
            echo "  1. Verify device is registered with ZIMRA\n";
            echo "  2. Re-register device if needed\n";
        } elseif (strpos($errorMsg, 'Failed to connect') !== false) {
            echo "  1. Test network connectivity: curl -v https://fdmsapitest.zimra.co.zw\n";
            echo "  2. Check firewall rules\n";
            echo "  3. Verify DNS resolution\n";
        }
    }
    
    echo "  4. Check PHP error logs for additional details\n";
    echo "  5. Contact ZIMRA support with device ID and error message\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "TEST COMPLETE\n";
echo str_repeat("=", 50) . "\n";

