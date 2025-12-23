<?php
/**
 * Export device certificates for Python testing
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';

$deviceId = isset($argv[1]) ? $argv[1] : 30199;
$outputDir = isset($argv[2]) ? $argv[2] : '../zimra-public/test_device_' . $deviceId;

$db = Database::getPrimaryInstance();

$device = $db->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
    [':device_id' => $deviceId]
);

if (!$device) {
    die("Device $deviceId not found\n");
}

if (empty($device['certificate_pem']) || empty($device['private_key_pem'])) {
    die("Device $deviceId does not have certificates. Please register it first.\n");
}

// Create output directory
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Write certificate
$certPath = $outputDir . '/certificate.crt';
file_put_contents($certPath, $device['certificate_pem']);
echo "Certificate exported to: $certPath\n";

// Write private key
$keyPath = $outputDir . '/decrypted_key.key';
file_put_contents($keyPath, $device['private_key_pem']);
echo "Private key exported to: $keyPath\n";

echo "\nDevice Info:\n";
echo "  Device ID: {$device['device_id']}\n";
echo "  Serial No: {$device['device_serial_no']}\n";
echo "  Activation Key: {$device['activation_key']}\n";
echo "  Is Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
echo "\nCertificates exported successfully!\n";

