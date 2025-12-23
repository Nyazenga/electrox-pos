<?php
/**
 * Generate CSR for Swagger Testing
 * Run this script to generate a Certificate Signing Request for manual testing
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_certificate.php';

echo "=== ZIMRA CSR Generator for Swagger Testing ===\n\n";

// Device configurations
$devices = [
    [
        'name' => 'Head Office',
        'device_id' => 30199,
        'serial_no' => 'electrox-1',
        'activation_key' => '00544726'
    ],
    [
        'name' => 'Hillside',
        'device_id' => 30200,
        'serial_no' => 'electrox-2',
        'activation_key' => '00294543'
    ]
];

foreach ($devices as $device) {
    echo "--- {$device['name']} ---\n";
    echo "Device ID: {$device['device_id']}\n";
    echo "Serial No: {$device['serial_no']}\n";
    echo "Activation Key: {$device['activation_key']}\n\n";
    
    try {
        // Generate CSR (try ECC first, fallback to RSA)
        try {
            $csrData = ZimraCertificate::generateCSR($device['serial_no'], $device['device_id'], 'ECC');
            echo "✓ CSR generated (ECC)\n";
        } catch (Exception $e) {
            echo "⚠ ECC failed, trying RSA: " . $e->getMessage() . "\n";
            $csrData = ZimraCertificate::generateCSR($device['serial_no'], $device['device_id'], 'RSA');
            echo "✓ CSR generated (RSA)\n";
        }
        
        echo "\n--- CSR (Copy this for Swagger) ---\n";
        echo $csrData['csr'];
        echo "\n--- End CSR ---\n\n";
        
        // Save to file
        $filename = "csr_{$device['serial_no']}_{$device['device_id']}.pem";
        file_put_contents($filename, $csrData['csr']);
        echo "✓ CSR saved to: $filename\n";
        
        // Save private key (keep secure!)
        $keyFilename = "private_key_{$device['serial_no']}_{$device['device_id']}.pem";
        file_put_contents($keyFilename, $csrData['privateKey']);
        echo "✓ Private key saved to: $keyFilename (KEEP SECURE!)\n";
        
        echo "\n";
        
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n\n";
    }
}

echo "\n=== Done ===\n";
echo "\nNext Steps:\n";
echo "1. Copy the CSR from above\n";
echo "2. Go to Swagger UI\n";
echo "3. Test 'registerDevice' endpoint\n";
echo "4. Paste the CSR in the 'certificateRequest' field\n";
echo "5. Save the returned certificate for future requests\n";

