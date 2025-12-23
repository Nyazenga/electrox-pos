<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30200;
$deviceSerialNo = 'electrox-2';
$activationKey = '00294543'; // Correct activation key from ZIMRA

echo "=== Registering Device $deviceId ===\n\n";
echo "Device Details:\n";
echo "  Device ID: $deviceId\n";
echo "  Serial: $deviceSerialNo\n";
echo "  Activation Key: $activationKey\n\n";

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

// Initialize API
$api = new ZimraApi('Server', 'v1', true);

// Register device
echo "Registering device with ZIMRA...\n";
try {
    $response = $api->registerDevice($deviceId, $activationKey, $csrResult['csr']);
    
    echo "✓ Device registered successfully!\n";
    echo "  Operation ID: " . $response['operationID'] . "\n";
    echo "  Certificate length: " . strlen($response['certificate']) . " bytes\n\n";
    
    // Verify certificate is for correct device
    $cert = openssl_x509_read($response['certificate']);
    if ($cert) {
        $certInfo = openssl_x509_parse($cert);
        $cn = $certInfo['subject']['CN'] ?? '';
        echo "Certificate CN: $cn\n";
        if (strpos($cn, strval($deviceId)) !== false) {
            echo "✓ Certificate is for device $deviceId\n\n";
        } else {
            echo "⚠ Warning: Certificate CN doesn't match device ID\n\n";
        }
        openssl_x509_free($cert);
    }
    
    // Save certificate
    echo "Saving certificate to database...\n";
    CertificateStorage::saveCertificate($deviceId, $response['certificate'], $csrResult['privateKey']);
    echo "✓ Certificate saved to database\n\n";
    
    // Also save to file for backup
    $certFile = __DIR__ . "/device_{$deviceId}_cert.pem";
    $keyFile = __DIR__ . "/device_{$deviceId}_key.pem";
    file_put_contents($certFile, $response['certificate']);
    file_put_contents($keyFile, $csrResult['privateKey']);
    echo "✓ Certificate also saved to files:\n";
    echo "  $certFile\n";
    echo "  $keyFile\n\n";
    
    // Verify it can be loaded
    $savedCert = CertificateStorage::loadCertificate($deviceId);
    if ($savedCert) {
        echo "✓ Certificate verified in database\n";
        echo "  Certificate: " . strlen($savedCert['certificate']) . " bytes\n";
        echo "  Private Key: " . strlen($savedCert['privateKey']) . " bytes\n\n";
        
        // Test authentication
        echo "Testing authentication...\n";
        $testApi = new ZimraApi('Server', 'v1', true);
        $testApi->setCertificate($savedCert['certificate'], $savedCert['privateKey']);
        
        try {
            $status = $testApi->getStatus($deviceId);
            echo "✓ Authentication successful!\n";
            echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
            
            // Try opening fiscal day
            if ($status['fiscalDayStatus'] !== 'FiscalDayOpened') {
                echo "\nOpening fiscal day...\n";
                $fiscalDayOpened = date('Y-m-d\TH:i:s');
                $openDayResponse = $testApi->openDay($deviceId, $fiscalDayOpened);
                echo "✓ Fiscal day opened (Day #{$openDayResponse['fiscalDayNo']})\n";
            }
            
            echo "\n✓ Device $deviceId is ready for fiscalization!\n";
            
        } catch (Exception $e) {
            echo "✗ Authentication failed: " . $e->getMessage() . "\n";
        }
    } else {
        echo "✗ Failed to verify saved certificate\n";
    }
    
} catch (Exception $e) {
    echo "✗ Registration failed: " . $e->getMessage() . "\n";
    
    if (strpos($e->getMessage(), 'DEV02') !== false) {
        echo "\n⚠ Device is already registered.\n";
        echo "You need to contact ZIMRA to:\n";
        echo "  1. Get the existing certificate for device $deviceId, OR\n";
        echo "  2. Reset the device registration\n";
    }
    
    exit(1);
}

echo "\n=== Registration Complete ===\n";

