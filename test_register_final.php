<?php
/**
 * Final test of registerDevice with detailed debugging
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';

echo "=== Final registerDevice Test ===\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$api = new ZimraApi('Server', 'v1', true);

echo "1. Generating CSR...\n";
$csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
$csr = $csrData['csr'];

// Clean CSR - remove any trailing whitespace/newlines
$csr = trim($csr);
// Ensure it ends with proper footer
if (substr($csr, -strlen("-----END CERTIFICATE REQUEST-----")) !== "-----END CERTIFICATE REQUEST-----") {
    $csr .= "\n-----END CERTIFICATE REQUEST-----";
}

echo "   CSR Length: " . strlen($csr) . " bytes\n";
echo "   Has BEGIN: " . (strpos($csr, '-----BEGIN CERTIFICATE REQUEST-----') !== false ? 'Yes' : 'No') . "\n";
echo "   Has END: " . (strpos($csr, '-----END CERTIFICATE REQUEST-----') !== false ? 'Yes' : 'No') . "\n\n";

echo "2. Testing different CSR encoding methods...\n\n";

// Method 1: No escaping (let json_encode handle it)
echo "Method 1: Direct CSR (json_encode will escape newlines to \\n)\n";
$data1 = [
    'activationKey' => $activationKey,
    'certificateRequest' => $csr
];
$json1 = json_encode($data1);
echo "   JSON contains \\n (single): " . (strpos($json1, '\\n') !== false && strpos($json1, '\\\\n') === false ? 'Yes' : 'No') . "\n";
echo "   JSON contains \\\\n (double): " . (strpos($json1, '\\\\n') !== false ? 'Yes' : 'No') . "\n";
echo "   First 250 chars: " . substr($json1, 0, 250) . "\n\n";

// Method 2: Pre-escape with single quotes
echo "Method 2: Pre-escape with '\\n' (single quotes)\n";
$csrEscaped = str_replace("\n", '\n', $csr);
$data2 = [
    'activationKey' => $activationKey,
    'certificateRequest' => $csrEscaped
];
$json2 = json_encode($data2);
echo "   JSON contains \\n (single): " . (strpos($json2, '\\n') !== false && strpos($json2, '\\\\n') === false ? 'Yes' : 'No') . "\n";
echo "   JSON contains \\\\n (double): " . (strpos($json2, '\\\\n') !== false ? 'Yes' : 'No') . "\n";
echo "   First 250 chars: " . substr($json2, 0, 250) . "\n\n";

// Method 3: Base64 encode (just to test)
echo "Method 3: Base64 encode (not what we want, but testing)\n";
$csrBase64 = base64_encode($csr);
echo "   Base64 length: " . strlen($csrBase64) . "\n\n";

echo "3. Making API call with Method 1 (direct CSR)...\n";
try {
    // Temporarily modify registerDevice to use direct CSR
    $endpoint = '/Public/v1/' . $deviceId . '/RegisterDevice';
    $url = 'https://fdmsapitest.zimra.co.zw' . $endpoint;
    
    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'DeviceModelName: Server',
        'DeviceModelVersion: v1'
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $json1, // Method 1 - direct
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "   HTTP Code: $httpCode\n";
    if ($httpCode == 200) {
        echo "   ✓ SUCCESS!\n";
        $result = json_decode($response, true);
        if (isset($result['certificate'])) {
            echo "   Certificate received!\n";
        }
    } else {
        $error = json_decode($response, true);
        echo "   ✗ FAILED\n";
        echo "   Error: " . ($error['detail'] ?? $response) . "\n";
        echo "   Error Code: " . ($error['errorCode'] ?? 'N/A') . "\n";
        
        if ($httpCode == 422 && ($error['errorCode'] ?? '') == 'DEV03') {
            echo "\n   Trying Method 2 (pre-escaped)...\n";
            
            $ch2 = curl_init($url);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $json2, // Method 2 - pre-escaped
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            
            $response2 = curl_exec($ch2);
            $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
            
            echo "   HTTP Code: $httpCode2\n";
            if ($httpCode2 == 200) {
                echo "   ✓ SUCCESS with Method 2!\n";
            } else {
                $error2 = json_decode($response2, true);
                echo "   ✗ FAILED with Method 2\n";
                echo "   Error: " . ($error2['detail'] ?? $response2) . "\n";
            }
        }
    }
} catch (Exception $e) {
    echo "   ✗ Exception: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";

