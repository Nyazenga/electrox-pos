<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

echo "=== Switching All Branches to Device 30199 ===\n\n";

// Update all fiscal_devices to use 30199
$devices = $primaryDb->getRows("SELECT * FROM fiscal_devices");
foreach ($devices as $device) {
    echo "Updating device record ID {$device['id']} (branch {$device['branch_id']})...\n";
    
    $primaryDb->update('fiscal_devices', [
        'device_id' => 30199,
        'device_serial_no' => 'electrox-1',
        'activation_key' => '00544726',
        'is_registered' => 1
    ], ['id' => $device['id']]);
    
    echo "  ✓ Updated to device 30199\n";
}

// Update fiscal_config
$configs = $primaryDb->getRows("SELECT * FROM fiscal_config");
foreach ($configs as $config) {
    echo "Updating fiscal config ID {$config['id']}...\n";
    $primaryDb->update('fiscal_config', [
        'device_id' => 30199
    ], ['id' => $config['id']]);
    echo "  ✓ Updated to device 30199\n";
}

echo "\n✓ All branches now use device 30199\n";
echo "\nNote: You'll need to contact ZIMRA to get the correct certificate for device 30200\n";
echo "      or reset device 30200 registration to use it.\n";

