<?php
/**
 * Check for existing certificate in database
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

$deviceId = 30199;
$primaryDb = Database::getPrimaryInstance();

echo "Checking for existing certificate...\n\n";

// Check raw database
$device = $primaryDb->getRow(
    "SELECT id, device_id, is_registered, certificate_pem, private_key_pem, certificate_valid_till FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if ($device) {
    echo "Device found:\n";
    echo "  ID: " . $device['id'] . "\n";
    echo "  Device ID: " . $device['device_id'] . "\n";
    echo "  Is Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
    echo "  Has Certificate: " . ($device['certificate_pem'] ? 'Yes (' . strlen($device['certificate_pem']) . ' bytes)' : 'No') . "\n";
    echo "  Has Private Key: " . ($device['private_key_pem'] ? 'Yes (' . strlen($device['private_key_pem']) . ' bytes)' : 'No') . "\n";
    echo "  Valid Till: " . ($device['certificate_valid_till'] ?? 'N/A') . "\n";
    
    if ($device['certificate_pem'] && $device['private_key_pem']) {
        echo "\n✓ Certificate found in database!\n";
        
        // Try loading via CertificateStorage
        $certData = CertificateStorage::loadCertificate($deviceId);
        if ($certData) {
            echo "✓ Certificate loaded via CertificateStorage\n";
            echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
            echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n";
        } else {
            echo "✗ CertificateStorage failed to load\n";
        }
    }
} else {
    echo "✗ Device not found in database\n";
}

