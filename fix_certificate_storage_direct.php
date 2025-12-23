<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

echo "=== Fixing Certificate Storage Directly ===\n\n";

// Read certificate files
$certFile = __DIR__ . "/certificate_30200.pem";
$keyFile = __DIR__ . "/private_key_30200.pem";

if (!file_exists($certFile) || !file_exists($keyFile)) {
    die("✗ Certificate files not found\n");
}

$certificate = file_get_contents($certFile);
$privateKey = file_get_contents($keyFile);

echo "Certificate: " . strlen($certificate) . " bytes\n";
echo "Private Key: " . strlen($privateKey) . " bytes\n\n";

// Verify
$cert = openssl_x509_read($certificate);
if (!$cert) {
    die("✗ Invalid certificate\n");
}
$certInfo = openssl_x509_parse($cert);
$cn = $certInfo['subject']['CN'] ?? '';
echo "Certificate CN: $cn\n";

$key = openssl_pkey_get_private($privateKey);
if (!$key) {
    die("✗ Invalid private key\n");
}
echo "✓ Certificate and key are valid\n\n";
openssl_free_key($key);
openssl_x509_free($cert);

// Get device record
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = 30200"
);

if (!$device) {
    die("✗ Device record not found\n");
}

echo "Updating device record directly...\n";

// Encrypt private key properly
require_once APP_PATH . '/includes/certificate_storage.php';
$encryptedKey = CertificateStorage::encryptPrivateKey($privateKey);

// Get certificate expiry
$cert = openssl_x509_read($certificate);
$certInfo = openssl_x509_parse($cert);
$validTill = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
openssl_x509_free($cert);

// Update directly
$primaryDb->update('fiscal_devices', [
    'certificate_pem' => $certificate,
    'private_key_pem' => $encryptedKey,
    'certificate_valid_till' => $validTill,
    'is_registered' => 1
], ['device_id' => 30200]);

echo "✓ Certificate and key saved directly\n\n";

// Verify decryption works
$decryptedKey = CertificateStorage::decryptPrivateKey($encryptedKey);
if ($decryptedKey === $privateKey) {
    echo "✓ Decryption works correctly\n";
} else {
    echo "✗ Decryption failed - keys don't match\n";
    echo "  Original length: " . strlen($privateKey) . "\n";
    echo "  Decrypted length: " . strlen($decryptedKey) . "\n";
}

// Test loading
$certData = CertificateStorage::loadCertificate(30200);
if ($certData) {
    echo "✓ Certificate loads correctly\n";
    
    // Test private key
    $testKey = openssl_pkey_get_private($certData['privateKey']);
    if ($testKey) {
        echo "✓ Decrypted private key is valid\n";
        openssl_free_key($testKey);
    } else {
        echo "✗ Decrypted private key is invalid\n";
    }
} else {
    echo "✗ Failed to load certificate\n";
}

echo "\n=== Complete ===\n";

