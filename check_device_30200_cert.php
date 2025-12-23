<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30200;
$primaryDb = Database::getPrimaryInstance();

// Check device record
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if (!$device) {
    echo "Device 30200 not found in database\n";
    exit(1);
}

echo "Device 30200:\n";
echo "  Serial: {$device['device_serial_no']}\n";
echo "  Activation Key: {$device['activation_key']}\n";
echo "  Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
echo "  Certificate in DB: " . (empty($device['certificate_pem']) ? 'Not stored' : 'Stored (' . strlen($device['certificate_pem']) . ' bytes)') . "\n\n";

// Check certificate from CertificateStorage
$certData = CertificateStorage::loadCertificate($deviceId);
if ($certData) {
    echo "Certificate from CertificateStorage:\n";
    echo "  Certificate: " . strlen($certData['certificate']) . " bytes\n";
    echo "  Private Key: " . strlen($certData['privateKey']) . " bytes\n\n";
    
    // Parse certificate to check device ID
    $cert = openssl_x509_read($certData['certificate']);
    if ($cert) {
        $certInfo = openssl_x509_parse($cert);
        $cn = $certInfo['subject']['CN'] ?? '';
        echo "Certificate CN: $cn\n";
        if (strpos($cn, '30199') !== false) {
            echo "⚠ WARNING: Certificate is for device 30199, not 30200!\n";
        } elseif (strpos($cn, '30200') !== false) {
            echo "✓ Certificate is for device 30200\n";
        } else {
            echo "⚠ Certificate CN doesn't clearly indicate device ID\n";
        }
        openssl_x509_free($cert);
    }
} else {
    echo "No certificate found via CertificateStorage\n";
}

