<?php
/**
 * Try fresh registration - may fail if already registered, but let's see the error
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

echo "=== Attempting Fresh Registration ===\n\n";

$api = new ZimraApi('Server', 'v1', true);

// Generate CSR
echo "Generating CSR...\n";
$csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
echo "✓ CSR generated\n";
echo "  CSR Subject CN: ZIMRA-$deviceSerialNo-" . str_pad($deviceId, 10, '0', STR_PAD_LEFT) . "\n\n";

// Try registration
echo "Attempting registration...\n";
try {
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    
    if (isset($result['certificate'])) {
        echo "✓ Registration successful!\n";
        echo "  Certificate received: " . strlen($result['certificate']) . " bytes\n";
        
        // Save certificate
        CertificateStorage::saveCertificate($deviceId, $result['certificate'], $csrData['privateKey']);
        echo "✓ Certificate saved to database\n";
        
        // Test immediately
        echo "\nTesting API with new certificate...\n";
        $api2 = new ZimraApi('Server', 'v1', true);
        $api2->setCertificate($result['certificate'], $csrData['privateKey']);
        
        try {
            $status = $api2->getStatus($deviceId);
            echo "✓ getStatus: SUCCESS\n";
            echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
        } catch (Exception $e) {
            echo "✗ getStatus: FAILED - " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    echo "✗ Registration failed: $errorMsg\n";
    
    // Check error code
    if (strpos($errorMsg, 'DEV02') !== false) {
        echo "\n⚠ Device already registered or activation key incorrect\n";
        echo "This is expected if device was registered previously\n";
    }
}

