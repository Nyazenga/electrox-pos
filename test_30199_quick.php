<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(15);

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/database.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30199;
$db = Database::getPrimaryInstance();

echo "Quick Test Device 30199\n";
echo "=======================\n\n";

$device = $db->getRow("SELECT * FROM fiscal_devices WHERE device_id = :device_id", [':device_id' => $deviceId]);
if (!$device) {
    die("Device not found\n");
}

echo "Device: " . $device['device_id'] . ", Registered: " . $device['is_registered'] . "\n";

$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData && $device['certificate_pem']) {
    $certData = ['certificate' => $device['certificate_pem'], 'privateKey' => $device['private_key_pem']];
    echo "Using fallback certificate\n";
}

if (!$certData) {
    die("No certificate found\n");
}

echo "Certificate loaded: " . strlen($certData['certificate']) . " bytes\n";

$api = new ZimraApi($device['device_model_name'] ?? 'Server', $device['device_model_version'] ?? 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);

echo "Making API call with 10 second timeout...\n";

// Override timeout for this test
$reflection = new ReflectionClass($api);
$timeoutProperty = $reflection->getProperty('timeout');
$timeoutProperty->setAccessible(true);
$timeoutProperty->setValue($api, 10);

try {
    $start = microtime(true);
    $status = $api->getStatus($deviceId);
    $duration = round((microtime(true) - $start) * 1000, 2);
    
    echo "SUCCESS in {$duration}ms\n";
    echo "Response: " . json_encode($status, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    $duration = round((microtime(true) - $start) * 1000, 2);
    echo "ERROR after {$duration}ms\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Type: " . get_class($e) . "\n";
}

