<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30200;

// Load certificate
$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData) {
    echo "✗ Certificate not found\n";
    exit(1);
}

// Initialize API
$api = new ZimraApi('Server', 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);

// First test getStatus to verify authentication works
echo "Testing getStatus (to verify certificate)...\n";
try {
    $status = $api->getStatus($deviceId);
    echo "✓ getStatus successful\n";
    echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    if (isset($status['lastFiscalDayNo'])) {
        echo "  Last Fiscal Day No: " . $status['lastFiscalDayNo'] . "\n";
    }
} catch (Exception $e) {
    echo "✗ getStatus failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";

// Now test openDay with different formats
$fiscalDayOpened = date('Y-m-d\TH:i:s');

echo "Testing openDay with date: $fiscalDayOpened\n";

// Try format 1: Direct fields
echo "\nTrying format 1: Direct fields...\n";
try {
    $endpoint = '/Device/v1/' . $deviceId . '/OpenDay';
    $data = ['fiscalDayOpened' => $fiscalDayOpened];
    $response = $api->makeRequest($endpoint, 'POST', $data, true);
    echo "✓ Success with direct fields!\n";
    print_r($response);
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

// Try format 2: Wrapped in openDayRequest
echo "\nTrying format 2: Wrapped in openDayRequest...\n";
try {
    $endpoint = '/Device/v1/' . $deviceId . '/OpenDay';
    $data = ['openDayRequest' => ['fiscalDayOpened' => $fiscalDayOpened]];
    $response = $api->makeRequest($endpoint, 'POST', $data, true);
    echo "✓ Success with openDayRequest wrapper!\n";
    print_r($response);
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

