<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$primaryDb = Database::getPrimaryInstance();

// Get certificate from device 30200 (which actually has 30199's certificate)
$certData = CertificateStorage::loadCertificate(30200);
if (!$certData) {
    echo "✗ Certificate not found for device 30200\n";
    exit(1);
}

echo "Certificate found: " . strlen($certData['certificate']) . " bytes\n";

// Save it for device 30199
echo "Saving certificate for device 30199...\n";
CertificateStorage::saveCertificate(30199, $certData['certificate'], $certData['privateKey']);
echo "✓ Certificate saved for device 30199\n";

// Verify
$cert30199 = CertificateStorage::loadCertificate(30199);
if ($cert30199) {
    echo "✓ Verified: Certificate loaded for device 30199\n";
    echo "  Certificate: " . strlen($cert30199['certificate']) . " bytes\n";
    echo "  Private Key: " . strlen($cert30199['privateKey']) . " bytes\n";
} else {
    echo "✗ Failed to verify certificate for device 30199\n";
}

