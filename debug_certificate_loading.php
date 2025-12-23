<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30200;

echo "=== Debugging Certificate Loading ===\n\n";

// Check database
$primaryDb = Database::getPrimaryInstance();
$device = $primaryDb->getRow(
    "SELECT certificate_pem, private_key_pem, certificate_valid_till FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if (!$device) {
    die("✗ Device not found\n");
}

echo "Device found in database:\n";
echo "  Certificate: " . strlen($device['certificate_pem']) . " bytes\n";
echo "  Private Key (encrypted): " . strlen($device['private_key_pem']) . " bytes\n";
echo "  Private Key starts with BEGIN: " . (strpos($device['private_key_pem'], '-----BEGIN') !== false ? 'Yes' : 'No') . "\n";
echo "  Private Key is base64: " . (base64_decode($device['private_key_pem'], true) !== false ? 'Yes' : 'No') . "\n\n";

// Try to decrypt
echo "Attempting to decrypt private key...\n";
$decrypted = CertificateStorage::decryptPrivateKey($device['private_key_pem']);

echo "  Decrypted length: " . strlen($decrypted) . " bytes\n";
echo "  Decrypted starts with BEGIN: " . (strpos($decrypted, '-----BEGIN') !== false ? 'Yes' : 'No') . "\n";
echo "  First 50 chars: " . substr($decrypted, 0, 50) . "\n\n";

// Test if it's valid
$key = openssl_pkey_get_private($decrypted);
if ($key) {
    echo "✓ Decrypted private key is valid\n";
    openssl_free_key($key);
} else {
    echo "✗ Decrypted private key is invalid: " . openssl_error_string() . "\n";
}

// Try loading via CertificateStorage
echo "\nTrying CertificateStorage::loadCertificate()...\n";
$certData = CertificateStorage::loadCertificate($deviceId);

if ($certData) {
    echo "✓ Certificate loaded\n";
    echo "  Certificate: " . strlen($certData['certificate']) . " bytes\n";
    echo "  Private Key: " . strlen($certData['privateKey']) . " bytes\n";
    echo "  Private Key starts with BEGIN: " . (strpos($certData['privateKey'], '-----BEGIN') !== false ? 'Yes' : 'No') . "\n";
    
    $testKey = openssl_pkey_get_private($certData['privateKey']);
    if ($testKey) {
        echo "✓ Loaded private key is valid\n";
        openssl_free_key($testKey);
    } else {
        echo "✗ Loaded private key is invalid: " . openssl_error_string() . "\n";
    }
} else {
    echo "✗ Failed to load certificate\n";
}

