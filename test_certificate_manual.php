<?php
/**
 * Test Certificate Manually (Not from Database)
 * Uses certificate provided directly to check for format issues
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "MANUAL CERTIFICATE TEST (NOT FROM DATABASE)\n";
echo "========================================\n\n";

$deviceId = 30199;

// Certificate provided by user
$certificatePem = <<<'CERT'
-----BEGIN CERTIFICATE-----
MIIEDzCCAvegAwIBAgIIdZevnjDYhiwwDQYJKoZIhvcNAQELBQAwTzELMAkGA1UE
BhMCWlcxIzAhBgNVBAoTGlppbWJhYndlIFJldmVudWUgQXV0aG9yaXR5MRswGQYD
VQQDExJmb3ItZGV2aWNlLXNpZ25pbmcwHhcNMjUxMjE4MTEzOTUxWhcNMjYxMjE4
MTEzOTUxWjBrMQswCQYDVQQGEwJaVzERMA8GA1UECAwIWmltYmFid2UxIzAhBgNV
BAoMGlppbWJhYndlIFJldmVudWUgQXV0aG9yaXR5MSQwIgYDVQQDDBtaSU1SQS1l
bGVjdHJveC0xLTAwMDAwMzAxOTkwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEK
AoIBAQCplXrFnnfcYfwWhNFtFyTFDz4HutZcT7eb98SR5WGRwNDwVcDeNZvqggfP
5ig9Qfriejra3Yz355MnGENPj5OaEA9/vAibTN4gCZz00V2x+mBeo9ZnN4H17S1C
dVi/tQJuLeNvloItWi2R3tIOwTAKL5k9TqkCgjjQbgScQiqMpB/uhoBrK4t06ODy
n6FV4PfnwUhFqcHczvK/WE+NM1lgeXZ5jUnbI2PwG+MJztxIsLCTKifGF9BstDfa
eFXwWKiJ71daXIQwQCyZtfyYl/UZrQCB86Lg2Evf34otUarZLKNXcvJLaEMcHuGf
vJfuR+2fkJgtkULZhN2PZ3wGY8prAgMBAAGjgdIwgc8wCQYDVR0TBAIwADAdBgNV
HQ4EFgQUrw+sjCqJtk/LVGF3X63mc+fmNqMwfgYDVR0jBHcwdYAUU7/avL3rxixS
YklqUei9iWSpTjahSKRGMEQxEjAQBgoJkiaJk/IsZAEZFgJaVzESMBAGCgmSJomT
8ixkARkWAlJBMRowGAYDVQQDExFaSU1SQSBJc3N1aW5nIENBM4ITFAAAATQI/Nxa
pLIgcgAAAAABNDAOBgNVHQ8BAf8EBAMCBeAwEwYDVR0lBAwwCgYIKwYBBQUHAwIw
DQYJKoZIhvcNAQELBQADggEBAJmn9MjuTG8jhJlaQXpRHTilU/DogsEFU/K0BiLN
E3fBzI3aNO5QlvcDih8pa/JyoEgjT/uShquEKJaY6GBf4Y3HHTDkq7H67oYskMSh
Nhdyz2oBoPGVoSADSQ6mAtrWDqF3YncHU/fXcZJCco4JebOc1JIj+65EWuQlEUn1
WJ69fTP21faAwOtaY/JfUlkhQLxveKnEIX935eZnUSn4w7wDW415USBOl2X433SD
McyJZ7HLpprCnFVDWyjE3p1IXtp/6lq/ZuoyBn+xle5yxZf7WB2EhQNyJHjHqweH
KyBtyNC0NRzL5Ezg/SjzoBwcqeSZcXgTZcwMAg11Dm/a8T4=
-----END CERTIFICATE-----
CERT;

// Load private key from file
$keyFile = "private_key_$deviceId.pem";
if (!file_exists($keyFile)) {
    die("✗ Private key file not found: $keyFile\n");
}

$privateKeyPem = file_get_contents($keyFile);
echo "✓ Certificate loaded manually (not from database)\n";
echo "✓ Private key loaded from file\n";
echo "  Certificate length: " . strlen($certificatePem) . " bytes\n";
echo "  Private key length: " . strlen($privateKeyPem) . " bytes\n\n";

// Verify certificate format
echo "Verifying certificate format...\n";
$cert = openssl_x509_read($certificatePem);
if (!$cert) {
    die("✗ Failed to parse certificate: " . openssl_error_string() . "\n");
}

$details = openssl_x509_parse($cert);
echo "✓ Certificate parsed successfully\n";
echo "  Subject CN: " . ($details['subject']['CN'] ?? 'N/A') . "\n";
echo "  Issuer: " . ($details['issuer']['CN'] ?? 'N/A') . "\n";
echo "  Valid To: " . (isset($details['validTo_time_t']) ? date('Y-m-d H:i:s', $details['validTo_time_t']) : 'N/A') . "\n\n";

// Compare with database certificate
echo "Comparing with database certificate...\n";
$primaryDb = Database::getPrimaryInstance();
$dbDevice = $primaryDb->getRow(
    "SELECT certificate_pem FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if ($dbDevice && $dbDevice['certificate_pem']) {
    $dbCert = trim($dbDevice['certificate_pem']);
    $manualCert = trim($certificatePem);
    
    echo "  Database cert length: " . strlen($dbCert) . " bytes\n";
    echo "  Manual cert length: " . strlen($manualCert) . " bytes\n";
    
    if ($dbCert === $manualCert) {
        echo "  ✓ Certificates are IDENTICAL\n";
    } else {
        echo "  ⚠ Certificates are DIFFERENT\n";
        echo "  First 100 chars match: " . (substr($dbCert, 0, 100) === substr($manualCert, 0, 100) ? 'Yes' : 'No') . "\n";
        echo "  Last 100 chars match: " . (substr($dbCert, -100) === substr($manualCert, -100) ? 'Yes' : 'No') . "\n";
        
        // Check for whitespace differences
        $dbCertClean = preg_replace('/\s+/', '', $dbCert);
        $manualCertClean = preg_replace('/\s+/', '', $manualCert);
        if ($dbCertClean === $manualCertClean) {
            echo "  ⚠ Only whitespace differences (certificates are same, just formatting)\n";
        } else {
            echo "  ✗ Certificates are actually different\n";
        }
    }
}
echo "\n";

// Test with manual certificate
echo "========================================\n";
echo "TESTING WITH MANUAL CERTIFICATE\n";
echo "========================================\n\n";

// Test 1: Direct cURL
echo "Test 1: Direct cURL with manual certificate\n";
echo str_repeat("-", 50) . "\n";

$certFile = tempnam(sys_get_temp_dir(), 'manual_cert_') . '.pem';
$keyFile = tempnam(sys_get_temp_dir(), 'manual_key_') . '.pem';

file_put_contents($certFile, $certificatePem);
file_put_contents($keyFile, $privateKeyPem);

$url = 'https://fdmsapitest.zimra.co.zw/Device/v1/' . $deviceId . '/GetStatus';
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

echo "  HTTP Code: $httpCode\n";
if ($curlError) {
    echo "  cURL Error: $curlError\n";
}

if ($httpCode == 200) {
    echo "  ✓✓✓ SUCCESS WITH MANUAL CERTIFICATE! ✓✓✓\n";
    echo "  Response: " . substr($response, 0, 300) . "\n";
} elseif ($httpCode == 401) {
    echo "  ✗ Still 401 Unauthorized with manual certificate\n";
    echo "  Response: " . substr($response, 0, 300) . "\n";
    
    $errorData = json_decode($response, true);
    if ($errorData) {
        echo "\n  Error: " . ($errorData['title'] ?? 'N/A') . "\n";
        echo "  Detail: " . ($errorData['detail'] ?? 'N/A') . "\n";
    }
} else {
    echo "  ⚠ HTTP Code: $httpCode\n";
    echo "  Response: " . substr($response, 0, 300) . "\n";
}

curl_close($ch);
@unlink($certFile);
@unlink($keyFile);
echo "\n";

// Test 2: ZimraApi class
echo "Test 2: ZimraApi class with manual certificate\n";
echo str_repeat("-", 50) . "\n";

$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($certificatePem, $privateKeyPem);
echo "✓ API client initialized with manual certificate\n";

try {
    $status = $api->getStatus($deviceId);
    echo "✓✓✓ getStatus SUCCESS! ✓✓✓\n";
    echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "  Last Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "✗ getStatus FAILED: " . $e->getMessage() . "\n";
}

try {
    $config = $api->getConfig($deviceId);
    echo "✓✓✓ getConfig SUCCESS! ✓✓✓\n";
    echo "  Operating Mode: " . ($config['deviceOperatingMode'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "✗ getConfig FAILED: " . $e->getMessage() . "\n";
}

try {
    $ping = $api->ping($deviceId);
    echo "✓✓✓ ping SUCCESS! ✓✓✓\n";
    echo "  Reporting Frequency: " . ($ping['reportingFrequency'] ?? 'N/A') . " minutes\n";
} catch (Exception $e) {
    echo "✗ ping FAILED: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";

