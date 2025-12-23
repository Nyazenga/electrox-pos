<?php
/**
 * Direct API Test
 * Test ZIMRA API connection directly
 */

$testUrl = 'https://fdmsapitest.zimra.co.zw/api/verifyTaxpayerInformation';

$data = [
    'deviceID' => 30199,
    'activationKey' => '00544726',
    'deviceSerialNo' => 'electrox-1'
];

$ch = curl_init($testUrl);

$headers = [
    'Content-Type: application/json',
    'DeviceModelName: Server',
    'DeviceModelVersionNo: v1',
    'Accept: application/json'
];

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_VERBOSE => true,
]);

$verbose = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verbose);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$curlInfo = curl_getinfo($ch);

rewind($verbose);
$verboseLog = stream_get_contents($verbose);
fclose($verbose);

curl_close($ch);

echo "=== Direct API Test ===\n\n";
echo "URL: $testUrl\n";
echo "HTTP Code: $httpCode\n";
echo "Error: " . ($error ?: 'None') . "\n";
echo "Response Length: " . strlen($response) . " bytes\n";
echo "\nResponse:\n";
echo substr($response, 0, 1000) . "\n";
echo "\nCURL Info:\n";
print_r($curlInfo);
echo "\nVerbose Log:\n";
echo $verboseLog . "\n";

