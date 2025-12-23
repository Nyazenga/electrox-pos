<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

echo "=== Restoring Certificates from Files ===\n\n";

// Device 30200
$deviceId = 30200;
$certFile = __DIR__ . "/certificate_{$deviceId}.pem";
$keyFile = __DIR__ . "/private_key_{$deviceId}.pem";

if (file_exists($certFile) && file_exists($keyFile)) {
    echo "Found certificate files for device $deviceId\n";
    $certificate = file_get_contents($certFile);
    $privateKey = file_get_contents($keyFile);
    
    echo "  Certificate: " . strlen($certificate) . " bytes\n";
    echo "  Private Key: " . strlen($privateKey) . " bytes\n";
    
    // Verify certificate is for correct device
    $cert = openssl_x509_read($certificate);
    if ($cert) {
        $certInfo = openssl_x509_parse($cert);
        $cn = $certInfo['subject']['CN'] ?? '';
        echo "  Certificate CN: $cn\n";
        
        if (strpos($cn, strval($deviceId)) !== false) {
            echo "  ✓ Certificate is for device $deviceId\n\n";
            
            // Save to database
            echo "Saving to database...\n";
            CertificateStorage::saveCertificate($deviceId, $certificate, $privateKey);
            echo "✓ Certificate saved to database\n\n";
            
            // Verify
            $savedCert = CertificateStorage::loadCertificate($deviceId);
            if ($savedCert) {
                echo "✓ Certificate verified in database\n";
                echo "  Certificate: " . strlen($savedCert['certificate']) . " bytes\n";
                echo "  Private Key: " . strlen($savedCert['privateKey']) . " bytes\n";
                
                // Test private key
                $key = openssl_pkey_get_private($savedCert['privateKey']);
                if ($key) {
                    echo "  ✓ Private key is valid\n";
                    openssl_free_key($key);
                } else {
                    echo "  ✗ Private key is invalid\n";
                }
            }
        } else {
            echo "  ✗ Certificate is NOT for device $deviceId (CN: $cn)\n";
        }
        openssl_x509_free($cert);
    }
} else {
    echo "✗ Certificate files not found for device $deviceId\n";
}

echo "\n";

// Device 30199
$deviceId = 30199;
$certFile = __DIR__ . "/certificate_{$deviceId}.pem";
$keyFile = __DIR__ . "/private_key_{$deviceId}.pem";

if (file_exists($certFile) && file_exists($keyFile)) {
    echo "Found certificate files for device $deviceId\n";
    $certificate = file_get_contents($certFile);
    $privateKey = file_get_contents($keyFile);
    
    echo "  Certificate: " . strlen($certificate) . " bytes\n";
    echo "  Private Key: " . strlen($privateKey) . " bytes\n";
    
    // Verify certificate is for correct device
    $cert = openssl_x509_read($certificate);
    if ($cert) {
        $certInfo = openssl_x509_parse($cert);
        $cn = $certInfo['subject']['CN'] ?? '';
        echo "  Certificate CN: $cn\n";
        
        if (strpos($cn, strval($deviceId)) !== false) {
            echo "  ✓ Certificate is for device $deviceId\n\n";
            
            // Save to database
            echo "Saving to database...\n";
            CertificateStorage::saveCertificate($deviceId, $certificate, $privateKey);
            echo "✓ Certificate saved to database\n\n";
            
            // Verify
            $savedCert = CertificateStorage::loadCertificate($deviceId);
            if ($savedCert) {
                echo "✓ Certificate verified in database\n";
                echo "  Certificate: " . strlen($savedCert['certificate']) . " bytes\n";
                echo "  Private Key: " . strlen($savedCert['privateKey']) . " bytes\n";
                
                // Test private key
                $key = openssl_pkey_get_private($savedCert['privateKey']);
                if ($key) {
                    echo "  ✓ Private key is valid\n";
                    openssl_free_key($key);
                } else {
                    echo "  ✗ Private key is invalid\n";
                }
            }
        } else {
            echo "  ✗ Certificate is NOT for device $deviceId (CN: $cn)\n";
        }
        openssl_x509_free($cert);
    }
} else {
    echo "✗ Certificate files not found for device $deviceId\n";
}

echo "\n=== Restoration Complete ===\n";

