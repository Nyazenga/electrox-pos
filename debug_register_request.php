<?php
/**
 * Debug registerDevice request to see exact JSON being sent
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_certificate.php';

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

echo "=== Debug registerDevice Request ===\n\n";

$csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
$csr = $csrData['csr'];

echo "CSR (first 5 lines):\n";
$lines = explode("\n", $csr);
for ($i = 0; $i < min(5, count($lines)); $i++) {
    echo "  Line " . ($i+1) . ": " . $lines[$i] . "\n";
}
echo "\n";

// Test different escaping methods
echo "=== Test 1: No escaping (json_encode handles it) ===\n";
$data1 = [
    'activationKey' => $activationKey,
    'certificateRequest' => $csr
];
$json1 = json_encode($data1);
echo "JSON (first 300 chars):\n" . substr($json1, 0, 300) . "\n";
echo "Contains \\n: " . (strpos($json1, '\\n') !== false ? 'Yes' : 'No') . "\n";
echo "Contains \\\\n: " . (strpos($json1, '\\\\n') !== false ? 'Yes' : 'No') . "\n\n";

echo "=== Test 2: Replace with '\\n' (single quotes) ===\n";
$csrEscaped = str_replace("\n", '\n', $csr);
$data2 = [
    'activationKey' => $activationKey,
    'certificateRequest' => $csrEscaped
];
$json2 = json_encode($data2);
echo "JSON (first 300 chars):\n" . substr($json2, 0, 300) . "\n";
echo "Contains \\n: " . (strpos($json2, '\\n') !== false ? 'Yes' : 'No') . "\n";
echo "Contains \\\\n: " . (strpos($json2, '\\\\n') !== false ? 'Yes' : 'No') . "\n\n";

echo "=== What Swagger shows ===\n";
echo "Swagger example: '-----BEGIN CERTIFICATE REQUEST-----\\\\n'\n";
echo "This is: backslash + backslash + n in the JSON string\n";
echo "Which means: the JSON contains the literal characters: \\\\n\n\n";

echo "=== Making actual API call with Test 2 (escaped) ===\n";
require_once APP_PATH . '/includes/zimra_api.php';
$api = new ZimraApi('Server', 'v1', true);

// Manually create the request to see what happens
$endpoint = '/Public/v1/' . $deviceId . '/RegisterDevice';
$url = 'https://fdmsapitest.zimra.co.zw' . $endpoint;

$ch = curl_init($url);
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'DeviceModelName: Server',
    'DeviceModelVersion: v1'
];

$requestData = [
    'activationKey' => $activationKey,
    'certificateRequest' => str_replace("\n", '\n', $csr)
];

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($requestData),
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . substr($response, 0, 500) . "\n";

