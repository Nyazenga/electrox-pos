<?php
/**
 * Verify and fix fiscal device configuration
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

echo "========================================\n";
echo "VERIFYING AND FIXING FISCAL DEVICES\n";
echo "========================================\n\n";

// Device details
$devices = [
    [
        'device_id' => 30199,
        'device_serial_no' => 'electrox-1',
        'activation_key' => '00544726',
        'device_model_name' => 'Server',
        'device_model_version' => 'v1',
        'branch_id' => 1 // Default to branch 1
    ],
    [
        'device_id' => 30200,
        'device_serial_no' => 'electrox-2',
        'activation_key' => '00294543',
        'device_model_name' => 'Server',
        'device_model_version' => 'v1',
        'branch_id' => 1 // Default to branch 1
    ]
];

foreach ($devices as $deviceData) {
    $deviceId = $deviceData['device_id'];
    echo "Checking device $deviceId...\n";
    
    // Check if device exists
    $existing = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
        [':device_id' => $deviceId]
    );
    
    if ($existing) {
        echo "  Device exists in database\n";
        echo "    Branch ID: " . ($existing['branch_id'] ?? 'NULL') . "\n";
        echo "    Active: " . ($existing['is_active'] ?? 'NULL') . "\n";
        echo "    Registered: " . ($existing['is_registered'] ?? 'NULL') . "\n";
        
        // Update to ensure it's configured correctly
        $updateData = [
            'branch_id' => $deviceData['branch_id'],
            'is_active' => 1, // Make it active
            'device_serial_no' => $deviceData['device_serial_no'],
            'activation_key' => $deviceData['activation_key'],
            'device_model_name' => $deviceData['device_model_name'],
            'device_model_version' => $deviceData['device_model_version']
        ];
        
        $db->update('fiscal_devices', $updateData, ['device_id' => $deviceId]);
        echo "  ✓ Updated device configuration\n";
    } else {
        echo "  Device not found, creating...\n";
        $insertData = array_merge($deviceData, [
            'is_active' => 1,
            'is_registered' => 0
        ]);
        $db->insert('fiscal_devices', $insertData);
        echo "  ✓ Created device\n";
    }
    
    echo "\n";
}

// Verify configuration
echo "========================================\n";
echo "VERIFICATION\n";
echo "========================================\n\n";

$allDevices = $db->getRows("SELECT * FROM fiscal_devices ORDER BY device_id");
foreach ($allDevices as $device) {
    echo "Device ID: " . $device['device_id'] . "\n";
    echo "  Branch ID: " . ($device['branch_id'] ?? 'NULL') . "\n";
    echo "  Active: " . ($device['is_active'] ?? 'NULL') . "\n";
    echo "  Registered: " . ($device['is_registered'] ?? 'NULL') . "\n";
    echo "  Serial: " . ($device['device_serial_no'] ?? 'N/A') . "\n";
    echo "\n";
}

// Check for branch 1
echo "Checking devices for branch 1:\n";
$branch1Devices = $db->getRows(
    "SELECT * FROM fiscal_devices WHERE branch_id = 1 AND is_active = 1"
);
if (empty($branch1Devices)) {
    echo "  ✗ NO ACTIVE DEVICES FOUND FOR BRANCH 1!\n";
    echo "  This is why you're getting the error.\n";
} else {
    echo "  ✓ Found " . count($branch1Devices) . " active device(s) for branch 1:\n";
    foreach ($branch1Devices as $device) {
        echo "    - Device ID: " . $device['device_id'] . "\n";
    }
}

echo "\n========================================\n";
echo "DONE\n";
echo "========================================\n";

