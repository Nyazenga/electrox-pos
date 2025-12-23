<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30200;

// Load certificate
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    echo "✗ Certificate not found\n";
    exit(1);
}

echo "Certificate loaded: " . strlen($certData['certificate']) . " bytes\n";
echo "Private key loaded: " . strlen($certData['privateKey']) . " bytes\n\n";

// Initialize API
$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);

// Test getStatus first
echo "Testing getStatus...\n";
try {
    $status = $api->getStatus($deviceId);
    echo "✓ getStatus successful\n";
    echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    if (isset($status['lastFiscalDayNo'])) {
        echo "  Last Fiscal Day No: " . $status['lastFiscalDayNo'] . "\n";
    }
    echo "\n";
    
    // If day is already open, we're done
    if (isset($status['fiscalDayStatus']) && $status['fiscalDayStatus'] === 'FiscalDayOpened') {
        echo "✓ Fiscal day is already open!\n";
        exit(0);
    }
    
} catch (Exception $e) {
    echo "✗ getStatus failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Now try openDay
echo "Testing openDay...\n";
$fiscalDayOpened = date('Y-m-d\TH:i:s');
echo "Date: $fiscalDayOpened\n";

try {
    $response = $api->openDay($deviceId, $fiscalDayOpened);
    echo "✓ openDay successful!\n";
    print_r($response);
} catch (Exception $e) {
    echo "✗ openDay failed: " . $e->getMessage() . "\n";
}

