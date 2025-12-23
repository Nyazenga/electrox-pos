<?php
/**
 * Verify CSR subject using openssl command
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_certificate.php';

echo "=== Verify CSR Subject ===\n\n";

$deviceSerialNo = 'electrox-1';
$deviceID = 30199;

echo "Generating CSR...\n";
$csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceID, 'ECC');
$csr = $csrData['csr'];

// Save CSR to temp file
$tempFile = tempnam(sys_get_temp_dir(), 'csr_') . '.pem';
file_put_contents($tempFile, $csr);

echo "CSR saved to: $tempFile\n\n";

// Use openssl command to verify
echo "Verifying CSR subject using openssl...\n";
$command = "openssl req -in \"$tempFile\" -noout -subject 2>&1";
$output = shell_exec($command);

if ($output) {
    echo "Subject: $output\n";
    
    // Check for expected values
    $expectedCN = 'ZIMRA-' . $deviceSerialNo . '-' . str_pad($deviceID, 10, '0', STR_PAD_LEFT);
    
    if (strpos($output, $expectedCN) !== false) {
        echo "✓ CN is correct: $expectedCN\n";
    } else {
        echo "✗ CN mismatch! Expected: $expectedCN\n";
    }
    
    if (strpos($output, '/C=ZW') !== false || strpos($output, 'C = ZW') !== false) {
        echo "✓ Country (C) is ZW\n";
    } else {
        echo "✗ Country (C) is not ZW\n";
    }
    
    if (strpos($output, 'Zimbabwe Revenue Authority') !== false) {
        echo "✓ Organization (O) is correct\n";
    } else {
        echo "✗ Organization (O) is incorrect\n";
    }
    
    if (strpos($output, '/S=Zimbabwe') !== false || strpos($output, 'S = Zimbabwe') !== false || strpos($output, '/ST=Zimbabwe') !== false) {
        echo "✓ State (S) is Zimbabwe\n";
    } else {
        echo "✗ State (S) is not Zimbabwe\n";
    }
} else {
    echo "⚠ Could not run openssl command\n";
    echo "Trying alternative method...\n";
    
    // Try reading CSR directly
    $csrResource = openssl_csr_read($csr);
    if ($csrResource) {
        $subject = openssl_csr_get_subject($csrResource);
        if ($subject) {
            echo "Subject (from openssl_csr_get_subject):\n";
            print_r($subject);
        }
    }
}

@unlink($tempFile);

echo "\n=== Test Complete ===\n";

