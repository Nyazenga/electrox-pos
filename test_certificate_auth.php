<?php
/**
 * Test certificate authentication
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Testing Certificate Authentication ===\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$api = new ZimraApi('Server', 'v1', true);

// Load certificate
$primaryDb = Database::getPrimaryInstance();
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

// Load certificate using CertificateStorage
$certData = CertificateStorage::loadCertificate($deviceId);

if ($certData) {
    echo "✓ Certificate found in database (loaded via CertificateStorage)\n";
    echo "Certificate length: " . strlen($certData['certificate']) . " bytes\n";
    echo "Private key length: " . strlen($certData['privateKey']) . " bytes\n";
    
    // Verify certificate format
    echo "\nCertificate format check:\n";
    echo "  Has BEGIN: " . (strpos($certData['certificate'], '-----BEGIN CERTIFICATE-----') !== false ? 'Yes' : 'No') . "\n";
    echo "  Has END: " . (strpos($certData['certificate'], '-----END CERTIFICATE-----') !== false ? 'Yes' : 'No') . "\n";
    
    echo "\nPrivate key format check:\n";
    echo "  Has BEGIN: " . (strpos($certData['privateKey'], '-----BEGIN') !== false ? 'Yes' : 'No') . "\n";
    echo "  Has END: " . (strpos($certData['privateKey'], '-----END') !== false ? 'Yes' : 'No') . "\n";
    
    // Check certificate status
    $status = CertificateStorage::checkCertificateStatus($deviceId);
    echo "\nCertificate status:\n";
    echo "  Expired: " . ($status['expired'] ? 'Yes' : 'No') . "\n";
    echo "  Expiring Soon: " . ($status['expiringSoon'] ? 'Yes' : 'No') . "\n";
    echo "  Valid Till: " . ($status['validTill'] ? $status['validTill']->format('Y-m-d H:i:s') : 'N/A') . "\n";
    
    // Set certificate
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    echo "\n✓ Certificate set in API client\n";
    
    // Test getConfig
    echo "\nTesting getConfig with certificate...\n";
    try {
        $result = $api->getConfig($deviceId);
        echo "✓ SUCCESS!\n";
        echo "Operating Mode: " . ($result['deviceOperatingMode'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "✗ FAILED: " . $e->getMessage() . "\n";
    }
    
    // Test getStatus
    echo "\nTesting getStatus with certificate...\n";
    try {
        $result = $api->getStatus($deviceId);
        echo "✓ SUCCESS!\n";
        echo "Fiscal Day Status: " . ($result['fiscalDayStatus'] ?? 'N/A') . "\n";
    } catch (Exception $e) {
        echo "✗ FAILED: " . $e->getMessage() . "\n";
    }
    
} else {
    echo "✗ No certificate found. Please register device first.\n";
}

echo "\n=== Test Complete ===\n";

