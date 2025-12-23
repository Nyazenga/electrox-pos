<?php
/**
 * Update branches and fiscal devices according to requirements
 * - Update HEAD OFFICE to BELGRAVIA
 * - Update HILLSIDE to RIDGEWAY
 * - Configure fiscal devices
 * - Delete unwanted branches/devices
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== UPDATING BRANCHES AND FISCAL DEVICES ===\n\n";

// Get all branches
$allBranches = $db->getRows("SELECT * FROM branches ORDER BY id");
echo "Current branches:\n";
foreach ($allBranches as $b) {
    echo "  ID: {$b['id']}, Name: {$b['branch_name']}\n";
}
echo "\n";

// Get all fiscal devices
$allDevices = $db->getRows("SELECT * FROM fiscal_devices ORDER BY id");
echo "Current fiscal devices:\n";
foreach ($allDevices as $d) {
    echo "  ID: {$d['id']}, Branch ID: {$d['branch_id']}, Device ID: {$d['device_id']}, Serial: {$d['device_serial_no']}\n";
}
echo "\n";

try {
    $db->beginTransaction();
    
    // Find HEAD OFFICE branch
    $headOffice = $db->getRow("SELECT * FROM branches WHERE branch_name LIKE '%HEAD%OFFICE%' OR branch_name LIKE '%HEAD%' LIMIT 1");
    if ($headOffice) {
        echo "Updating HEAD OFFICE to BELGRAVIA...\n";
        $db->update('branches', [
            'branch_name' => 'BELGRAVIA',
            'branch_code' => 'BELGRAVIA',
            'fiscalization_enabled' => 1
        ], ['id' => $headOffice['id']]);
        $belgraviaBranchId = $headOffice['id'];
        echo "  Updated branch ID {$headOffice['id']} to BELGRAVIA\n";
    } else {
        // Check if BELGRAVIA already exists
        $belgravia = $db->getRow("SELECT * FROM branches WHERE branch_name = 'BELGRAVIA' LIMIT 1");
        if ($belgravia) {
            $belgraviaBranchId = $belgravia['id'];
            echo "BELGRAVIA branch already exists (ID: {$belgraviaBranchId})\n";
        } else {
            // Create BELGRAVIA branch
            echo "Creating BELGRAVIA branch...\n";
            $belgraviaBranchId = $db->insert('branches', [
                'branch_name' => 'BELGRAVIA',
                'branch_code' => 'BELGRAVIA',
                'fiscalization_enabled' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            echo "  Created BELGRAVIA branch with ID: {$belgraviaBranchId}\n";
        }
    }
    
    // Find HILLSIDE branch
    $hillside = $db->getRow("SELECT * FROM branches WHERE branch_name LIKE '%HILLSIDE%' LIMIT 1");
    if ($hillside) {
        echo "Updating HILLSIDE to RIDGEWAY...\n";
        $db->update('branches', [
            'branch_name' => 'RIDGEWAY',
            'branch_code' => 'RIDGEWAY',
            'fiscalization_enabled' => 1
        ], ['id' => $hillside['id']]);
        $ridgewayBranchId = $hillside['id'];
        echo "  Updated branch ID {$hillside['id']} to RIDGEWAY\n";
    } else {
        // Check if RIDGEWAY already exists
        $ridgeway = $db->getRow("SELECT * FROM branches WHERE branch_name = 'RIDGEWAY' LIMIT 1");
        if ($ridgeway) {
            $ridgewayBranchId = $ridgeway['id'];
            echo "RIDGEWAY branch already exists (ID: {$ridgewayBranchId})\n";
        } else {
            // Create RIDGEWAY branch
            echo "Creating RIDGEWAY branch...\n";
            $ridgewayBranchId = $db->insert('branches', [
                'branch_name' => 'RIDGEWAY',
                'branch_code' => 'RIDGEWAY',
                'fiscalization_enabled' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            echo "  Created RIDGEWAY branch with ID: {$ridgewayBranchId}\n";
        }
    }
    
    // Delete any other branches (except BELGRAVIA and RIDGEWAY)
    $otherBranches = $db->getRows("SELECT * FROM branches WHERE id NOT IN (?, ?)", [$belgraviaBranchId, $ridgewayBranchId]);
    if (!empty($otherBranches)) {
        echo "\nDeleting unwanted branches:\n";
        foreach ($otherBranches as $other) {
            echo "  Deleting branch: {$other['branch_name']} (ID: {$other['id']})\n";
            // Delete related fiscal devices first
            $pdo = $db->getPdo();
            $pdo->exec("DELETE FROM fiscal_devices WHERE branch_id = {$other['id']}");
            $pdo->exec("DELETE FROM fiscal_config WHERE branch_id = {$other['id']}");
            $pdo->exec("DELETE FROM fiscal_days WHERE branch_id = {$other['id']}");
            // Delete branch
            $pdo->exec("DELETE FROM branches WHERE id = {$other['id']}");
        }
    }
    
    // First, delete ALL existing fiscal devices for these branches to start clean
    echo "\nCleaning up existing fiscal devices...\n";
    $pdo = $db->getPdo();
    $pdo->exec("DELETE FROM fiscal_config WHERE branch_id IN ($belgraviaBranchId, $ridgewayBranchId)");
    $pdo->exec("DELETE FROM fiscal_devices WHERE branch_id IN ($belgraviaBranchId, $ridgewayBranchId)");
    echo "  Deleted all existing fiscal devices for BELGRAVIA and RIDGEWAY\n";
    
    // Configure BELGRAVIA fiscal device (30199)
    echo "\nConfiguring BELGRAVIA fiscal device...\n";
    $belgraviaDeviceData = [
        'branch_id' => $belgraviaBranchId,
        'device_id' => 30199,
        'device_serial_no' => 'electrox-1',
        'activation_key' => '00544726',
        'device_model_name' => 'Server',
        'device_model_version' => 'v1',
        'is_active' => 1
    ];
    
    $db->insert('fiscal_devices', $belgraviaDeviceData);
    echo "  Created fiscal device for BELGRAVIA (Device ID: 30199, Serial: electrox-1, Key: 00544726)\n";
    
    // Configure RIDGEWAY fiscal device (30200)
    echo "Configuring RIDGEWAY fiscal device...\n";
    $ridgewayDeviceData = [
        'branch_id' => $ridgewayBranchId,
        'device_id' => 30200,
        'device_serial_no' => 'electrox-2',
        'activation_key' => '00294543',
        'device_model_name' => 'Server',
        'device_model_version' => 'v1',
        'is_active' => 1
    ];
    
    $db->insert('fiscal_devices', $ridgewayDeviceData);
    echo "  Created fiscal device for RIDGEWAY (Device ID: 30200, Serial: electrox-2, Key: 00294543)\n";
    
    // Delete any other fiscal devices not assigned to BELGRAVIA or RIDGEWAY
    $otherDevices = $db->getRows(
        "SELECT * FROM fiscal_devices WHERE branch_id NOT IN (?, ?)",
        [$belgraviaBranchId, $ridgewayBranchId]
    );
    if (!empty($otherDevices)) {
        echo "\nDeleting unwanted fiscal devices from other branches:\n";
        $pdo = $db->getPdo();
        foreach ($otherDevices as $other) {
            echo "  Deleting device: Device ID {$other['device_id']} (Branch ID: {$other['branch_id']})\n";
            $pdo->exec("DELETE FROM fiscal_config WHERE device_id = {$other['device_id']} AND branch_id = {$other['branch_id']}");
            $pdo->exec("DELETE FROM fiscal_devices WHERE id = {$other['id']}");
        }
    }
    
    $db->commitTransaction();
    echo "\n=== UPDATE COMPLETE ===\n\n";
    
    // Verify final state
    echo "Final branches:\n";
    $finalBranches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
    foreach ($finalBranches as $b) {
        echo "  ID: {$b['id']}, Name: {$b['branch_name']}, Fiscal Enabled: {$b['fiscalization_enabled']}\n";
    }
    
    echo "\nFinal fiscal devices:\n";
    $finalDevices = $db->getRows("SELECT * FROM fiscal_devices ORDER BY branch_id, device_id");
    foreach ($finalDevices as $d) {
        echo "  Branch ID: {$d['branch_id']}, Device ID: {$d['device_id']}, Serial: {$d['device_serial_no']}, Key: {$d['activation_key']}\n";
    }
    
} catch (Exception $e) {
    $db->rollbackTransaction();
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

