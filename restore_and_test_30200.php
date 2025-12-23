<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$primaryDb = Database::getPrimaryInstance();

echo "=== Restoring Device 30200 Certificate ===\n\n";

// Read certificate files
$certFile = __DIR__ . "/certificate_30200.pem";
$keyFile = __DIR__ . "/private_key_30200.pem";

if (!file_exists($certFile) || !file_exists($keyFile)) {
    die("✗ Certificate files not found\n");
}

$certificate = file_get_contents($certFile);
$privateKey = file_get_contents($keyFile);

echo "Certificate file: " . strlen($certificate) . " bytes\n";
echo "Private key file: " . strlen($privateKey) . " bytes\n";

// Verify certificate
$cert = openssl_x509_read($certificate);
if (!$cert) {
    die("✗ Invalid certificate format\n");
}
$certInfo = openssl_x509_parse($cert);
$cn = $certInfo['subject']['CN'] ?? '';
echo "Certificate CN: $cn\n";

if (strpos($cn, '30200') === false) {
    die("✗ Certificate is not for device 30200\n");
}

echo "✓ Certificate is for device 30200\n\n";

// Verify private key
$key = openssl_pkey_get_private($privateKey);
if (!$key) {
    die("✗ Invalid private key: " . openssl_error_string() . "\n");
}
echo "✓ Private key is valid\n";
openssl_free_key($key);
openssl_x509_free($cert);

// Save to database
echo "\nSaving to database...\n";
try {
    CertificateStorage::saveCertificate(30200, $certificate, $privateKey);
    echo "✓ Certificate saved\n";
} catch (Exception $e) {
    die("✗ Failed to save: " . $e->getMessage() . "\n");
}

// Verify it can be loaded
$savedCert = CertificateStorage::loadCertificate(30200);
if (!$savedCert) {
    die("✗ Failed to load saved certificate\n");
}

echo "✓ Certificate loaded from database\n";
echo "  Certificate: " . strlen($savedCert['certificate']) . " bytes\n";
echo "  Private Key: " . strlen($savedCert['privateKey']) . " bytes\n";

// Test private key
$testKey = openssl_pkey_get_private($savedCert['privateKey']);
if (!$testKey) {
    die("✗ Decrypted private key is invalid: " . openssl_error_string() . "\n");
}
echo "✓ Decrypted private key is valid\n";
openssl_free_key($testKey);

// Update all branches to use device 30200
echo "\nUpdating branches to use device 30200...\n";
$devices = $primaryDb->getRows("SELECT * FROM fiscal_devices");
foreach ($devices as $device) {
    $primaryDb->update('fiscal_devices', [
        'device_id' => 30200,
        'device_serial_no' => 'electrox-2',
        'activation_key' => '00294543',
        'is_registered' => 1
    ], ['id' => $device['id']]);
}
echo "✓ All branches updated to device 30200\n";

// Update fiscal_config
$configs = $primaryDb->getRows("SELECT * FROM fiscal_config");
foreach ($configs as $config) {
    $primaryDb->update('fiscal_config', [
        'device_id' => 30200
    ], ['id' => $config['id']]);
}
echo "✓ Fiscal configs updated to device 30200\n";

echo "\n=== Certificate Restored Successfully ===\n";

