<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

// Update activation key for device 30199 (Belgravia)
$result = $db->execute(
    'UPDATE fiscal_devices SET activation_key = :key WHERE device_id = :device_id',
    [
        ':key' => '00544726',
        ':device_id' => 30199
    ]
);

if ($result) {
    echo "✓ Activation key updated successfully for device 30199\n";
    $device = $db->getRow("SELECT * FROM fiscal_devices WHERE device_id = 30199");
    echo "Device ID: {$device['device_id']}\n";
    echo "Serial: {$device['device_serial_no']}\n";
    echo "Activation Key: {$device['activation_key']}\n";
    echo "Branch ID: {$device['branch_id']}\n";
} else {
    echo "✗ Failed to update activation key\n";
}

