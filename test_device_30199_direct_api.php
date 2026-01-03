<?php
/**
 * Direct API Test for Device 30199
 * Bypasses FiscalService to test API directly
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/database.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';

echo "=== DIRECT API TEST FOR DEVICE 30199 ===\n\n";

$deviceId = 30199;
$db = Database::getPrimaryInstance();

// Get device info
$device = $db->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if (!$device) {
    die("Device 30199 not found!\n");
}

echo "Device Info:\n";
echo "  Device ID: " . $device['device_id'] . "\n";
echo "  Branch ID: " . $device['branch_id'] . "\n";
echo "  Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
echo "  Active: " . ($device['is_active'] ? 'Yes' : 'No') . "\n";
echo "  Model: " . ($device['device_model_name'] ?? 'N/A') . "\n";
echo "  Version: " . ($device['device_model_version'] ?? 'N/A') . "\n\n";

// Initialize API
$api = new ZimraApi(
    $device['device_model_name'] ?? 'Server',
    $device['device_model_version'] ?? 'v1',
    true // test environment
);

echo "API Client initialized\n";

// Try to load certificate
echo "\nLoading certificate...\n";
$certData = CertificateStorage::loadCertificate($deviceId);

if (!$certData) {
    echo "✗ CertificateStorage::loadCertificate() returned null\n";
    echo "Trying fallback from device record...\n";
    
    if ($device['certificate_pem'] && $device['private_key_pem']) {
        $certData = [
            'certificate' => $device['certificate_pem'],
            'privateKey' => $device['private_key_pem']
        ];
        echo "✓ Using certificate from device record\n";
    } else {
        die("✗ No certificate found anywhere!\n");
    }
} else {
    echo "✓ Certificate loaded via CertificateStorage\n";
}

// Decrypt private key if needed
if (strpos($certData['privateKey'], '-----BEGIN') === false) {
    echo "Private key appears encrypted, attempting decryption...\n";
    try {
        $certData['privateKey'] = CertificateStorage::decryptPrivateKey($certData['privateKey']);
        echo "✓ Private key decrypted\n";
    } catch (Exception $e) {
        echo "⚠ Decryption failed, using as-is: " . $e->getMessage() . "\n";
    }
}

// Set certificate
echo "\nSetting certificate in API client...\n";
$api->setCertificate($certData['certificate'], $certData['privateKey']);

if (!$api->hasCertificate()) {
    die("✗ Certificate not set in API client!\n");
}
echo "✓ Certificate set in API client\n";

// Test getStatus
echo "\n" . str_repeat("=", 60) . "\n";
echo "CALLING getStatus() API...\n";
echo str_repeat("=", 60) . "\n\n";

try {
    $startTime = microtime(true);
    $status = $api->getStatus($deviceId);
    $endTime = microtime(true);
    $duration = round(($endTime - $startTime) * 1000, 2);
    
    echo "✓✓✓ SUCCESS! ✓✓✓\n";
    echo "Response time: {$duration}ms\n\n";
    echo "Response:\n";
    echo json_encode($status, JSON_PRETTY_PRINT) . "\n\n";
    
    if (isset($status['fiscalDayStatus'])) {
        echo "✓ Fiscal Day Status: " . $status['fiscalDayStatus'] . "\n";
        if (isset($status['lastFiscalDayNo'])) {
            echo "✓ Last Fiscal Day No: " . $status['lastFiscalDayNo'] . "\n";
        }
        echo "\n✓✓✓ API is working correctly! ✓✓✓\n";
    } else {
        echo "⚠ WARNING: Response missing 'fiscalDayStatus' field\n";
        echo "This is why the error occurs!\n";
    }
    
} catch (Exception $e) {
    echo "✗✗✗ FAILED! ✗✗✗\n\n";
    echo "Error Type: " . get_class($e) . "\n";
    echo "Error Message: " . $e->getMessage() . "\n\n";
    
    // Detailed error analysis
    $errorMsg = $e->getMessage();
    echo "=== ERROR ANALYSIS ===\n";
    
    if (strpos($errorMsg, '401') !== false || strpos($errorMsg, 'Unauthorized') !== false) {
        echo "DIAGNOSIS: Certificate Authentication Failed (401)\n";
        echo "  - Certificate may be expired (but we checked - it's valid until 2026-12-29)\n";
        echo "  - Certificate may be revoked\n";
        echo "  - Certificate CN may not match device ID\n";
        echo "  - Certificate may not be properly formatted\n";
    } elseif (strpos($errorMsg, '404') !== false || strpos($errorMsg, 'Not Found') !== false) {
        echo "DIAGNOSIS: Device Not Found (404)\n";
        echo "  - Device may not be registered with ZIMRA\n";
        echo "  - Device ID may be incorrect\n";
    } elseif (strpos($errorMsg, 'Failed to connect') !== false || strpos($errorMsg, 'CURL Error') !== false) {
        echo "DIAGNOSIS: Network Connectivity Issue\n";
        echo "  - Cannot reach ZIMRA API server\n";
        echo "  - Firewall blocking connection\n";
        echo "  - DNS resolution failure\n";
        echo "  - SSL/TLS handshake failure\n";
    } elseif (strpos($errorMsg, 'timeout') !== false) {
        echo "DIAGNOSIS: Connection Timeout\n";
        echo "  - Network latency too high\n";
        echo "  - ZIMRA API server slow or down\n";
    } else {
        echo "DIAGNOSIS: Unknown error - see details above\n";
    }
    
    echo "\n=== FULL ERROR TRACE ===\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";

