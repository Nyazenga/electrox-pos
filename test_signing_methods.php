<?php
/**
 * Test different signing methods based on ZIMRA documentation
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/zimra_signature.php';

// Get device and private key
$db = Database::getPrimaryInstance();
$device = $db->getRow(
    "SELECT device_id, private_key_pem FROM fiscal_devices WHERE device_id = 30200 AND is_registered = 1"
);

if (!$device || !$device['private_key_pem']) {
    die("Device not found or no private key\n");
}

$privateKeyPem = $device['private_key_pem'];

// Test signature string
$signatureString = '30200FISCALINVOICEUSD122025-12-19T00:00:07300A15.5040300';

echo "Testing different signing methods...\n";
echo "Signature string: $signatureString\n\n";

// Load private key
$privateKey = openssl_pkey_get_private($privateKeyPem);
if (!$privateKey) {
    die("Failed to load private key: " . openssl_error_string() . "\n");
}

// Get key details
$keyDetails = openssl_pkey_get_details($privateKey);
$keyType = $keyDetails['type'] === OPENSSL_KEYTYPE_RSA ? 'RSA' : ($keyDetails['type'] === OPENSSL_KEYTYPE_EC ? 'ECC' : 'UNKNOWN');

echo "Key type: $keyType\n";
if ($keyType === 'RSA') {
    echo "RSA key size: " . ($keyDetails['bits'] ?? 'unknown') . " bits\n";
} elseif ($keyType === 'ECC') {
    echo "ECC curve: " . ($keyDetails['ec']['curve_name'] ?? 'unknown') . "\n";
}
echo "\n";

// Method 1: Current method - openssl_sign with SHA256 (hashes then signs)
echo "Method 1: openssl_sign with OPENSSL_ALGO_SHA256 (hashes then signs)\n";
$hash1 = hash('sha256', $signatureString, true);
$hash1Base64 = base64_encode($hash1);
$signature1 = '';
$success1 = openssl_sign($signatureString, $signature1, $privateKey, OPENSSL_ALGO_SHA256);
if ($success1) {
    $signature1Base64 = base64_encode($signature1);
    echo "  Hash: $hash1Base64\n";
    echo "  Signature: " . substr($signature1Base64, 0, 50) . "...\n";
    echo "  Length: " . strlen($signature1) . " bytes\n";
} else {
    echo "  FAILED: " . openssl_error_string() . "\n";
}
echo "\n";

// Method 2: For RSA - try signing the raw string directly (no hashing)
if ($keyType === 'RSA') {
    echo "Method 2: RSA - Sign raw string directly (no hashing)\n";
    // This requires manual RSA signing without PKCS#1 padding
    // Note: This is NOT standard and may not work with openssl_sign
    // But let's try with OPENSSL_ALGO_SHA1 or other algorithms
    $signature2 = '';
    $success2 = openssl_sign($signatureString, $signature2, $privateKey, OPENSSL_ALGO_SHA1);
    if ($success2) {
        $signature2Base64 = base64_encode($signature2);
        echo "  Signature (SHA1): " . substr($signature2Base64, 0, 50) . "...\n";
        echo "  Length: " . strlen($signature2) . " bytes\n";
    } else {
        echo "  FAILED: " . openssl_error_string() . "\n";
    }
    echo "\n";
}

// Method 3: For ECC - sign the hash directly
if ($keyType === 'ECC') {
    echo "Method 3: ECC - Sign hash directly\n";
    $hash3 = hash('sha256', $signatureString, true);
    // ECC signing with openssl_sign already signs the hash
    $signature3 = '';
    $success3 = openssl_sign($signatureString, $signature3, $privateKey, OPENSSL_ALGO_SHA256);
    if ($success3) {
        $signature3Base64 = base64_encode($signature3);
        echo "  Hash: " . base64_encode($hash3) . "\n";
        echo "  Signature: " . substr($signature3Base64, 0, 50) . "...\n";
        echo "  Length: " . strlen($signature3) . " bytes\n";
    } else {
        echo "  FAILED: " . openssl_error_string() . "\n";
    }
    echo "\n";
}

// Method 4: Try with different hash algorithms
echo "Method 4: Testing different hash algorithms\n";
$algorithms = [
    'OPENSSL_ALGO_SHA1' => OPENSSL_ALGO_SHA1,
    'OPENSSL_ALGO_SHA256' => OPENSSL_ALGO_SHA256,
    'OPENSSL_ALGO_SHA512' => OPENSSL_ALGO_SHA512,
];

foreach ($algorithms as $name => $algo) {
    $sig = '';
    $success = openssl_sign($signatureString, $sig, $privateKey, $algo);
    if ($success) {
        $sigBase64 = base64_encode($sig);
        echo "  $name: " . substr($sigBase64, 0, 30) . "... (length: " . strlen($sig) . " bytes)\n";
    }
}
echo "\n";

openssl_free_key($privateKey);

echo "Note: openssl_sign always hashes the data first, then signs the hash.\n";
echo "For RSA, the documentation says to sign the string directly, but this\n";
echo "may just be mathematical notation - in practice, PKCS#1 padding includes hashing.\n";

