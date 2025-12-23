<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30200;

echo "=== Verifying Certificate for Device $deviceId ===\n\n";

// Load certificate
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    echo "✗ Certificate not found\n";
    exit(1);
}

echo "✓ Certificate loaded\n";
echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n\n";

// Parse certificate
$cert = openssl_x509_read($certData['certificate']);
if (!$cert) {
    echo "✗ Failed to parse certificate: " . openssl_error_string() . "\n";
    exit(1);
}

$certInfo = openssl_x509_parse($cert);
echo "Certificate Details:\n";
echo "  Subject: " . $certInfo['name'] . "\n";
echo "  Issuer: " . $certInfo['issuer']['CN'] . "\n";
echo "  Valid From: " . date('Y-m-d H:i:s', $certInfo['validFrom_time_t']) . "\n";
echo "  Valid To: " . date('Y-m-d H:i:s', $certInfo['validTo_time_t']) . "\n";
echo "  Serial: " . $certInfo['serialNumberHex'] . "\n";

// Check if expired
$now = time();
if ($certInfo['validTo_time_t'] < $now) {
    echo "\n⚠ WARNING: Certificate has EXPIRED!\n";
    echo "  Expired: " . date('Y-m-d H:i:s', $certInfo['validTo_time_t']) . "\n";
    echo "  Current: " . date('Y-m-d H:i:s', $now) . "\n";
} else {
    echo "\n✓ Certificate is valid (not expired)\n";
}

// Check if device ID matches
$subject = $certInfo['subject'];
$cn = $subject['CN'] ?? '';
echo "\nCertificate CN: $cn\n";
if (strpos($cn, strval($deviceId)) !== false) {
    echo "✓ Device ID matches certificate\n";
} else {
    echo "⚠ Device ID might not match certificate\n";
}

openssl_x509_free($cert);

