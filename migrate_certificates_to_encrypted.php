<?php
/**
 * Migrate existing certificates to encrypted storage
 * This script encrypts all existing plain-text private keys in the database
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Migrating Certificates to Encrypted Storage ===\n\n";

$db = Database::getPrimaryInstance();

// Get all devices with certificates
$devices = $db->getRows(
    "SELECT id, device_id, certificate_pem, private_key_pem FROM fiscal_devices WHERE certificate_pem IS NOT NULL AND private_key_pem IS NOT NULL"
);

if (empty($devices)) {
    echo "No devices with certificates found.\n";
    exit(0);
}

echo "Found " . count($devices) . " device(s) with certificates\n\n";

foreach ($devices as $device) {
    echo "Processing device ID: " . $device['device_id'] . "\n";
    
    // Check if already encrypted (encrypted keys are base64 and longer)
    $privateKey = $device['private_key_pem'];
    $isEncrypted = (strlen($privateKey) > 2000 && base64_decode($privateKey, true) !== false);
    
    if ($isEncrypted) {
        echo "  ⚠ Already encrypted, skipping\n";
        continue;
    }
    
    // Verify it's a valid PEM key
    if (strpos($privateKey, '-----BEGIN') === false) {
        echo "  ✗ Invalid private key format, skipping\n";
        continue;
    }
    
    try {
        // Encrypt and save
        CertificateStorage::saveCertificate(
            $device['device_id'],
            $device['certificate_pem'],
            $privateKey
        );
        
        echo "  ✓ Certificate encrypted and saved\n";
    } catch (Exception $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Migration Complete ===\n";

