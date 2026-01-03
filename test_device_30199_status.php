<?php
/**
 * Test Device 30199 Fiscal Day Status
 * Diagnostic script to identify why fiscal day opening fails
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/database.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';

echo "=== DEVICE 30199 DIAGNOSTIC TEST ===\n\n";

$deviceId = 30199;
$db = Database::getPrimaryInstance();

// Step 1: Check device configuration
echo "STEP 1: Checking Device Configuration\n";
echo str_repeat("-", 50) . "\n";
$device = $db->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if (!$device) {
    echo "✗ Device 30199 NOT FOUND in database!\n";
    exit(1);
}

echo "✓ Device found in database\n";
echo "  Branch ID: " . $device['branch_id'] . "\n";
echo "  Serial No: " . ($device['device_serial_no'] ?? 'N/A') . "\n";
echo "  Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
echo "  Active: " . ($device['is_active'] ? 'Yes' : 'No') . "\n";
echo "  Model: " . ($device['device_model_name'] ?? 'N/A') . " v" . ($device['device_model_version'] ?? 'N/A') . "\n";

if (!$device['is_registered']) {
    echo "\n⚠ WARNING: Device is NOT registered! This will cause API calls to fail.\n";
}

if (!$device['is_active']) {
    echo "\n⚠ WARNING: Device is NOT active!\n";
}

// Step 2: Check certificate storage
echo "\nSTEP 2: Checking Certificate Storage\n";
echo str_repeat("-", 50) . "\n";
$certData = CertificateStorage::loadCertificate($deviceId);

if ($certData) {
    echo "✓ Certificate found via CertificateStorage::loadCertificate()\n";
    echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
    echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n";
    if (isset($certData['validTill']) && $certData['validTill']) {
        echo "  Valid till: " . $certData['validTill'] . "\n";
        $expiry = strtotime($certData['validTill']);
        $now = time();
        if ($expiry < $now) {
            echo "  ⚠ WARNING: Certificate is EXPIRED! (Expired " . round(($now - $expiry) / 86400) . " days ago)\n";
        } else {
            $daysLeft = round(($expiry - $now) / 86400);
            echo "  ✓ Certificate is valid (expires in $daysLeft days)\n";
        }
    } else {
        echo "  ⚠ Certificate expiry date not set\n";
    }
    
    // Verify certificate format
    if (strpos($certData['certificate'], '-----BEGIN CERTIFICATE-----') === false) {
        echo "  ⚠ WARNING: Certificate format may be invalid (missing PEM header)\n";
    }
    if (strpos($certData['privateKey'], '-----BEGIN') === false) {
        echo "  ⚠ WARNING: Private key format may be invalid (missing PEM header)\n";
    }
} else {
    echo "✗ Certificate NOT found via CertificateStorage::loadCertificate()\n";
    
    // Check fallback in device record
    if ($device['certificate_pem'] && $device['private_key_pem']) {
        echo "  → Found certificate in device record (fallback)\n";
        echo "  Certificate length: " . strlen($device['certificate_pem']) . " bytes\n";
        echo "  Private key length: " . strlen($device['private_key_pem']) . " bytes\n";
        echo "  ⚠ NOTE: CertificateStorage didn't load it - checking why...\n";
        
        // Check if is_registered is set
        if (!$device['is_registered']) {
            echo "  ✗ Device is_registered = 0, CertificateStorage requires is_registered = 1\n";
        }
    } else {
        echo "  ✗ No certificate found in device record either!\n";
        echo "\n⚠ CRITICAL: No certificate available. Device must be registered first.\n";
    }
}

// Step 3: Initialize API client
echo "\nSTEP 3: Initializing ZIMRA API Client\n";
echo str_repeat("-", 50) . "\n";
try {
    $api = new ZimraApi(
        $device['device_model_name'] ?? 'Server',
        $device['device_model_version'] ?? 'v1',
        true // Use test environment
    );
    echo "✓ API client initialized\n";
    
    // Load certificate
    if ($certData) {
        $api->setCertificate($certData['certificate'], $certData['privateKey']);
        echo "✓ Certificate loaded from CertificateStorage\n";
    } elseif ($device['certificate_pem'] && $device['private_key_pem']) {
        // Try to decrypt if needed
        $privateKey = $device['private_key_pem'];
        if (strpos($privateKey, '-----BEGIN') === false) {
            try {
                $privateKey = CertificateStorage::decryptPrivateKey($privateKey);
                echo "✓ Private key decrypted\n";
            } catch (Exception $e) {
                echo "⚠ Could not decrypt private key, using as-is\n";
            }
        }
        $api->setCertificate($device['certificate_pem'], $privateKey);
        echo "✓ Certificate loaded from device record\n";
    } else {
        echo "✗ Cannot load certificate - API calls will fail\n";
        exit(1);
    }
    
    if (!$api->hasCertificate()) {
        echo "✗ Certificate not properly set in API client\n";
        exit(1);
    }
    echo "✓ Certificate verified in API client\n";
    
} catch (Exception $e) {
    echo "✗ Error initializing API: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 4: Test API connectivity
echo "\nSTEP 4: Testing API Connectivity\n";
echo str_repeat("-", 50) . "\n";
try {
    // Test with getStatus
    echo "Attempting to call getStatus()...\n";
    $status = $api->getStatus($deviceId);
    
    echo "✓✓✓ API CALL SUCCESSFUL! ✓✓✓\n";
    echo "\nResponse:\n";
    echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
    
    if (isset($status['fiscalDayStatus'])) {
        echo "\n✓ Fiscal Day Status: " . $status['fiscalDayStatus'] . "\n";
        if (isset($status['lastFiscalDayNo'])) {
            echo "✓ Last Fiscal Day No: " . $status['lastFiscalDayNo'] . "\n";
        }
    } else {
        echo "\n⚠ WARNING: Response does not contain 'fiscalDayStatus' field!\n";
        echo "This is why the error occurs - response format is unexpected.\n";
    }
    
} catch (Exception $e) {
    echo "✗✗✗ API CALL FAILED! ✗✗✗\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Error class: " . get_class($e) . "\n";
    
    // Check error type
    $errorMsg = $e->getMessage();
    if (strpos($errorMsg, '401') !== false || strpos($errorMsg, 'Unauthorized') !== false) {
        echo "\n⚠ DIAGNOSIS: Certificate authentication failed (401 Unauthorized)\n";
        echo "  Possible causes:\n";
        echo "  - Certificate expired\n";
        echo "  - Certificate revoked\n";
        echo "  - Certificate doesn't match device ID\n";
        echo "  - Certificate CN mismatch\n";
    } elseif (strpos($errorMsg, '404') !== false || strpos($errorMsg, 'Not Found') !== false) {
        echo "\n⚠ DIAGNOSIS: Device not found (404 Not Found)\n";
        echo "  Possible causes:\n";
        echo "  - Device not registered with ZIMRA\n";
        echo "  - Device ID incorrect\n";
    } elseif (strpos($errorMsg, 'Failed to connect') !== false || strpos($errorMsg, 'CURL Error') !== false) {
        echo "\n⚠ DIAGNOSIS: Network connectivity issue\n";
        echo "  Possible causes:\n";
        echo "  - Cannot reach ZIMRA API server\n";
        echo "  - Firewall blocking connection\n";
        echo "  - DNS resolution failure\n";
        echo "  - SSL/TLS handshake failure\n";
    } elseif (strpos($errorMsg, 'timeout') !== false) {
        echo "\n⚠ DIAGNOSIS: Connection timeout\n";
        echo "  Possible causes:\n";
        echo "  - Network latency too high\n";
        echo "  - ZIMRA API server slow or down\n";
    }
    
    echo "\nFull error trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

// Step 5: Check recent fiscal days
echo "\nSTEP 5: Checking Recent Fiscal Days\n";
echo str_repeat("-", 50) . "\n";
$fiscalDays = $db->getRows(
    "SELECT * FROM fiscal_days WHERE device_id = :device_id ORDER BY id DESC LIMIT 5",
    [':device_id' => $deviceId]
);

if ($fiscalDays) {
    echo "Found " . count($fiscalDays) . " recent fiscal day(s):\n";
    foreach ($fiscalDays as $day) {
        echo "  Day No: " . $day['fiscal_day_no'] . ", Status: " . $day['status'] . ", Opened: " . $day['fiscal_day_opened'] . "\n";
    }
} else {
    echo "No fiscal days found in database\n";
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";

