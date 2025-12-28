<?php
/**
 * Activate Fiscal Device
 * Sets is_active = 1 for a fiscal device
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('fiscalization.manage');

try {
    $branchId = intval($_POST['branch_id'] ?? 0);
    $deviceId = intval($_POST['device_id'] ?? 0);
    
    if (!$branchId || !$deviceId) {
        throw new Exception('Branch ID and Device ID are required');
    }
    
    $db = Database::getPrimaryInstance();
    
    // Check if device exists
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND device_id = :device_id",
        [':branch_id' => $branchId, ':device_id' => $deviceId]
    );
    
    if (!$device) {
        throw new Exception('Device not found for this branch');
    }
    
    // Check if device is already active
    if ($device['is_active']) {
        throw new Exception('Device is already active');
    }
    
    // Activate the device
    $db->update('fiscal_devices', [
        'is_active' => 1,
        'updated_at' => date('Y-m-d H:i:s')
    ], [
        'branch_id' => $branchId,
        'device_id' => $deviceId
    ]);
    
    // Log the activation
    logActivity($_SESSION['user_id'], 'fiscal_device_activated', [
        'branch_id' => $branchId,
        'device_id' => $deviceId,
        'device_serial' => $device['device_serial_no']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Device activated successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

