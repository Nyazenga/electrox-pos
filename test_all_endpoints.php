<?php
/**
 * Test All ZIMRA Endpoints
 * Tests all endpoints according to Swagger documentation
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';

echo "=== ZIMRA API Endpoint Testing ===\n\n";

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

$api = new ZimraApi('Server', 'v1', true);

// Test 1: verifyTaxpayerInformation (Public)
echo "Test 1: verifyTaxpayerInformation (Public)\n";
echo "-----------------------------------\n";
try {
    $result = $api->verifyTaxpayerInformation($deviceId, $activationKey, $deviceSerialNo);
    echo "✓ SUCCESS!\n";
    echo "  Taxpayer: " . $result['taxPayerName'] . "\n";
    echo "  TIN: " . $result['taxPayerTIN'] . "\n";
    echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 2: getServerCertificate (Public)
echo "Test 2: getServerCertificate (Public)\n";
echo "-----------------------------------\n";
try {
    $result = $api->getServerCertificate();
    echo "✓ SUCCESS!\n";
    echo "  Certificate thumbprint: " . ($result['thumbprint'] ?? 'N/A') . "\n";
    echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 3: registerDevice (Public) - requires CSR
echo "Test 3: registerDevice (Public)\n";
echo "-----------------------------------\n";
echo "Generating CSR...\n";
try {
    $csrData = ZimraCertificate::generateCSR($deviceSerialNo, $deviceId, 'ECC');
    echo "✓ CSR generated\n";
    
    echo "Attempting device registration...\n";
    $result = $api->registerDevice($deviceId, $activationKey, $csrData['csr']);
    echo "✓ SUCCESS!\n";
    echo "  Certificate received: " . (isset($result['certificate']) ? 'Yes' : 'No') . "\n";
    echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
    
    // Save certificate for future tests
    if (isset($result['certificate'])) {
        $api->setCertificate($result['certificate'], $csrData['privateKey']);
        echo "  ✓ Certificate set for authenticated requests\n";
    }
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    echo "  Note: This is expected if device is already registered\n";
}
echo "\n";

// Test 4: getConfig (Device - requires certificate)
echo "Test 4: getConfig (Device - requires certificate)\n";
echo "-----------------------------------\n";
try {
    $result = $api->getConfig($deviceId);
    echo "✓ SUCCESS!\n";
    echo "  Taxpayer: " . ($result['taxpayerName'] ?? 'N/A') . "\n";
    echo "  Operating Mode: " . ($result['deviceOperatingMode'] ?? 'N/A') . "\n";
    echo "  QR URL: " . ($result['qrUrl'] ?? 'N/A') . "\n";
    echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    echo "  Note: Requires registered device with valid certificate\n";
}
echo "\n";

// Test 5: getStatus (Device - requires certificate)
echo "Test 5: getStatus (Device - requires certificate)\n";
echo "-----------------------------------\n";
try {
    $result = $api->getStatus($deviceId);
    echo "✓ SUCCESS!\n";
    echo "  Fiscal Day Status: " . ($result['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "  Last Fiscal Day No: " . ($result['lastFiscalDayNo'] ?? 'N/A') . "\n";
    echo "  Last Receipt Global No: " . ($result['lastReceiptGlobalNo'] ?? 'N/A') . "\n";
    echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    echo "  Note: Requires registered device with valid certificate\n";
}
echo "\n";

// Test 6: ping (Device - requires certificate)
echo "Test 6: ping (Device - requires certificate)\n";
echo "-----------------------------------\n";
try {
    $result = $api->ping($deviceId);
    echo "✓ SUCCESS!\n";
    echo "  Reporting Frequency: " . ($result['reportingFrequency'] ?? 'N/A') . " minutes\n";
    echo "  Operation ID: " . ($result['operationID'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    echo "  Note: Requires registered device with valid certificate\n";
}
echo "\n";

echo "=== Testing Complete ===\n";
echo "\nSummary:\n";
echo "- Public endpoints (verifyTaxpayerInformation, getServerCertificate, registerDevice) should work\n";
echo "- Device endpoints (getConfig, getStatus, ping) require registered device with certificate\n";
echo "- If device registration fails, device may already be registered\n";

