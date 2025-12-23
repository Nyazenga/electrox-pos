<?php
/**
 * Register device or issue certificate if already registered
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

echo "Registering or issuing certificate for device $deviceId...\n\n";

$api = new ZimraApi('Server', 'v1', true);

// First, try to load existing certificate
$certData = CertificateStorage::loadCertificate($deviceId);
if ($certData) {
    echo "✓ Certificate already exists in database\n";
    exit(0);
}

// Generate CSR
echo "Generating CSR...\n";
$csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
echo "✓ CSR generated\n";

// Try registerDevice first
echo "\nAttempting device registration...\n";
try {
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    if (isset($result['certificate'])) {
        echo "✓ Device registered successfully\n";
        CertificateStorage::saveCertificate($deviceId, $result['certificate'], $csrData['privateKey']);
        echo "✓ Certificate saved to database\n";
        exit(0);
    }
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    echo "⚠ Registration failed: $errorMsg\n";
    
    // If device is already registered, try issueCertificate
    if (strpos($errorMsg, 'DEV02') !== false || strpos($errorMsg, 'already registered') !== false || strpos($errorMsg, 'incorrect') === false) {
        echo "\nDevice may already be registered. Trying issueCertificate...\n";
        
        // Need certificate to call issueCertificate - catch 22!
        // Let's try to use a temporary certificate or check if we can get one another way
        echo "⚠ Cannot issue certificate without existing certificate (catch-22)\n";
        echo "Please contact ZIMRA to reset device registration or use existing certificate\n";
        exit(1);
    }
}

echo "✗ Failed to register or issue certificate\n";

