<?php
/**
 * Step-by-Step Certificate Authentication Test
 * Tests each possible cause of 401 Unauthorized
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "STEP-BY-STEP CERTIFICATE AUTHENTICATION TEST\n";
echo "========================================\n\n";

$deviceId = 30199;
$deviceSerialNo = 'electrox-1';

$primaryDb = Database::getPrimaryInstance();

// STEP 1: Load Certificate
echo "STEP 1: Loading Certificate from Database\n";
echo str_repeat("-", 50) . "\n";
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    // Try loading from file
    $certFile = "certificate_$deviceId.pem";
    $keyFile = "private_key_$deviceId.pem";
    
    if (file_exists($certFile) && file_exists($keyFile)) {
        echo "⚠ Certificate not in database, loading from file...\n";
        $certData = [
            'certificate' => file_get_contents($certFile),
            'privateKey' => file_get_contents($keyFile)
        ];
        CertificateStorage::saveCertificate($deviceId, $certData['certificate'], $certData['privateKey']);
        echo "✓ Certificate loaded from file and saved to database\n";
    } else {
        die("✗ No certificate found in database or files\n");
    }
} else {
    echo "✓ Certificate loaded from database\n";
}
echo "\n";

// STEP 2: Verify Certificate Format
echo "STEP 2: Verifying Certificate Format\n";
echo str_repeat("-", 50) . "\n";
$cert = openssl_x509_read($certData['certificate']);
if (!$cert) {
    die("✗ Failed to parse certificate: " . openssl_error_string() . "\n");
}
echo "✓ Certificate parsed successfully\n";

$details = openssl_x509_parse($cert);
echo "  Subject CN: " . ($details['subject']['CN'] ?? 'N/A') . "\n";
echo "  Issuer: " . ($details['issuer']['CN'] ?? 'N/A') . "\n";
echo "  Valid From: " . (isset($details['validFrom_time_t']) ? date('Y-m-d H:i:s', $details['validFrom_time_t']) : 'N/A') . "\n";
echo "  Valid To: " . (isset($details['validTo_time_t']) ? date('Y-m-d H:i:s', $details['validTo_time_t']) : 'N/A') . "\n";

// Check expiration
$now = time();
$validFrom = $details['validFrom_time_t'] ?? 0;
$validTo = $details['validTo_time_t'] ?? 0;

if ($now < $validFrom) {
    echo "  ⚠ Certificate not yet valid\n";
} elseif ($now > $validTo) {
    echo "  ✗ Certificate EXPIRED\n";
    die("Certificate expired. Please re-issue certificate.\n");
} else {
    $daysLeft = floor(($validTo - $now) / 86400);
    echo "  ✓ Certificate is valid (expires in $daysLeft days)\n";
}
echo "\n";

// STEP 3: Verify Certificate Matches Device ID
echo "STEP 3: Verifying Certificate Matches Device ID\n";
echo str_repeat("-", 50) . "\n";
$subjectCN = $details['subject']['CN'] ?? '';
$expectedCN = 'ZIMRA-' . $deviceSerialNo . '-' . str_pad($deviceId, 10, '0', STR_PAD_LEFT);

echo "  Certificate Subject CN: $subjectCN\n";
echo "  Expected CN: $expectedCN\n";

if ($subjectCN === $expectedCN) {
    echo "  ✓ Certificate CN matches device ID\n";
} else {
    echo "  ✗ Certificate CN does NOT match device ID!\n";
    echo "  ⚠ This could cause 401 Unauthorized\n";
}
echo "\n";

// STEP 4: Verify Certificate Issuer
echo "STEP 4: Verifying Certificate Issuer\n";
echo str_repeat("-", 50) . "\n";
$issuer = $details['issuer'];
echo "  Issuer Details:\n";
foreach ($issuer as $key => $value) {
    echo "    $key: $value\n";
}

// Check if issued by ZIMRA/FDMS
$issuerCN = $issuer['CN'] ?? '';
if (stripos($issuerCN, 'zimra') !== false || 
    stripos($issuerCN, 'fdms') !== false || 
    stripos($issuerCN, 'fiscal') !== false ||
    stripos($issuerCN, 'device') !== false) {
    echo "  ✓ Certificate appears to be issued by ZIMRA/FDMS\n";
} else {
    echo "  ⚠ Certificate issuer may not be ZIMRA/FDMS\n";
    echo "  ⚠ This could cause 401 Unauthorized\n";
}
echo "\n";

// STEP 5: Verify Private Key Matches Certificate
echo "STEP 5: Verifying Private Key Matches Certificate\n";
echo str_repeat("-", 50) . "\n";
$key = openssl_pkey_get_private($certData['privateKey']);
if (!$key) {
    die("✗ Failed to parse private key: " . openssl_error_string() . "\n");
}
echo "✓ Private key parsed successfully\n";

// Extract public key from certificate
$certPubKey = openssl_pkey_get_public($certData['certificate']);
$certPubKeyDetails = openssl_pkey_get_details($certPubKey);
$keyDetails = openssl_pkey_get_details($key);

if ($certPubKeyDetails['key'] === $keyDetails['key']) {
    echo "✓ Private key matches certificate\n";
} else {
    echo "✗ Private key does NOT match certificate!\n";
    echo "⚠ This will cause authentication failures\n";
}
echo "\n";

// STEP 6: Test Certificate in cURL (Direct)
echo "STEP 6: Testing Certificate Authentication (Direct cURL)\n";
echo str_repeat("-", 50) . "\n";

// Create temp files
$certFile = tempnam(sys_get_temp_dir(), 'test_cert_') . '.pem';
$keyFile = tempnam(sys_get_temp_dir(), 'test_key_') . '.pem';

file_put_contents($certFile, $certData['certificate']);
file_put_contents($keyFile, $certData['privateKey']);

echo "  Certificate file: $certFile (" . filesize($certFile) . " bytes)\n";
echo "  Private key file: $keyFile (" . filesize($keyFile) . " bytes)\n";

// Test with getStatus endpoint
$url = 'https://fdmsapitest.zimra.co.zw/Device/v1/' . $deviceId . '/GetStatus';
echo "\n  Testing: GET $url\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'DeviceModelName: Server',
        'DeviceModelVersion: v1'
    ],
    CURLOPT_SSLCERT => $certFile,
    CURLOPT_SSLKEY => $keyFile,
    CURLOPT_SSLCERTTYPE => 'PEM',
    CURLOPT_SSLKEYTYPE => 'PEM',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_VERBOSE => false,
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$sslVerifyResult = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
$certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);

echo "  HTTP Code: $httpCode\n";
if ($curlError) {
    echo "  cURL Error: $curlError\n";
}
echo "  SSL Verify Result: $sslVerifyResult\n";

if ($httpCode == 200) {
    echo "  ✓✓✓ AUTHENTICATION SUCCESSFUL! ✓✓✓\n";
    echo "  Response: " . substr($response, 0, 200) . "\n";
} elseif ($httpCode == 401) {
    echo "  ✗ AUTHENTICATION FAILED (401 Unauthorized)\n";
    echo "  Response: " . substr($response, 0, 500) . "\n";
    
    // Parse error response
    $errorData = json_decode($response, true);
    if ($errorData) {
        echo "\n  Error Details:\n";
        echo "    Title: " . ($errorData['title'] ?? 'N/A') . "\n";
        echo "    Detail: " . ($errorData['detail'] ?? 'N/A') . "\n";
        echo "    Error Code: " . ($errorData['errorCode'] ?? 'N/A') . "\n";
    }
    
    echo "\n  Possible Causes:\n";
    echo "    1. Certificate not issued by ZIMRA Fiscal Device Gateway\n";
    echo "    2. Certificate revoked by ZIMRA\n";
    echo "    3. Certificate expired (but we checked - it's valid)\n";
    echo "    4. Certificate not issued to device ID $deviceId\n";
    echo "    5. Certificate CN mismatch (we checked: " . ($subjectCN === $expectedCN ? 'OK' : 'MISMATCH') . ")\n";
} else {
    echo "  ⚠ Unexpected HTTP Code: $httpCode\n";
    echo "  Response: " . substr($response, 0, 200) . "\n";
}

curl_close($ch);
@unlink($certFile);
@unlink($keyFile);
echo "\n";

// STEP 7: Test with ZimraApi Class
echo "STEP 7: Testing with ZimraApi Class\n";
echo str_repeat("-", 50) . "\n";
$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);
echo "✓ API client initialized with certificate\n";

// Test getStatus
echo "\n  Testing getStatus...\n";
try {
    $status = $api->getStatus($deviceId);
    echo "  ✓✓✓ SUCCESS! ✓✓✓\n";
    echo "    Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "  ✗ FAILED: " . $e->getMessage() . "\n";
}

// Test getConfig
echo "\n  Testing getConfig...\n";
try {
    $config = $api->getConfig($deviceId);
    echo "  ✓✓✓ SUCCESS! ✓✓✓\n";
    echo "    Operating Mode: " . ($config['deviceOperatingMode'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "  ✗ FAILED: " . $e->getMessage() . "\n";
}

// Test ping
echo "\n  Testing ping...\n";
try {
    $ping = $api->ping($deviceId);
    echo "  ✓✓✓ SUCCESS! ✓✓✓\n";
    echo "    Reporting Frequency: " . ($ping['reportingFrequency'] ?? 'N/A') . " minutes\n";
} catch (Exception $e) {
    echo "  ✗ FAILED: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";

