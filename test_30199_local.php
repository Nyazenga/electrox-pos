<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(15);

echo "Starting test...\n";
flush();

require_once __DIR__ . '/config.php';
echo "Config loaded\n";
flush();

require_once APP_PATH . '/includes/database.php';
echo "Database loaded\n";
flush();

require_once APP_PATH . '/includes/zimra_api.php';
echo "ZimraApi loaded\n";
flush();

require_once APP_PATH . '/includes/certificate_storage.php';
echo "CertificateStorage loaded\n";
flush();

$deviceId = 30199;
$db = Database::getPrimaryInstance();

echo "Getting device from database...\n";
flush();

$device = $db->getRow("SELECT * FROM fiscal_devices WHERE device_id = :device_id", [':device_id' => $deviceId]);
if (!$device) {
    die("Device not found\n");
}

echo "Device found: " . $device['device_id'] . ", Registered: " . $device['is_registered'] . "\n";
flush();

echo "Loading certificate...\n";
flush();

$certData = CertificateStorage::loadCertificate($deviceId);
if (!$certData && $device['certificate_pem']) {
    echo "CertificateStorage returned null, using fallback...\n";
    flush();
    $certData = ['certificate' => $device['certificate_pem'], 'privateKey' => $device['private_key_pem']];
}

if (!$certData) {
    die("No certificate found\n");
}

echo "Certificate loaded: " . strlen($certData['certificate']) . " bytes\n";
flush();

echo "Initializing API client...\n";
flush();

$api = new ZimraApi($device['device_model_name'] ?? 'Server', $device['device_model_version'] ?? 'v1', true);
$api->setCertificate($certData['certificate'], $certData['privateKey']);

echo "API client initialized\n";
flush();

// Reduce timeout to 5 seconds
$reflection = new ReflectionClass($api);
$timeoutProperty = $reflection->getProperty('timeout');
$timeoutProperty->setAccessible(true);
$timeoutProperty->setValue($api, 5);

echo "Calling getStatus() with 5 second timeout...\n";
echo "This may hang if there's a connection issue...\n";
flush();

$startTime = microtime(true);

try {
    $status = $api->getStatus($deviceId);
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    echo "SUCCESS in {$duration}ms\n";
    echo "Response: " . json_encode($status, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    echo "ERROR after {$duration}ms\n";
    echo "Error Message: " . $e->getMessage() . "\n";
    echo "Error Type: " . get_class($e) . "\n";
    echo "Error Code: " . ($e->getCode() ?: 'N/A') . "\n";
}

echo "Test complete\n";

