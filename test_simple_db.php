<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "Testing database connection...\n";

try {
    $db = Database::getPrimaryInstance();
    echo "✓ Database connected\n";
    
    // Check if fiscal_devices table exists
    $tables = $db->query("SHOW TABLES LIKE 'fiscal_devices'");
    if (count($tables) > 0) {
        echo "✓ fiscal_devices table exists\n";
        
        // Check devices
        $devices = $db->getRows("SELECT * FROM fiscal_devices WHERE device_id IN (30199, 30200)");
        echo "Found " . count($devices) . " test devices\n";
        
        foreach ($devices as $device) {
            echo "  Device {$device['device_id']}: {$device['device_serial_no']}\n";
        }
    } else {
        echo "✗ fiscal_devices table does not exist\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

