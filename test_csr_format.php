<?php
/**
 * Test CSR format to verify it matches ZIMRA requirements
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_certificate.php';

echo "=== Testing CSR Format ===\n\n";

$deviceSerialNo = 'electrox-1';
$deviceID = 30199;

echo "Device Serial No: $deviceSerialNo\n";
echo "Device ID: $deviceID\n\n";

// Expected CN format: ZIMRA-<SerialNo>-<zero_padded_10_digit_deviceId>
$expectedCN = 'ZIMRA-' . $deviceSerialNo . '-' . str_pad($deviceID, 10, '0', STR_PAD_LEFT);
echo "Expected CN: $expectedCN\n\n";

echo "Generating CSR...\n";
try {
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceID, 'ECC');
    $csr = $csrData['csr'];
    
    echo "✓ CSR generated\n\n";
    
    // Parse CSR to verify format
    $csrResource = openssl_csr_new([], openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));
    
    // Read the CSR we generated
    $csrParsed = openssl_csr_get_subject($csr, false);
    
    if ($csrParsed) {
        echo "CSR Subject Fields:\n";
        echo "  CN (Common Name): " . ($csrParsed['CN'] ?? 'NOT FOUND') . "\n";
        echo "  C (Country): " . ($csrParsed['C'] ?? 'NOT FOUND') . "\n";
        echo "  O (Organization): " . ($csrParsed['O'] ?? 'NOT FOUND') . "\n";
        echo "  S (State): " . ($csrParsed['ST'] ?? $csrParsed['S'] ?? 'NOT FOUND') . "\n";
        echo "\n";
        
        // Verify format
        $cn = $csrParsed['CN'] ?? '';
        if ($cn === $expectedCN) {
            echo "✓ CN format is correct\n";
        } else {
            echo "✗ CN format mismatch!\n";
            echo "  Expected: $expectedCN\n";
            echo "  Got: $cn\n";
        }
        
        if (($csrParsed['C'] ?? '') === 'ZW') {
            echo "✓ Country (C) is correct: ZW\n";
        } else {
            echo "✗ Country (C) is incorrect\n";
        }
        
        if (($csrParsed['O'] ?? '') === 'Zimbabwe Revenue Authority') {
            echo "✓ Organization (O) is correct\n";
        } else {
            echo "✗ Organization (O) is incorrect\n";
        }
        
        $state = $csrParsed['ST'] ?? $csrParsed['S'] ?? '';
        if ($state === 'Zimbabwe') {
            echo "✓ State (S) is correct: Zimbabwe\n";
        } else {
            echo "✗ State (S) is incorrect (got: $state)\n";
        }
    } else {
        echo "⚠ Could not parse CSR to verify subject\n";
    }
    
    // Check PEM format
    echo "\nPEM Format Check:\n";
    if (strpos($csr, '-----BEGIN CERTIFICATE REQUEST-----') !== false) {
        echo "✓ Has BEGIN header\n";
    } else {
        echo "✗ Missing BEGIN header\n";
    }
    
    if (strpos($csr, '-----END CERTIFICATE REQUEST-----') !== false) {
        echo "✓ Has END footer\n";
    } else {
        echo "✗ Missing END footer\n";
    }
    
    // Check for newlines
    $hasNewlines = (strpos($csr, "\n") !== false);
    echo "Has newlines: " . ($hasNewlines ? 'Yes' : 'No') . "\n";
    
    // Show first few lines
    echo "\nCSR (first 5 lines):\n";
    $lines = explode("\n", $csr);
    for ($i = 0; $i < min(5, count($lines)); $i++) {
        echo "  " . ($i+1) . ": " . $lines[$i] . "\n";
    }
    
    // Test JSON encoding
    echo "\nJSON Encoding Test:\n";
    $escapedCSR = str_replace("\n", '\n', $csr);
    $json = json_encode(['certificateRequest' => $escapedCSR]);
    echo "JSON contains \\\\n: " . (strpos($json, '\\\\n') !== false ? 'Yes' : 'No') . "\n";
    echo "JSON first 200 chars: " . substr($json, 0, 200) . "\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";

