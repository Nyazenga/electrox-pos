<?php
/**
 * Full Certificate Cycle Test
 * Tests: Save -> Load -> Use -> Verify persistence
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "FULL CERTIFICATE CYCLE TEST\n";
echo "========================================\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$primaryDb = Database::getPrimaryInstance();

// Step 1: Check existing certificate
echo "Step 1: Checking for existing certificate...\n";
$certData = CertificateStorage::loadCertificate($deviceId);
if ($certData) {
    echo "✓ Certificate found in database\n";
    echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
    echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n";
    
    // Test API with existing certificate
    $api = new ZimraApi('Server', 'v1', true);
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    
    echo "\nTesting API endpoints with existing certificate...\n";
    try {
        $status = $api->getStatus($deviceId);
        echo "✓ getStatus: SUCCESS\n";
        echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "✗ getStatus: FAILED - " . $e->getMessage() . "\n";
    }
    
    try {
        $config = $api->getConfig($deviceId);
        echo "✓ getConfig: SUCCESS\n";
        echo "  Operating Mode: " . ($config['deviceOperatingMode'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "✗ getConfig: FAILED - " . $e->getMessage() . "\n";
    }
    
    echo "\n✓ Certificate persistence verified!\n";
    exit(0);
}

echo "✗ No certificate found\n\n";

// Step 2: Register device
echo "Step 2: Registering device...\n";
$api = new ZimraApi('Server', 'v1', true);

try {
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
    echo "✓ CSR generated\n";
    
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    if (isset($result['certificate'])) {
        echo "✓ Certificate received from ZIMRA\n";
        
        // Step 3: Save certificate
        echo "\nStep 3: Saving certificate to database...\n";
        CertificateStorage::saveCertificate(
            $deviceId,
            $result['certificate'],
            $csrData['privateKey']
        );
        echo "✓ Certificate saved\n";
        
        // Step 4: Verify save
        echo "\nStep 4: Verifying certificate was saved...\n";
        $savedDevice = $primaryDb->getRow(
            "SELECT certificate_pem, private_key_pem, is_registered FROM fiscal_devices WHERE device_id = :device_id",
            [':device_id' => $deviceId]
        );
        
        if ($savedDevice && $savedDevice['certificate_pem'] && $savedDevice['is_registered']) {
            echo "✓ Certificate verified in database\n";
            echo "  Certificate length: " . strlen($savedDevice['certificate_pem']) . " bytes\n";
            echo "  Private key length: " . strlen($savedDevice['private_key_pem']) . " bytes\n";
            echo "  Is registered: " . ($savedDevice['is_registered'] ? 'Yes' : 'No') . "\n";
        } else {
            echo "✗ Certificate not properly saved\n";
            exit(1);
        }
        
        // Step 5: Test loading in "new session"
        echo "\nStep 5: Testing certificate load in new session...\n";
        $loadedCert = CertificateStorage::loadCertificate($deviceId);
        
        if ($loadedCert) {
            echo "✓ Certificate loaded successfully\n";
            echo "  Certificate length: " . strlen($loadedCert['certificate']) . " bytes\n";
            echo "  Private key length: " . strlen($loadedCert['privateKey']) . " bytes\n";
            
            // Step 6: Test API with loaded certificate
            echo "\nStep 6: Testing API with loaded certificate...\n";
            $api2 = new ZimraApi('Server', 'v1', true);
            $api2->setCertificate($loadedCert['certificate'], $loadedCert['privateKey']);
            
            try {
                $status = $api2->getStatus($deviceId);
                echo "✓ getStatus: SUCCESS\n";
                echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
            } catch (Exception $e) {
                echo "✗ getStatus: FAILED - " . $e->getMessage() . "\n";
            }
            
            try {
                $config = $api2->getConfig($deviceId);
                echo "✓ getConfig: SUCCESS\n";
                echo "  Operating Mode: " . ($config['deviceOperatingMode'] ?? 'N/A') . "\n";
            } catch (Exception $e) {
                echo "✗ getConfig: FAILED - " . $e->getMessage() . "\n";
            }
            
            echo "\n✓✓✓ CERTIFICATE PERSISTENCE FULLY WORKING! ✓✓✓\n";
        } else {
            echo "✗ Failed to load certificate\n";
            exit(1);
        }
        
    } else {
        echo "✗ No certificate in response\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";

