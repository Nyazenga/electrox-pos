<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30200;
$cert = CertificateStorage::loadCertificate($deviceId);

if ($cert) {
    echo "✓ Certificate loaded: " . strlen($cert['certificate']) . " bytes\n";
    echo "✓ Private key loaded: " . strlen($cert['privateKey']) . " bytes\n";
} else {
    echo "✗ Certificate not found for device $deviceId\n";
    
    // Check if device exists
    $primaryDb = Database::getPrimaryInstance();
    $device = $primaryDb->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
        [':device_id' => $deviceId]
    );
    
    if ($device) {
        echo "Device found, but no certificate stored\n";
        echo "Certificate present: " . (!empty($device['certificate_pem']) ? 'Yes' : 'No') . "\n";
        echo "Private key present: " . (!empty($device['private_key_encrypted']) ? 'Yes (encrypted)' : (!empty($device['private_key_pem']) ? 'Yes (plain)' : 'No')) . "\n";
    } else {
        echo "Device not found in database\n";
    }
}

