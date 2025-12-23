<?php
/**
 * Check if certificate files exist from previous tests and save to database
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

$deviceId = 30199;

echo "Checking for certificate files from previous tests...\n\n";

$certFile = "certificate_$deviceId.pem";
$keyFile = "private_key_$deviceId.pem";

if (file_exists($certFile) && file_exists($keyFile)) {
    echo "✓ Certificate files found\n";
    
    $certificate = file_get_contents($certFile);
    $privateKey = file_get_contents($keyFile);
    
    echo "Certificate length: " . strlen($certificate) . " bytes\n";
    echo "Private key length: " . strlen($privateKey) . " bytes\n";
    
    try {
        CertificateStorage::saveCertificate($deviceId, $certificate, $privateKey);
        echo "\n✓ Certificate saved to database\n";
        
        // Verify
        $certData = CertificateStorage::loadCertificate($deviceId);
        if ($certData) {
            echo "✓ Certificate verified in database\n";
        }
    } catch (Exception $e) {
        echo "✗ Error saving: " . $e->getMessage() . "\n";
    }
} else {
    echo "✗ Certificate files not found\n";
    echo "  Looking for: $certFile and $keyFile\n";
}

