<?php
/**
 * Verify fiscal status for all branches - get status from ZIMRA
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== VERIFYING FISCAL STATUS FOR ALL BRANCHES ===\n\n";

// Get all branches
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");

foreach ($branches as $branch) {
    echo "Branch: {$branch['branch_name']} (ID: {$branch['id']})\n";
    echo "Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'Yes' : 'No') . "\n";
    
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE branch_id = ? AND is_active = 1",
        [$branch['id']]
    );
    
    if (!$device) {
        echo "  ⚠ No fiscal device configured\n\n";
        continue;
    }
    
    echo "Device ID: {$device['device_id']}\n";
    echo "Serial: {$device['device_serial_no']}\n";
    echo "Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
    echo "Has Certificate: " . (!empty($device['certificate_pem']) ? 'Yes' : 'No') . "\n";
    
    if (!$device['is_registered'] || empty($device['certificate_pem'])) {
        echo "  ⚠ Device not registered or missing certificate\n\n";
        continue;
    }
    
    // Get status from ZIMRA
    try {
        $fiscalService = new FiscalService($branch['id']);
        $status = $fiscalService->getFiscalDayStatus();
        
        if ($status && isset($status['fiscalDayStatus'])) {
            echo "\nZIMRA Status:\n";
            echo "  Fiscal Day Status: {$status['fiscalDayStatus']}\n";
            if (isset($status['lastFiscalDayNo'])) {
                echo "  Last Fiscal Day No: {$status['lastFiscalDayNo']}\n";
            }
            if (isset($status['lastReceiptGlobalNo'])) {
                echo "  Last Receipt Global No: {$status['lastReceiptGlobalNo']}\n";
            }
            
            $isReady = ($status['fiscalDayStatus'] === 'FiscalDayOpened' || $status['fiscalDayStatus'] === 'FiscalDayCloseFailed');
            echo "\n  Status: " . ($isReady ? "✓ READY to accept fiscal receipts" : "⚠ NOT READY") . "\n";
        } else {
            echo "  ⚠ Could not get status from ZIMRA\n";
        }
    } catch (Exception $e) {
        echo "  ✗ ERROR: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat("-", 60) . "\n\n";
}

