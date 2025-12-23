<?php
/**
 * Test ZIMRA Connection
 * Test script to verify ZIMRA API connectivity and device registration
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';

echo "=== ZIMRA Connection Test ===\n\n";

// Test 1: Verify Taxpayer Information
echo "Test 1: Verify Taxpayer Information\n";
echo "-----------------------------------\n";

$testDevices = [
    ['device_id' => 30199, 'activation_key' => '00544726', 'serial' => 'electrox-1', 'branch' => 'Head Office'],
    ['device_id' => 30200, 'activation_key' => '00294543', 'serial' => 'electrox-2', 'branch' => 'Hillside']
];

$api = new ZimraApi('Server', 'v1', true);

foreach ($testDevices as $device) {
    echo "\nTesting {$device['branch']}:\n";
    echo "  Device ID: {$device['device_id']}\n";
    echo "  Serial No: {$device['serial']}\n";
    
    try {
        $result = $api->verifyTaxpayerInformation(
            $device['device_id'],
            $device['activation_key'],
            $device['serial']
        );
        
        echo "  ✓ SUCCESS!\n";
        echo "  Taxpayer Name: " . ($result['taxPayerName'] ?? 'N/A') . "\n";
        echo "  TIN: " . ($result['taxPayerTIN'] ?? 'N/A') . "\n";
        echo "  VAT Number: " . ($result['vatNumber'] ?? 'N/A') . "\n";
        echo "  Branch Name: " . ($result['deviceBranchName'] ?? 'N/A') . "\n";
        echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
        
    } catch (Exception $e) {
        echo "  ✗ FAILED: " . $e->getMessage() . "\n";
    }
}

// Test 2: Test Certificate Generation
echo "\n\nTest 2: Certificate Generation\n";
echo "-----------------------------------\n";

try {
    $csrData = ZimraCertificate::generateCSR('electrox-1', 30199, 'ECC');
    echo "✓ CSR generated successfully\n";
    echo "  CSR Length: " . strlen($csrData['csr']) . " bytes\n";
    echo "  Private Key Length: " . strlen($csrData['privateKey']) . " bytes\n";
    
    // Verify CSR format
    if (strpos($csrData['csr'], 'BEGIN CERTIFICATE REQUEST') !== false) {
        echo "  ✓ CSR format is valid (PEM)\n";
    } else {
        echo "  ✗ CSR format appears invalid\n";
    }
    
} catch (Exception $e) {
    echo "✗ Certificate generation failed: " . $e->getMessage() . "\n";
}

// Test 3: Test API Ping (requires registered device)
echo "\n\nTest 3: API Ping Test\n";
echo "-----------------------------------\n";
echo "Note: This requires a registered device with valid certificate\n";

$primaryDb = Database::getPrimaryInstance();
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = 30199 AND is_registered = 1 LIMIT 1"
);

if ($device && $device['certificate_pem'] && $device['private_key_pem']) {
    try {
        $api->setCertificate($device['certificate_pem'], $device['private_key_pem']);
        $result = $api->ping($device['device_id']);
        
        echo "✓ Ping successful!\n";
        echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
        echo "  Reporting Frequency: " . ($result['reportingFrequency'] ?? 'N/A') . " minutes\n";
        
    } catch (Exception $e) {
        echo "✗ Ping failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "⚠ Device not registered yet. Register device first to test ping.\n";
}

echo "\n\n=== Test Complete ===\n";

