<?php
/**
 * Add detailed logging to certificate operations
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/certificate_storage.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/db.php';

$deviceId = 30199;

echo "=== Certificate Operations with Logging ===\n\n";

// Load certificate
echo "1. Loading certificate...\n";
$certData = CertificateStorage::loadCertificate($deviceId);
if ($certData) {
    echo "✓ Certificate loaded\n";
    echo "  Certificate: " . substr($certData['certificate'], 0, 80) . "...\n";
    echo "  Private Key: " . substr($certData['privateKey'], 0, 80) . "...\n";
    
    // Verify files can be created
    echo "\n2. Testing certificate file creation...\n";
    $certFile = tempnam(sys_get_temp_dir(), 'test_cert_') . '.pem';
    $keyFile = tempnam(sys_get_temp_dir(), 'test_key_') . '.pem';
    
    $certWritten = file_put_contents($certFile, $certData['certificate']);
    $keyWritten = file_put_contents($keyFile, $certData['privateKey']);
    
    echo "  Certificate file: " . ($certWritten ? "✓ Written ($certWritten bytes)" : "✗ Failed") . "\n";
    echo "  Private key file: " . ($keyWritten ? "✓ Written ($keyWritten bytes)" : "✗ Failed") . "\n";
    
    // Test API
    echo "\n3. Testing API with certificate...\n";
    $api = new ZimraApi('Server', 'v1', true);
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    
    // Make a direct cURL call to see what's happening
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
        CURLOPT_VERBOSE => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $sslVerifyResult = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
    
    echo "  HTTP Code: $httpCode\n";
    if ($curlError) {
        echo "  cURL Error: $curlError\n";
    }
    echo "  SSL Verify Result: $sslVerifyResult\n";
    echo "  Response: " . substr($response, 0, 200) . "\n";
    
    if ($httpCode == 200) {
        echo "\n✓ SUCCESS!\n";
    } else {
        echo "\n✗ FAILED\n";
        
        // Check if it's a certificate issue
        if ($httpCode == 401) {
            echo "\nPossible issues:\n";
            echo "1. Certificate may be revoked\n";
            echo "2. Certificate may not match device ID\n";
            echo "3. Certificate may be expired (but we checked - it's valid)\n";
            echo "4. ZIMRA server may have certificate validation issues\n";
        }
    }
    
    curl_close($ch);
    @unlink($certFile);
    @unlink($keyFile);
} else {
    echo "✗ No certificate found\n";
}

