<?php
/**
 * Test with certificate from a successful registration session
 * This simulates what happens when certificate is properly saved after registration
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/zimra_signature.php';
require_once APP_PATH . '/includes/db.php';

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

echo "=== Testing with Fresh Certificate Registration ===\n\n";

// Step 1: Register device (will fail if already registered, but let's try)
$api = new ZimraApi('Server', 'v1', true);

echo "1. Attempting device registration...\n";
$csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
echo "   ✓ CSR generated\n";

try {
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    
    if (isset($result['certificate'])) {
        echo "   ✓ Certificate received\n";
        
        // Save immediately
        echo "\n2. Saving certificate to database...\n";
        CertificateStorage::saveCertificate($deviceId, $result['certificate'], $csrData['privateKey']);
        echo "   ✓ Certificate saved\n";
        
        // Test immediately with fresh certificate
        echo "\n3. Testing API endpoints with fresh certificate...\n";
        $api2 = new ZimraApi('Server', 'v1', true);
        $api2->setCertificate($result['certificate'], $csrData['privateKey']);
        
        // Test getStatus
        try {
            $status = $api2->getStatus($deviceId);
            echo "   ✓ getStatus: SUCCESS\n";
            echo "     Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
        } catch (Exception $e) {
            echo "   ✗ getStatus: FAILED - " . $e->getMessage() . "\n";
        }
        
        // Test getConfig
        try {
            $config = $api2->getConfig($deviceId);
            echo "   ✓ getConfig: SUCCESS\n";
            echo "     Operating Mode: " . ($config['deviceOperatingMode'] ?? 'N/A') . "\n";
        } catch (Exception $e) {
            echo "   ✗ getConfig: FAILED - " . $e->getMessage() . "\n";
        }
        
        // Test ping
        try {
            $ping = $api2->ping($deviceId);
            echo "   ✓ ping: SUCCESS\n";
        } catch (Exception $e) {
            echo "   ✗ ping: FAILED - " . $e->getMessage() . "\n";
        }
        
        // Now test loading from database
        echo "\n4. Testing certificate load from database...\n";
        $loadedCert = CertificateStorage::loadCertificate($deviceId);
        if ($loadedCert) {
            echo "   ✓ Certificate loaded from database\n";
            
            // Test API with loaded certificate
            $api3 = new ZimraApi('Server', 'v1', true);
            $api3->setCertificate($loadedCert['certificate'], $loadedCert['privateKey']);
            
            try {
                $status2 = $api3->getStatus($deviceId);
                echo "   ✓ getStatus (from DB): SUCCESS\n";
                echo "     Fiscal Day Status: " . ($status2['fiscalDayStatus'] ?? 'N/A') . "\n";
            } catch (Exception $e) {
                echo "   ✗ getStatus (from DB): FAILED - " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ✗ Failed to load certificate from database\n";
        }
        
    } else {
        echo "   ✗ No certificate in response\n";
    }
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    echo "   ✗ Registration failed: $errorMsg\n";
    
    if (strpos($errorMsg, 'DEV02') !== false) {
        echo "\n⚠ Device already registered. Certificate in database may be from previous registration.\n";
        echo "The certificate may need to be re-issued or the device reset by ZIMRA.\n";
    }
}

