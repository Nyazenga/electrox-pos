<?php
define('APP_PATH', __DIR__);
require_once 'config.php';
require_once 'includes/db.php';

$db = Database::getInstance();

// Check if device 30199 exists
$device = $db->getRow(
    "SELECT * FROM fiscal_devices WHERE device_id = 30199"
);

echo "Device 30199 Status:\n";
if ($device) {
    echo json_encode($device, JSON_PRETTY_PRINT);
} else {
    echo "Device 30199 NOT FOUND in database.\n";
    echo "Need to register this device first.\n";
}


