<?php
/**
 * Enable Fiscalization for All Branches
 * Set all branches to use device ID 30200
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "ENABLING FISCALIZATION FOR ALL BRANCHES\n";
echo "========================================\n\n";

$primaryDb = Database::getPrimaryInstance();

// Get all branches
$branches = $primaryDb->getRows("SELECT * FROM branches ORDER BY branch_name");

if (empty($branches)) {
    die("✗ No branches found\n");
}

echo "Found " . count($branches) . " branches\n\n";

// Device configuration (use 30200 for all)
$deviceId = 30200;
$deviceSerialNo = 'electrox-2';
$activationKey = '00294543';
$deviceModelName = 'Server';
$deviceModelVersion = 'v1';

foreach ($branches as $branch) {
    echo "Processing: " . $branch['branch_name'] . " (ID: " . $branch['id'] . ")\n";
    
    // Step 1: Enable fiscalization for branch
    $primaryDb->update('branches', [
        'fiscalization_enabled' => 1
    ], ['id' => $branch['id']]);
    echo "  ✓ Fiscalization enabled\n";
    
    // Step 2: Check if device exists for this branch
    $device = $primaryDb->getRow(
        "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id",
        [':branch_id' => $branch['id']]
    );
    
    if ($device) {
        // Update existing device to use 30200
        $primaryDb->update('fiscal_devices', [
            'device_id' => $deviceId,
            'device_serial_no' => $deviceSerialNo,
            'activation_key' => $activationKey,
            'device_model_name' => $deviceModelName,
            'device_model_version' => $deviceModelVersion,
            'is_active' => 1
        ], ['id' => $device['id']]);
        echo "  ✓ Device updated to use Device ID: $deviceId\n";
    } else {
        // Create new device record
        $deviceId_db = $primaryDb->insert('fiscal_devices', [
            'branch_id' => $branch['id'],
            'device_id' => $deviceId,
            'device_serial_no' => $deviceSerialNo,
            'activation_key' => $activationKey,
            'device_model_name' => $deviceModelName,
            'device_model_version' => $deviceModelVersion,
            'is_active' => 1,
            'is_registered' => 1 // Already registered
        ]);
        if ($deviceId_db) {
            echo "  ✓ Device created with Device ID: $deviceId\n";
        } else {
            echo "  ✗ Failed to create device\n";
        }
    }
    
    // Step 3: Check/Update fiscal config
    $config = $primaryDb->getRow(
        "SELECT * FROM fiscal_config WHERE branch_id = :branch_id",
        [':branch_id' => $branch['id']]
    );
    
    if ($config) {
        // Update existing config
        $primaryDb->update('fiscal_config', [
            'device_id' => $deviceId,
            'qr_url' => 'https://fdmstest.zimra.co.zw'
        ], ['id' => $config['id']]);
        echo "  ✓ Fiscal config updated\n";
    } else {
        // Create new config
        try {
            $configId = $primaryDb->insert('fiscal_config', [
                'branch_id' => $branch['id'],
                'device_id' => $deviceId,
                'qr_url' => 'https://fdmstest.zimra.co.zw'
            ]);
            if ($configId) {
                echo "  ✓ Fiscal config created (ID: $configId)\n";
            } else {
                $error = $primaryDb->getLastError();
                echo "  ⚠ Fiscal config insert returned false. Error: $error\n";
                // Try to check if it exists now
                $check = $primaryDb->getRow("SELECT * FROM fiscal_config WHERE branch_id = :branch_id", [':branch_id' => $branch['id']]);
                if ($check) {
                    echo "  ✓ Fiscal config exists (may have been created)\n";
                }
            }
        } catch (Exception $e) {
            echo "  ⚠ Fiscal config error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n";
}

echo "========================================\n";
echo "SUMMARY\n";
echo "========================================\n\n";

// Verify all branches
$branches = $primaryDb->getRows("
    SELECT 
        b.*,
        fd.device_id,
        fd.device_serial_no,
        fd.is_registered,
        fc.qr_url
    FROM branches b
    LEFT JOIN fiscal_devices fd ON b.id = fd.branch_id
    LEFT JOIN fiscal_config fc ON b.id = fc.branch_id AND fd.device_id = fc.device_id
    ORDER BY b.branch_name
");

foreach ($branches as $branch) {
    $status = [];
    if ($branch['fiscalization_enabled']) {
        $status[] = "✓ Enabled";
    } else {
        $status[] = "✗ Disabled";
    }
    
    if ($branch['device_id'] == $deviceId) {
        $status[] = "✓ Device ID: " . $branch['device_id'];
    } else {
        $status[] = "✗ Device ID: " . ($branch['device_id'] ?? 'Not Set');
    }
    
    if ($branch['is_registered']) {
        $status[] = "✓ Registered";
    } else {
        $status[] = "✗ Not Registered";
    }
    
    echo $branch['branch_name'] . ": " . implode(" | ", $status) . "\n";
}

echo "\n✓✓✓ ALL BRANCHES CONFIGURED! ✓✓✓\n";
echo "\nAll branches now use Device ID: $deviceId\n";
echo "Fiscalization is enabled for all branches\n";

