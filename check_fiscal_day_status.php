<?php
/**
 * Simple script to check current fiscal day status from ZIMRA
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$deviceId = 30200;
$branchId = 1;

echo "========================================\n";
echo "CHECKING FISCAL DAY STATUS - Device $deviceId\n";
echo "========================================\n\n";

try {
    $db = Database::getPrimaryInstance();
    
    // Ensure device is active
    $device = $db->getRow("SELECT * FROM fiscal_devices WHERE device_id = :device_id AND branch_id = :branch_id", [':device_id' => $deviceId, ':branch_id' => $branchId]);
    if (!$device || !$device['is_active']) {
        echo "ERROR: Device $deviceId is not active for branch $branchId.\n";
        exit;
    }
    
    $fiscalService = new FiscalService($branchId);
    
    // Get fiscal day status from ZIMRA
    echo "Getting status from ZIMRA...\n";
    $status = $fiscalService->getFiscalDayStatus();
    
    echo "\n========================================\n";
    echo "FISCAL DAY STATUS:\n";
    echo "========================================\n";
    echo "Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
    echo "Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n";
    
    if (isset($status['fiscalDayOpened'])) {
        echo "Fiscal Day Opened: " . $status['fiscalDayOpened'] . "\n";
    }
    if (isset($status['fiscalDayClosed'])) {
        echo "Fiscal Day Closed: " . $status['fiscalDayClosed'] . "\n";
    }
    
    echo "\n========================================\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n";
?>

