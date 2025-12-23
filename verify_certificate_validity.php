<?php
/**
 * Verify certificate validity and format
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

$deviceId = 30199;

echo "=== Verifying Certificate Validity ===\n\n";

// Load from database
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    die("✗ No certificate found\n");
}

echo "Certificate loaded:\n";
echo "  Certificate length: " . strlen($certData['certificate']) . " bytes\n";
echo "  Private key length: " . strlen($certData['privateKey']) . " bytes\n\n";

// Verify certificate format
echo "Certificate format check:\n";
$certValid = (strpos($certData['certificate'], '-----BEGIN CERTIFICATE-----') !== false && 
              strpos($certData['certificate'], '-----END CERTIFICATE-----') !== false);
echo "  Certificate PEM format: " . ($certValid ? '✓ Valid' : '✗ Invalid') . "\n";

$keyValid = (strpos($certData['privateKey'], '-----BEGIN') !== false && 
             strpos($certData['privateKey'], '-----END') !== false);
echo "  Private key PEM format: " . ($keyValid ? '✓ Valid' : '✗ Invalid') . "\n\n";

// Parse certificate
$cert = openssl_x509_read($certData['certificate']);
if ($cert) {
    $details = openssl_x509_parse($cert);
    echo "Certificate details:\n";
    echo "  Subject: " . ($details['subject']['CN'] ?? 'N/A') . "\n";
    echo "  Issuer: " . ($details['issuer']['CN'] ?? 'N/A') . "\n";
    echo "  Valid From: " . (isset($details['validFrom_time_t']) ? date('Y-m-d H:i:s', $details['validFrom_time_t']) : 'N/A') . "\n";
    echo "  Valid To: " . (isset($details['validTo_time_t']) ? date('Y-m-d H:i:s', $details['validTo_time_t']) : 'N/A') . "\n";
    
    $now = time();
    $validFrom = $details['validFrom_time_t'] ?? 0;
    $validTo = $details['validTo_time_t'] ?? 0;
    
    echo "\nCertificate validity:\n";
    if ($now < $validFrom) {
        echo "  Status: ✗ Not yet valid\n";
    } elseif ($now > $validTo) {
        echo "  Status: ✗ EXPIRED\n";
    } else {
        echo "  Status: ✓ Valid\n";
        $daysLeft = floor(($validTo - $now) / 86400);
        echo "  Days until expiry: $daysLeft\n";
    }
} else {
    echo "✗ Failed to parse certificate: " . openssl_error_string() . "\n";
}

// Verify private key
$key = openssl_pkey_get_private($certData['privateKey']);
if ($key) {
    echo "\nPrivate key:\n";
    echo "  Status: ✓ Valid\n";
    $keyDetails = openssl_pkey_get_details($key);
    echo "  Type: " . ($keyDetails['type'] == OPENSSL_KEYTYPE_EC ? 'ECC' : 'RSA') . "\n";
    if ($keyDetails['type'] == OPENSSL_KEYTYPE_EC) {
        echo "  Curve: " . ($keyDetails['ec']['curve_name'] ?? 'N/A') . "\n";
    }
} else {
    echo "\n✗ Failed to parse private key: " . openssl_error_string() . "\n";
}

// Check certificate status
$status = CertificateStorage::checkCertificateStatus($deviceId);
echo "\nCertificate storage status:\n";
echo "  Expired: " . ($status['expired'] ? 'Yes' : 'No') . "\n";
echo "  Expiring Soon: " . ($status['expiringSoon'] ? 'Yes' : 'No') . "\n";
echo "  Valid Till: " . ($status['validTill'] ? $status['validTill']->format('Y-m-d H:i:s') : 'N/A') . "\n";

