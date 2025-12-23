<?php
/**
 * Test different API endpoint formats
 */

$baseUrl = 'https://fdmsapitest.zimra.co.zw';

$endpoints = [
    '/api/verifyTaxpayerInformation',
    '/verifyTaxpayerInformation',
    'api/verifyTaxpayerInformation',
    '/FiscalDeviceGateway/api/verifyTaxpayerInformation',
    '/FiscalDeviceGateway/verifyTaxpayerInformation',
];

$data = [
    'deviceID' => 30199,
    'activationKey' => '00544726',
    'deviceSerialNo' => 'electrox-1'
];

$headers = [
    'Content-Type: application/json',
    'DeviceModelName: Server',
    'DeviceModelVersionNo: v1',
    'Accept: application/json'
];

echo "=== Testing API Endpoints ===\n\n";

foreach ($endpoints as $endpoint) {
    $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
    echo "Testing: $url\n";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "  HTTP Code: $httpCode\n";
    if ($error) {
        echo "  Error: $error\n";
    }
    if ($httpCode == 200) {
        echo "  ✓ SUCCESS!\n";
        $responseData = json_decode($response, true);
        if ($responseData) {
            echo "  Response: " . json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
        }
        break;
    } elseif ($httpCode != 404) {
        echo "  Response: " . substr($response, 0, 200) . "\n";
    }
    echo "\n";
}

