<?php
/**
 * Diagnose Device 30199 Fiscal Day Opening Issue
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/database.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/fiscal_service.php';

echo "=== DEVICE 30199 DIAGNOSTIC ===\n\n";

$deviceId = 30199;
$db = Database::getPrimaryInstance();

// Step 1: Check device
echo "STEP 1: Device Configuration\n";
echo str_repeat("-", 60) . "\n";
$device = $db->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if (!$device) {
    die("✗ Device 30199 NOT FOUND!\n");
}

echo "✓ Device found\n";
echo "  Branch ID: " . $device['branch_id'] . "\n";
echo "  Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
echo "  Active: " . ($device['is_active'] ? 'Yes' : 'No') . "\n";
echo "  Model: " . ($device['device_model_name'] ?? 'N/A') . "\n";

if (!$device['is_registered']) {
    die("\n✗ CRITICAL: Device is NOT registered! Must register first.\n");
}

// Step 2: Check certificate loading
echo "\nSTEP 2: Certificate Loading\n";
echo str_repeat("-", 60) . "\n";

$certData = CertificateStorage::loadCertificate($deviceId);
if ($certData) {
    echo "✓ Certificate loaded via CertificateStorage\n";
    echo "  Cert length: " . strlen($certData['certificate']) . " bytes\n";
    echo "  Key length: " . strlen($certData['privateKey']) . " bytes\n";
} else {
    echo "✗ CertificateStorage::loadCertificate() returned NULL\n";
    echo "  Checking why...\n";
    
    // Check the query that CertificateStorage uses
    $check = $db->getRow(
        "SELECT certificate_pem, private_key_pem, certificate_valid_till, is_registered 
         FROM fiscal_devices 
         WHERE device_id = :device_id AND is_registered = 1",
        [':device_id' => $deviceId]
    );
    
    if (!$check) {
        echo "  ✗ Query returns no rows (is_registered check failed?)\n";
    } elseif (!$check['certificate_pem'] || !$check['private_key_pem']) {
        echo "  ✗ Certificate or key is NULL in database\n";
    } else {
        echo "  ⚠ Query works but CertificateStorage still returns null - BUG!\n";
    }
    
    // Try fallback
    if ($device['certificate_pem'] && $device['private_key_pem']) {
        echo "  → Using fallback from device record\n";
        $certData = [
            'certificate' => $device['certificate_pem'],
            'privateKey' => $device['private_key_pem']
        ];
    } else {
        die("✗ No certificate found anywhere!\n");
    }
}

// Step 3: Test FiscalService
echo "\nSTEP 3: Testing FiscalService\n";
echo str_repeat("-", 60) . "\n";

try {
    $fiscalService = new FiscalService($device['branch_id']);
    echo "✓ FiscalService initialized\n";
    
    echo "\nCalling getFiscalDayStatus()...\n";
    $status = $fiscalService->getFiscalDayStatus();
    
    if ($status === null) {
        echo "✗✗✗ getFiscalDayStatus() returned NULL ✗✗✗\n";
        echo "This is the EXACT problem causing the error!\n";
        echo "\nChecking error logs...\n";
        echo "Look for: 'FISCAL DAY STATUS ERROR' in PHP error log\n";
    } elseif (!isset($status['fiscalDayStatus'])) {
        echo "✗✗✗ Response missing 'fiscalDayStatus' field ✗✗✗\n";
        echo "Response: " . json_encode($status, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "✓✓✓ SUCCESS! ✓✓✓\n";
        echo "Fiscal Day Status: " . $status['fiscalDayStatus'] . "\n";
        if (isset($status['lastFiscalDayNo'])) {
            echo "Last Fiscal Day No: " . $status['lastFiscalDayNo'] . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "✗✗✗ FiscalService Error ✗✗✗\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Class: " . get_class($e) . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}

// Step 4: Direct API test
echo "\nSTEP 4: Direct API Test (Bypassing FiscalService)\n";
echo str_repeat("-", 60) . "\n";

try {
    $api = new ZimraApi(
        $device['device_model_name'] ?? 'Server',
        $device['device_model_version'] ?? 'v1',
        true
    );
    
    if ($certData) {
        $api->setCertificate($certData['certificate'], $certData['privateKey']);
        echo "✓ API client initialized with certificate\n";
    } else {
        die("✗ No certificate to test with\n");
    }
    
    echo "\nCalling getStatus() directly...\n";
    $status = $api->getStatus($deviceId);
    
    echo "✓✓✓ Direct API call SUCCESS! ✓✓✓\n";
    echo "Response:\n" . json_encode($status, JSON_PRETTY_PRINT) . "\n";
    
    if (isset($status['fiscalDayStatus'])) {
        echo "\n✓ Fiscal Day Status: " . $status['fiscalDayStatus'] . "\n";
    } else {
        echo "\n⚠ Response missing 'fiscalDayStatus' field\n";
    }
    
} catch (Exception $e) {
    echo "✗✗✗ Direct API call FAILED! ✗✗✗\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Class: " . get_class($e) . "\n";
    
    // Analyze error
    $errorMsg = $e->getMessage();
    echo "\n=== ERROR ANALYSIS ===\n";
    
    if (strpos($errorMsg, '401') !== false || strpos($errorMsg, 'Unauthorized') !== false) {
        echo "DIAGNOSIS: Certificate Authentication Failed\n";
        echo "  - Certificate may be invalid or revoked\n";
        echo "  - Certificate CN may not match device ID\n";
    } elseif (strpos($errorMsg, '404') !== false) {
        echo "DIAGNOSIS: Device Not Found\n";
        echo "  - Device not registered with ZIMRA\n";
    } elseif (strpos($errorMsg, 'Failed to connect') !== false) {
        echo "DIAGNOSIS: Network Issue\n";
        echo "  - Cannot reach ZIMRA API\n";
    } else {
        echo "DIAGNOSIS: " . $errorMsg . "\n";
    }
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";

