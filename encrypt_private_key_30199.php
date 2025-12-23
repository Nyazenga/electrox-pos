<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$primaryDb = Database::getPrimaryInstance();

// Get device 30199 with plain text private key
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = 30199"
);

if (!$device || empty($device['private_key_pem'])) {
    echo "✗ Device 30199 not found or no private key\n";
    exit(1);
}

// Check if it's already encrypted (starts with base64 and is longer)
if (strlen($device['private_key_pem']) > 500 && base64_decode($device['private_key_pem'], true) !== false) {
    echo "Private key appears to be already encrypted\n";
    // But CertificateStorage expects it in private_key_pem as encrypted
    // Let's encrypt it properly
}

// Encrypt the private key
echo "Encrypting private key for device 30199...\n";
$encryptedKey = CertificateStorage::encryptPrivateKey($device['private_key_pem']);

// Update the device record
$primaryDb->update('fiscal_devices', [
    'private_key_pem' => $encryptedKey
], ['device_id' => 30199]);

echo "✓ Private key encrypted and saved\n";

// Verify CertificateStorage can load it
$certData = CertificateStorage::loadCertificate(30199);
if ($certData) {
    echo "✓ CertificateStorage can now load the certificate\n";
    echo "  Certificate: " . strlen($certData['certificate']) . " bytes\n";
    echo "  Private Key: " . strlen($certData['privateKey']) . " bytes\n";
} else {
    echo "✗ CertificateStorage still can't load the certificate\n";
}

