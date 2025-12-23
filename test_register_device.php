<?php
/**
 * Test registerDevice endpoint specifically
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';

echo "=== Testing registerDevice Endpoint ===\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$api = new ZimraApi('Server', 'v1', true);

echo "Device ID: $deviceId\n";
echo "Activation Key: $activationKey\n";
echo "Serial No: $deviceSerialNo\n\n";

echo "Generating CSR...\n";
try {
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
    echo "✓ CSR generated successfully\n";
    echo "  CSR Length: " . strlen($csrData['csr']) . " bytes\n";
    echo "  Private Key Length: " . strlen($csrData['privateKey']) . " bytes\n\n";
    
    // Show first few lines of CSR
    $csrLines = explode("\n", $csrData['csr']);
    echo "CSR Preview (first 3 lines):\n";
    for ($i = 0; $i < min(3, count($csrLines)); $i++) {
        echo "  " . $csrLines[$i] . "\n";
    }
    echo "  ...\n\n";
    
    echo "Attempting device registration...\n";
    echo "Endpoint: POST /Public/v1/$deviceId/RegisterDevice\n";
    echo "Headers: DeviceModelName: Server, DeviceModelVersion: v1\n";
    echo "Body: activationKey + certificateRequest (with escaped newlines)\n\n";
    
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    
    echo "✓ SUCCESS! Device registered\n\n";
    echo "Response:\n";
    if (isset($result['certificate'])) {
        echo "  ✓ Certificate received\n";
        echo "  Certificate Length: " . strlen($result['certificate']) . " bytes\n";
        
        // Show first few lines of certificate
        $certLines = explode("\n", $result['certificate']);
        echo "  Certificate Preview (first 3 lines):\n";
        for ($i = 0; $i < min(3, count($certLines)); $i++) {
            echo "    " . $certLines[$i] . "\n";
        }
        echo "    ...\n";
        
        // Save certificate and private key
        $certFile = "certificate_$deviceId.pem";
        $keyFile = "private_key_$deviceId.pem";
        file_put_contents($certFile, $result['certificate']);
        file_put_contents($keyFile, $csrData['privateKey']);
        echo "\n  ✓ Certificate saved to: $certFile\n";
        echo "  ✓ Private key saved to: $keyFile\n";
        
        // Set certificate for future requests
        $api->setCertificate($result['certificate'], $csrData['privateKey']);
        echo "  ✓ Certificate set for authenticated requests\n";
    }
    
    if (isset($result['operationID'])) {
        echo "  Operation ID: " . $result['operationID'] . "\n";
    }
    
    echo "\n✓ Device registration complete!\n";
    echo "\nYou can now test Device endpoints:\n";
    echo "  - getConfig\n";
    echo "  - getStatus\n";
    echo "  - ping\n";
    echo "  - openDay\n";
    echo "  - submitReceipt\n";
    
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n\n";
    
    // Check if it's a known error
    if (strpos($e->getMessage(), '422') !== false) {
        echo "Error Code 422 - Possible reasons:\n";
        echo "  - Device already registered\n";
        echo "  - CSR format issue\n";
        echo "  - Invalid activation key\n";
    } elseif (strpos($e->getMessage(), '400') !== false) {
        echo "Error Code 400 - Bad Request:\n";
        echo "  - Check request format\n";
        echo "  - Verify headers are correct\n";
    } elseif (strpos($e->getMessage(), 'DEV03') !== false) {
        echo "Error Code DEV03 - CSR not in PEM structure:\n";
        echo "  - CSR newlines must be escaped as \\n in JSON\n";
        echo "  - This should be handled automatically by the code\n";
    }
}

echo "\n=== Test Complete ===\n";

