<?php
/**
 * Get Applicable Taxes for Branch
 * Returns JSON list of applicable taxes from fiscal_config for a given branch
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$branchId = $_GET['branch_id'] ?? null;

if (!$branchId) {
    echo json_encode(['success' => false, 'error' => 'Branch ID required']);
    exit;
}

try {
    $primaryDb = Database::getPrimaryInstance();
    
    // Get device for branch
    $device = $primaryDb->getRow(
        "SELECT device_id FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1 LIMIT 1",
        [':branch_id' => $branchId]
    );
    
    if (!$device) {
        echo json_encode(['success' => true, 'taxes' => [], 'message' => 'No fiscal device found for this branch']);
        exit;
    }
    
    // Get fiscal config
    $config = $primaryDb->getRow(
        "SELECT applicable_taxes FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
        [':branch_id' => $branchId, ':device_id' => $device['device_id']]
    );
    
    if (!$config || empty($config['applicable_taxes'])) {
        echo json_encode(['success' => true, 'taxes' => [], 'message' => 'No applicable taxes configured for this branch']);
        exit;
    }
    
    $taxes = json_decode($config['applicable_taxes'], true);
    
    if (!is_array($taxes)) {
        echo json_encode(['success' => true, 'taxes' => [], 'message' => 'Invalid tax configuration']);
        exit;
    }
    
    // Return taxes with proper structure
    echo json_encode([
        'success' => true,
        'taxes' => $taxes
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error loading taxes: ' . $e->getMessage()
    ]);
}

