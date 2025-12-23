<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30200;
$deviceSerialNo = 'electrox-2';

echo "=== Issuing New Certificate for Device $deviceId ===\n\n";

// Generate new CSR
echo "Generating new CSR...\n";
$csrResult = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
if (!$csrResult || !isset($csrResult['csr'])) {
    echo "✗ Failed to generate CSR\n";
    exit(1);
}

echo "✓ CSR generated\n\n";

// We need to use the existing certificate to authenticate
// But we have the wrong certificate. Let's try to register again first
// Actually, let's check if we can use the issueCertificate endpoint
// But that requires authentication with the current certificate

// Since we have the wrong certificate, let's try to register again
// But the activation key might be wrong if it's already registered

// Alternative: Try to use device 30199's certificate to authenticate
// and then issue a certificate for 30200? No, that won't work.

// Best approach: Try to register device 30200 again
// If it fails with "already registered", we need to contact ZIMRA
// If it succeeds, we get a new certificate

$activationKey = '00294543';
$api = new ZimraApi('Server', 'v1', true);

echo "Attempting to register device (may fail if already registered)...\n";
try {
    $response = $api->registerDevice($deviceId, $activationKey, $csrResult['csr']);
    
    echo "✓ Device registered!\n";
    echo "  Operation ID: " . $response['operationID'] . "\n";
    
    // Save certificate
    CertificateStorage::saveCertificate($deviceId, $response['certificate'], $csrResult['privateKey']);
    echo "✓ Certificate saved\n";
    
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'DEV02') !== false || strpos($e->getMessage(), 'already registered') !== false) {
        echo "⚠ Device is already registered\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "\n  You need to contact ZIMRA to:\n";
        echo "    1. Get the correct activation key for device 30200, OR\n";
        echo "    2. Reset device 30200 registration, OR\n";
        echo "    3. Issue a new certificate for device 30200\n";
    } else {
        echo "✗ Registration failed: " . $e->getMessage() . "\n";
    }
    exit(1);
}

