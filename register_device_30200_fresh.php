<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30200;
$deviceSerialNo = 'electrox-2';
$activationKey = '00294543';

echo "=== Registering Device $deviceId ===\n\n";

// Generate CSR
echo "Generating CSR...\n";
$csrResult = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
if (!$csrResult || !isset($csrResult['csr'])) {
    echo "✗ Failed to generate CSR\n";
    exit(1);
}

echo "✓ CSR generated\n";
echo "  Private key length: " . strlen($csrResult['privateKey']) . " bytes\n";
echo "  CSR length: " . strlen($csrResult['csr']) . " bytes\n\n";

// Initialize API (no certificate needed for registration)
$api = new ZimraApi('Server', 'v1', true);

// Register device
echo "Registering device with ZIMRA...\n";
try {
    $response = $api->registerDevice($deviceId, $activationKey, $csrResult['csr']);
    
    echo "✓ Device registered successfully!\n";
    echo "  Operation ID: " . $response['operationID'] . "\n";
    echo "  Certificate length: " . strlen($response['certificate']) . " bytes\n\n";
    
    // Save certificate
    echo "Saving certificate...\n";
    CertificateStorage::saveCertificate($deviceId, $response['certificate'], $csrResult['privateKey']);
    echo "✓ Certificate saved to database\n\n";
    
    // Verify it was saved
    $savedCert = CertificateStorage::loadCertificate($deviceId);
    if ($savedCert) {
        echo "✓ Certificate verified in database\n";
        echo "  Certificate: " . strlen($savedCert['certificate']) . " bytes\n";
        echo "  Private key: " . strlen($savedCert['privateKey']) . " bytes\n";
    } else {
        echo "✗ Failed to verify saved certificate\n";
    }
    
    // Also save to file for backup
    $certFile = __DIR__ . "/device_{$deviceId}_cert.pem";
    $keyFile = __DIR__ . "/device_{$deviceId}_key.pem";
    file_put_contents($certFile, $response['certificate']);
    file_put_contents($keyFile, $csrResult['privateKey']);
    echo "\n✓ Certificate also saved to:\n";
    echo "  $certFile\n";
    echo "  $keyFile\n";
    
} catch (Exception $e) {
    echo "✗ Registration failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Registration Complete ===\n";

