<?php
/**
 * Verify and Update Test Devices in Database
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';

$testDevices = [
    [
        'device_id' => 30199,
        'serial' => 'electrox-1',
        'activation_key' => '00544726',
        'branch_id' => 1,
        'device_model_name' => 'Server',
        'device_model_version' => 'v1'
    ],
    [
        'device_id' => 30200,
        'serial' => 'electrox-2',
        'activation_key' => '00294543',
        'branch_id' => 1,
        'device_model_name' => 'Server',
        'device_model_version' => 'v1'
    ]
];

echo "========================================\n";
echo "VERIFYING AND UPDATING TEST DEVICES\n";
echo "========================================\n\n";

$db = Database::getPrimaryInstance();

foreach ($testDevices as $deviceInfo) {
    $deviceId = $deviceInfo['device_id'];
    $serial = $deviceInfo['serial'];
    $activationKey = $deviceInfo['activation_key'];
    $branchId = $deviceInfo['branch_id'];
    
    echo "Device ID: $deviceId (Serial: $serial)\n";
    echo "----------------------------------------\n";
    
    // Check if device exists
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE device_id = :device_id",
        [':device_id' => $deviceId]
    );
    
    if ($device) {
        echo "✓ Device found in database (ID: {$device['id']})\n";
        
        // Update device details
        $updateData = [
            'device_serial_no' => $serial,
            'activation_key' => $activationKey,
            'device_model_name' => $deviceInfo['device_model_name'],
            'device_model_version' => $deviceInfo['device_model_version'],
            'is_active' => 1,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $db->update('fiscal_devices', $updateData, ['id' => $device['id']]);
        echo "✓ Device details updated\n";
        
        // Show current status
        echo "  Serial: {$updateData['device_serial_no']}\n";
        echo "  Activation Key: {$updateData['activation_key']}\n";
        echo "  Model: {$updateData['device_model_name']} {$updateData['device_model_version']}\n";
        echo "  Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
        if ($device['certificate_pem']) {
            echo "  Certificate: Present (" . strlen($device['certificate_pem']) . " bytes)\n";
        } else {
            echo "  Certificate: Not present\n";
        }
    } else {
        echo "✗ Device not found. Creating...\n";
        
        // Create device record
        $deviceIdInserted = $db->insert('fiscal_devices', [
            'branch_id' => $branchId,
            'device_id' => $deviceId,
            'device_serial_no' => $serial,
            'activation_key' => $activationKey,
            'device_model_name' => $deviceInfo['device_model_name'],
            'device_model_version' => $deviceInfo['device_model_version'],
            'is_active' => 1,
            'is_registered' => 0,
            'operating_mode' => 'Online',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        if ($deviceIdInserted) {
            echo "✓ Device record created (ID: $deviceIdInserted)\n";
        } else {
            echo "✗ Failed to create device record\n";
        }
    }
    
    echo "\n";
}

echo "========================================\n";
echo "VERIFICATION COMPLETE\n";
echo "========================================\n\n";

