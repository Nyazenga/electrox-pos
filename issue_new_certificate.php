<?php
/**
 * Issue new certificate for already registered device
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

$deviceId = 30199;
$deviceSerialNo = 'electrox-1';

echo "=== Issuing New Certificate ===\n\n";

// Load existing certificate to authenticate
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    die("✗ No existing certificate found. Cannot issue new certificate without authentication.\n");
}

echo "✓ Existing certificate loaded for authentication\n";

// Initialize API with existing certificate
$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);

// Generate new CSR
echo "Generating new CSR...\n";
$csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
echo "✓ CSR generated\n";

// Issue new certificate
echo "\nIssuing new certificate...\n";
try {
    $result = $api->issueCertificate($deviceId, $csrData['csr']);
    
    if (isset($result['certificate'])) {
        echo "✓ New certificate received\n";
        
        // Save new certificate
        CertificateStorage::saveCertificate($deviceId, $result['certificate'], $csrData['privateKey']);
        echo "✓ New certificate saved to database\n";
        
        // Test with new certificate
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
    } else {
        echo "✗ No certificate in response\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

