<?php
/**
 * Verify Taxpayer Information for Device 30199
 * This endpoint doesn't require a certificate
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . DIRECTORY_SEPARATOR . 'config.php';
require_once APP_PATH . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'zimra_api.php';

$deviceId = 30199;
$activationKey = '00544726';
$deviceSerialNo = 'electrox-1';

echo "=== Verify Taxpayer Information ===\n\n";
echo "Device ID: $deviceId\n";
echo "Activation Key: $activationKey\n";
echo "Serial No: $deviceSerialNo\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $api = new ZimraApi('Server', 'v1', true);
    
    echo "Calling verifyTaxpayerInformation...\n";
    $response = $api->verifyTaxpayerInformation($deviceId, $activationKey, $deviceSerialNo);
    
    echo "\n=== ZIMRA RESPONSE ===\n";
    echo json_encode($response, JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    echo "\n[ERROR]: " . $e->getMessage() . "\n";
    exit(1);
}


