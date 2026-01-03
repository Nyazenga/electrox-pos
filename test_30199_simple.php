<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/database.php';
require_once APP_PATH . '/includes/zimra_api.php';
require_once APP_PATH . '/includes/certificate_storage.php';

$deviceId = 30199;
$db = Database::getPrimaryInstance();

echo "Testing Device 30199 API Call\n";
echo str_repeat("=", 50) . "\n\n";

$device = $db->getRow("SELECT * FROM fiscal_devices WHERE device_id = :device_id", [':device_id' => $deviceId]);
if (!$device) die("Device not found\n");

echo "Device: " . $device['device_id'] . ", Branch: " . $device['branch_id'] . ", Registered: " . $device['is_registered'] . "\n";

$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData && $device['certificate_pem']) {
    $certData = ['certificate' => $device['certificate_pem'], 'privateKey' => $device['private_key_pem']];
    echo "Using fallback certificate\n";
}

if ($certData) {
    $api = new ZimraApi($device['device_model_name'] ?? 'Server', $device['device_model_version'] ?? 'v1', true);
    $api->setCertificate($certData['certificate'], $certData['privateKey']);
    try {
        $status = $api->getStatus($deviceId);
        echo "SUCCESS: " . json_encode($status) . "\n";
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
} else {
    echo "No certificate found\n";
}

