<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/zimra_certificate.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$api = new ZimraApi('Server', 'v1', true);

// Test both devices
$devices = [
    ['id' => 30199, 'serial' => 'electrox-1', 'key' => '00544726'],
    ['id' => 30200, 'serial' => 'electrox-2', 'key' => '00294543']
];

foreach ($devices as $device) {
    echo "=== Device {$device['id']} ===\n";
    echo "Serial: {$device['serial']}\n";
    echo "Activation Key: {$device['key']}\n\n";
    
    // Verify taxpayer information
    echo "Verifying taxpayer information...\n";
    try {
        $taxpayer = $api->verifyTaxpayerInformation($device['id'], $device['key'], $device['serial']);
        echo "✓ Taxpayer verified:\n";
        echo "  Name: " . ($taxpayer['taxPayerName'] ?? 'N/A') . "\n";
        echo "  TIN: " . ($taxpayer['taxPayerTIN'] ?? 'N/A') . "\n";
        echo "  VAT: " . ($taxpayer['vatNumber'] ?? 'N/A') . "\n\n";
        
        // Device is registered, try to issue new certificate
        echo "Device is registered. Generating CSR for certificate issuance...\n";
        $csrResult = ZimraCertificate::generateCSR($device['serial'], $device['id'], 'ECC');
        
        echo "Attempting to issue new certificate...\n";
        // Note: issueCertificate requires authentication, but we don't have valid cert
        // So we can't actually call it. But we can prepare the CSR.
        echo "  CSR generated (" . strlen($csrResult['csr']) . " bytes)\n";
        echo "  Private key generated (" . strlen($csrResult['privateKey']) . " bytes)\n";
        echo "  ⚠ Cannot issue certificate without current certificate for authentication\n";
        echo "  You need to contact ZIMRA to:\n";
        echo "    1. Get the current certificate for device {$device['id']}, OR\n";
        echo "    2. Reset the device registration so it can be re-registered\n\n";
        
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'DEV01') !== false) {
            echo "✗ Device not found or not active\n";
        } elseif (strpos($e->getMessage(), 'DEV02') !== false) {
            echo "✗ Activation key incorrect or device already registered\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n";
}

echo "=== Summary ===\n";
echo "Both devices are registered with ZIMRA but we don't have valid certificates.\n";
echo "You need to contact ZIMRA to get the certificates for these devices.\n";

