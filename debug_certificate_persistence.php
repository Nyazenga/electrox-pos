<?php
/**
 * Debug Certificate Persistence
 * Thoroughly test certificate save and load operations
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "CERTIFICATE PERSISTENCE DEBUG\n";
echo "========================================\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$primaryDb = Database::getPrimaryInstance();

// Step 1: Check if device exists
echo "Step 1: Checking device record...\n";
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if (!$device) {
    echo "✗ Device record not found. Creating...\n";
    $branch = $primaryDb->getRow("SELECT id FROM branches WHERE branch_code = 'HO' OR branch_name LIKE '%Head Office%' LIMIT 1");
    if (!$branch) {
        // Try to get any branch
        $branch = $primaryDb->getRow("SELECT id FROM branches LIMIT 1");
        if (!$branch) {
            die("✗ No branches found. Cannot create device record.\n");
        }
        echo "⚠ Using first available branch (ID: " . $branch['id'] . ")\n";
    }
    
    // Check if device already exists by device_id
    $existingDevice = $primaryDb->getRow("SELECT * FROM fiscal_devices WHERE device_id = :device_id", [':device_id' => $deviceId]);
    if ($existingDevice) {
        $device = $existingDevice;
        echo "✓ Device record found (ID: " . $device['id'] . ")\n";
    } else {
        $deviceId_db = $primaryDb->insert('fiscal_devices', [
            'branch_id' => $branch['id'],
            'device_id' => $deviceId,
            'device_serial_no' => $deviceSerialNo,
            'activation_key' => $activationKey,
            'device_model_name' => 'Server',
            'device_model_version' => 'v1',
            'is_active' => 1
        ]);
        
        if ($deviceId_db) {
            $device = $primaryDb->getRow("SELECT * FROM fiscal_devices WHERE id = :id", [':id' => $deviceId_db]);
            echo "✓ Device record created (ID: $deviceId_db)\n";
        } else {
            // Check for error
            $error = $primaryDb->getLastError();
            echo "⚠ Insert returned false. Error: " . ($error ?: 'Unknown') . "\n";
            // Try to find it by device_id anyway
            $device = $primaryDb->getRow("SELECT * FROM fiscal_devices WHERE device_id = :device_id", [':device_id' => $deviceId]);
            if ($device) {
                echo "✓ Device record found after insert attempt (ID: " . $device['id'] . ")\n";
            } else {
                die("✗ Failed to create or find device record. Last error: " . ($error ?: 'Unknown') . "\n");
            }
        }
    }
} else {
    echo "✓ Device record found (ID: " . $device['id'] . ")\n";
    echo "  Branch ID: " . $device['branch_id'] . "\n";
    echo "  Is Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
    echo "  Has Certificate: " . ($device['certificate_pem'] ? 'Yes (' . strlen($device['certificate_pem']) . ' bytes)' : 'No') . "\n";
    echo "  Has Private Key: " . ($device['private_key_pem'] ? 'Yes (' . strlen($device['private_key_pem']) . ' bytes)' : 'No') . "\n";
}
echo "\n";

// Step 2: Check current certificate status
echo "Step 2: Checking certificate status...\n";
$certData = CertificateStorage::loadCertificate($deviceId);
if ($certData) {
    echo "✓ Certificate loaded successfully\n";
    echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
    echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n";
    echo "  Valid till: " . ($certData['validTill'] ?? 'N/A') . "\n";
    
    // Check if it's encrypted
    $rawKey = $device['private_key_pem'] ?? '';
    $isEncrypted = (strlen($rawKey) > 2000 && base64_decode($rawKey, true) !== false);
    echo "  Private key encrypted: " . ($isEncrypted ? 'Yes' : 'No (plain text)') . "\n";
} else {
    echo "✗ No certificate found in database\n";
}
echo "\n";

// Step 3: Register device if not registered
if (!$certData) {
    echo "Step 3: Registering device...\n";
    try {
        $api = new ZimraApi('Server', 'v1', true);
        $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
        echo "✓ CSR generated\n";
        
        $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
        if (isset($result['certificate'])) {
            echo "✓ Certificate received from ZIMRA\n";
            echo "  Certificate length: " . strlen($result['certificate']) . " bytes\n";
            echo "  Private key length: " . strlen($csrData['privateKey']) . " bytes\n";
            
            // Save using CertificateStorage
            echo "\nSaving certificate to database...\n";
            CertificateStorage::saveCertificate(
                $deviceId,
                $result['certificate'],
                $csrData['privateKey']
            );
            echo "✓ Certificate saved\n";
            
            // Verify it was saved
            $savedDevice = $primaryDb->getRow(
                "SELECT certificate_pem, private_key_pem FROM fiscal_devices WHERE device_id = :device_id",
                [':device_id' => $deviceId]
            );
            
            if ($savedDevice && $savedDevice['certificate_pem']) {
                echo "✓ Certificate verified in database\n";
                echo "  Saved certificate length: " . strlen($savedDevice['certificate_pem']) . " bytes\n";
                echo "  Saved private key length: " . strlen($savedDevice['private_key_pem']) . " bytes\n";
                
                // Check if private key is encrypted
                $isEncrypted = (strlen($savedDevice['private_key_pem']) > 2000 && base64_decode($savedDevice['private_key_pem'], true) !== false);
                echo "  Private key encrypted: " . ($isEncrypted ? 'Yes' : 'No') . "\n";
            } else {
                echo "✗ Certificate not found in database after save!\n";
            }
        }
    } catch (Exception $e) {
        echo "✗ Registration failed: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// Step 4: Test loading certificate in "new session" (reload from DB)
echo "Step 4: Testing certificate load in new session...\n";
$certData2 = CertificateStorage::loadCertificate($deviceId);
if ($certData2) {
    echo "✓ Certificate loaded successfully\n";
    echo "  Certificate length: " . strlen($certData2['certificate']) . " bytes\n";
    echo "  Private key length: " . strlen($certData2['privateKey']) . " bytes\n";
    
    // Verify certificate format
    $certValid = (strpos($certData2['certificate'], '-----BEGIN CERTIFICATE-----') !== false);
    $keyValid = (strpos($certData2['privateKey'], '-----BEGIN') !== false);
    echo "  Certificate format valid: " . ($certValid ? 'Yes' : 'No') . "\n";
    echo "  Private key format valid: " . ($keyValid ? 'Yes' : 'No') . "\n";
    
    // Test API with loaded certificate
    echo "\nTesting API with loaded certificate...\n";
    $api2 = new ZimraApi('Server', 'v1', true);
    $api2->setCertificate($certData2['certificate'], $certData2['privateKey']);
    
    try {
        $status = $api2->getStatus($deviceId);
        echo "✓ getStatus SUCCESS!\n";
        echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "✗ getStatus FAILED: " . $e->getMessage() . "\n";
    }
    
    try {
        $config = $api2->getConfig($deviceId);
        echo "✓ getConfig SUCCESS!\n";
        echo "  Operating Mode: " . ($config['deviceOperatingMode'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "✗ getConfig FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "✗ Failed to load certificate\n";
}

echo "\n========================================\n";
echo "DEBUG COMPLETE\n";
echo "========================================\n";

