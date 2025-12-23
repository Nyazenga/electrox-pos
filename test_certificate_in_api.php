<?php
/**
 * Test certificate in API client with detailed debugging
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/db.php';

$deviceId = 30199;

echo "=== Testing Certificate in API Client ===\n\n";

// Load certificate
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    die("✗ No certificate found\n");
}

echo "Certificate loaded:\n";
echo "  Certificate: " . substr($certData['certificate'], 0, 50) . "...\n";
echo "  Private Key: " . substr($certData['privateKey'], 0, 50) . "...\n\n";

// Create API client
$api = new ZimraApi('Server', 'v1', true);

// Set certificate
echo "Setting certificate in API client...\n";
$api->setCertificate($certData['certificate'], $certData['privateKey']);
echo "✓ Certificate set\n\n";

// Test getStatus with verbose cURL
echo "Testing getStatus...\n";

$url = 'https://fdmsapitest.zimra.co.zw/Device/v1/' . $deviceId . '/GetStatus';
$ch = curl_init($url);

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'DeviceModelName: Server',
    'DeviceModelVersion: v1'
];

// Create temp files for certificate
$certFile = tempnam(sys_get_temp_dir(), 'zimra_cert_') . '.pem';
$keyFile = tempnam(sys_get_temp_dir(), 'zimra_key_') . '.pem';

file_put_contents($certFile, $certData['certificate']);
file_put_contents($keyFile, $certData['privateKey']);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSLCERT => $certFile,
    CURLOPT_SSLKEY => $keyFile,
    CURLOPT_SSLCERTTYPE => 'PEM',
    CURLOPT_SSLKEYTYPE => 'PEM',
    CURLOPT_VERBOSE => true,
    CURLOPT_STDERR => fopen('php://temp', 'w+')
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$curlInfo = curl_getinfo($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "cURL Error: $error\n";
}
echo "Response: " . substr($response, 0, 500) . "\n";

// Check certificate file
echo "\nCertificate file check:\n";
echo "  File exists: " . (file_exists($certFile) ? 'Yes' : 'No') . "\n";
echo "  File size: " . filesize($certFile) . " bytes\n";
echo "  File readable: " . (is_readable($certFile) ? 'Yes' : 'No') . "\n";

echo "\nPrivate key file check:\n";
echo "  File exists: " . (file_exists($keyFile) ? 'Yes' : 'No') . "\n";
echo "  File size: " . filesize($keyFile) . " bytes\n";
echo "  File readable: " . (is_readable($keyFile) ? 'Yes' : 'No') . "\n";

curl_close($ch);
@unlink($certFile);
@unlink($keyFile);

if ($httpCode == 200) {
    echo "\n✓ SUCCESS!\n";
} else {
    echo "\n✗ FAILED\n";
}

