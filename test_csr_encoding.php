<?php
/**
 * Test CSR encoding for registerDevice
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_certificate.php';

echo "=== Testing CSR Encoding ===\n\n";

$csrData = ZimraCertificate::generateCSR('electrox-1', 30199, 'ECC');
$csr = $csrData['csr'];

echo "Original CSR (first 100 chars):\n";
echo substr($csr, 0, 100) . "\n\n";

// Test 1: Direct json_encode (newlines will become \n in JSON)
echo "Test 1: Direct json_encode\n";
$json1 = json_encode(['certificateRequest' => $csr]);
echo "JSON length: " . strlen($json1) . "\n";
echo "Contains \\n (escaped newline): " . (strpos($json1, '\\n') !== false ? 'Yes' : 'No') . "\n";
echo "Contains actual newline: " . (strpos($json1, "\n") !== false ? 'Yes' : 'No') . "\n";
echo "First 150 chars: " . substr($json1, 0, 150) . "\n\n";

// Test 2: Replace newlines with \n string, then json_encode
echo "Test 2: Replace \\n with \\\\n, then json_encode\n";
$csrEscaped = str_replace("\n", "\\n", $csr);
$json2 = json_encode(['certificateRequest' => $csrEscaped]);
echo "JSON length: " . strlen($json2) . "\n";
echo "Contains \\\\n (double backslash): " . (strpos($json2, '\\\\n') !== false ? 'Yes' : 'No') . "\n";
echo "Contains \\n (single backslash): " . (strpos($json2, '\\n') !== false ? 'Yes' : 'No') . "\n";
echo "First 150 chars: " . substr($json2, 0, 150) . "\n\n";

// Test 3: What Swagger expects - \\n in JSON (backslash + n)
echo "Test 3: What Swagger shows (\\\\n in JSON)\n";
echo "Swagger example shows: '-----BEGIN CERTIFICATE REQUEST-----\\\\n'\n";
echo "This means: backslash + n in the JSON string\n";
echo "So we need: CSR with actual newlines -> replace with '\\n' string -> json_encode -> '\\\\n' in JSON\n\n";

// Test 4: Using single quotes
echo "Test 4: Using single quotes for replacement\n";
$csrEscaped2 = str_replace("\n", '\n', $csr);
$json3 = json_encode(['certificateRequest' => $csrEscaped2]);
echo "JSON length: " . strlen($json3) . "\n";
echo "Contains \\\\n: " . (strpos($json3, '\\\\n') !== false ? 'Yes' : 'No') . "\n";
echo "First 150 chars: " . substr($json3, 0, 150) . "\n\n";

echo "=== Conclusion ===\n";
echo "Based on Swagger, we need \\\\n (double backslash + n) in the JSON.\n";
echo "This means: replace actual newlines with '\\n' string, then json_encode will escape it to '\\\\n'\n";

