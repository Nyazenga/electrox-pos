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

$device = $db->getRow("SELECT * FROM fiscal_devices WHERE device_id = :device_id", [':device_id' => $deviceId]);
if (!$device) die("Device not found\n");

$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData && $device['certificate_pem']) {
    $certData = ['certificate' => $device['certificate_pem'], 'privateKey' => $device['private_key_pem']];
}

if (!$certData) die("No certificate\n");

$api = new ZimraApi($device['device_model_name'] ?? 'Server', $device['device_model_version'] ?? 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);

// Reduce timeout to 5 seconds
$reflection = new ReflectionClass($api);
$timeoutProperty = $reflection->getProperty('timeout');
$timeoutProperty->setAccessible(true);
$timeoutProperty->setValue($api, 5);

echo "Calling getStatus with 5 second timeout...\n";
flush();

try {
    $status = $api->getStatus($deviceId);
    echo "SUCCESS: " . json_encode($status) . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Type: " . get_class($e) . "\n";
}

