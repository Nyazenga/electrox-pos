<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$primaryDb = Database::getPrimaryInstance();

// The certificate is for device 30199, so we need to get the original private key
// Since we don't have it, let's check if we can find it in any backup or regenerate

// Actually, let's check what we have in the database
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = 30199"
);

echo "Device 30199:\n";
echo "  Certificate: " . (empty($device['certificate_pem']) ? 'Missing' : strlen($device['certificate_pem']) . ' bytes') . "\n";
echo "  Private key (encrypted): " . (empty($device['private_key_pem']) ? 'Missing' : strlen($device['private_key_pem']) . ' bytes') . "\n\n";

// Try to decrypt it
try {
    $decrypted = CertificateStorage::decryptPrivateKey($device['private_key_pem']);
    echo "Decrypted private key: " . strlen($decrypted) . " bytes\n";
    
    // Check if it's valid
    $key = openssl_pkey_get_private($decrypted);
    if ($key) {
        echo "✓ Private key is valid\n";
        openssl_free_key($key);
    } else {
        echo "✗ Private key is invalid: " . openssl_error_string() . "\n";
        echo "\nThe private key might be corrupted. You'll need to:\n";
        echo "1. Re-register device 30199 with ZIMRA, OR\n";
        echo "2. Get the original private key from backup\n";
    }
} catch (Exception $e) {
    echo "✗ Decryption failed: " . $e->getMessage() . "\n";
}

