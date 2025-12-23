<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30200;

// Load from database
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    die("✗ Certificate not found\n");
}

echo "Certificate loaded:\n";
echo "  Certificate: " . strlen($certData['certificate']) . " bytes\n";
echo "  Private Key: " . strlen($certData['privateKey']) . " bytes\n\n";

// Test private key
$key = openssl_pkey_get_private($certData['privateKey']);
if (!$key) {
    echo "✗ Private key is invalid: " . openssl_error_string() . "\n";
    
    // Check if it has proper PEM headers
    echo "\nPrivate key content check:\n";
    echo "  Has BEGIN: " . (strpos($certData['privateKey'], '-----BEGIN') !== false ? 'Yes' : 'No') . "\n";
    echo "  Has END: " . (strpos($certData['privateKey'], '-----END') !== false ? 'Yes' : 'No') . "\n";
    echo "  First 50 chars: " . substr($certData['privateKey'], 0, 50) . "\n";
    echo "  Last 50 chars: " . substr($certData['privateKey'], -50) . "\n";
    
    exit(1);
}

echo "✓ Private key is valid\n";

// Test writing to temp file (like ZimraApi does)
$tempFile = tempnam(sys_get_temp_dir(), 'zimra_key_');
file_put_contents($tempFile, $certData['privateKey']);

echo "\nTesting temp file:\n";
echo "  File: $tempFile\n";
echo "  Size: " . filesize($tempFile) . " bytes\n";

// Try to read it back
$fileContent = file_get_contents($tempFile);
$key2 = openssl_pkey_get_private($fileContent);
if (!$key2) {
    echo "✗ Private key from file is invalid: " . openssl_error_string() . "\n";
} else {
    echo "✓ Private key from file is valid\n";
    openssl_free_key($key2);
}

unlink($tempFile);
openssl_free_key($key);

// Compare with original file
$originalKey = file_get_contents(__DIR__ . "/private_key_30200.pem");
echo "\nComparing with original file:\n";
echo "  Original: " . strlen($originalKey) . " bytes\n";
echo "  Decrypted: " . strlen($certData['privateKey']) . " bytes\n";
echo "  Match: " . ($originalKey === $certData['privateKey'] ? 'Yes' : 'No') . "\n";

if ($originalKey !== $certData['privateKey']) {
    echo "  ⚠ Private keys don't match - decryption may have issues\n";
}

