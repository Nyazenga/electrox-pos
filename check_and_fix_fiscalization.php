<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

echo "=== Checking Fiscalization Status ===\n\n";

// Check branches
$branches = $primaryDb->getRows("SELECT id, branch_name, fiscalization_enabled FROM branches");

foreach ($branches as $branch) {
    echo "Branch: {$branch['branch_name']} (ID: {$branch['id']})\n";
    echo "  Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'Yes' : 'No') . "\n";
    
    // Check device
    $device = $primaryDb->getRow(
        "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
        [':branch_id' => $branch['id']]
    );
    
    if ($device) {
        echo "  Device ID: {$device['device_id']}\n";
        echo "  Device Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
        echo "  Has Certificate: " . (!empty($device['certificate_pem']) ? 'Yes' : 'No') . "\n";
    } else {
        echo "  No device configured\n";
    }
    
    // Check config
    $config = $primaryDb->getRow(
        "SELECT * FROM fiscal_config WHERE branch_id = :branch_id",
        [':branch_id' => $branch['id']]
    );
    
    if ($config) {
        echo "  Fiscal Config: Yes (Device ID: {$config['device_id']})\n";
    } else {
        echo "  Fiscal Config: No\n";
    }
    
    echo "\n";
}

// Enable fiscalization for all branches
echo "Enabling fiscalization for all branches...\n";
foreach ($branches as $branch) {
    $primaryDb->update('branches', [
        'fiscalization_enabled' => 1
    ], ['id' => $branch['id']]);
    echo "  ✓ Enabled for {$branch['branch_name']}\n";
}

// Ensure all branches use device 30200
echo "\nUpdating all devices to use device 30200...\n";
$devices = $primaryDb->getRows("SELECT * FROM fiscal_devices");
foreach ($devices as $device) {
    $primaryDb->update('fiscal_devices', [
        'device_id' => 30200,
        'device_serial_no' => 'electrox-2',
        'activation_key' => '00294543',
        'is_registered' => 1
    ], ['id' => $device['id']]);
    echo "  ✓ Updated device record {$device['id']}\n";
}

// Ensure fiscal configs use device 30200
echo "\nUpdating fiscal configs to use device 30200...\n";
$configs = $primaryDb->getRows("SELECT * FROM fiscal_config");
foreach ($configs as $config) {
    $primaryDb->update('fiscal_config', [
        'device_id' => 30200
    ], ['id' => $config['id']]);
    echo "  ✓ Updated config {$config['id']}\n";
}

echo "\n=== Fiscalization Enabled for All Branches ===\n";

