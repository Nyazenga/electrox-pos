<?php
/**
 * Register all fiscal devices and open fiscal days
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== REGISTERING FISCAL DEVICES ===\n\n";

$branches = $db->getRows("SELECT * FROM branches WHERE fiscalization_enabled = 1 ORDER BY branch_name");

foreach ($branches as $branch) {
    echo "Branch: {$branch['branch_name']} (ID: {$branch['id']})\n";
    
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
    echo "Activation Key: {$device['activation_key']}\n";
    
    if ($device['is_registered'] && !empty($device['certificate_pem'])) {
        echo "  ✓ Device already registered\n\n";
        
        // Check fiscal day status and open if needed
        try {
            $fiscalService = new FiscalService($branch['id']);
            $status = $fiscalService->getFiscalDayStatus();
            
            if ($status && isset($status['fiscalDayStatus'])) {
                if ($status['fiscalDayStatus'] === 'FiscalDayClosed') {
                    echo "  Opening fiscal day...\n";
                    $result = $fiscalService->openFiscalDay();
                    echo "  ✓ Fiscal day opened (Day No: {$result['fiscalDayNo']})\n";
                } else {
                    echo "  Fiscal day status: {$status['fiscalDayStatus']}\n";
                }
            }
        } catch (Exception $e) {
            echo "  ⚠ Could not check/open fiscal day: " . $e->getMessage() . "\n";
        }
        echo "\n";
        continue;
    }
    
    // Register device
    echo "  Registering device with ZIMRA...\n";
    try {
        $fiscalService = new FiscalService($branch['id']);
        $result = $fiscalService->registerDevice();
        echo "  ✓ Device registered successfully!\n";
        
        // Sync config
        echo "  Syncing configuration...\n";
        $fiscalService->syncConfig();
        echo "  ✓ Configuration synced\n";
        
        // Open fiscal day
        echo "  Opening fiscal day...\n";
        $result = $fiscalService->openFiscalDay();
        echo "  ✓ Fiscal day opened (Day No: {$result['fiscalDayNo']})\n";
        
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'DEV02') !== false || strpos($e->getMessage(), 'already registered') !== false) {
            echo "  ⚠ Device may already be registered on ZIMRA: " . $e->getMessage() . "\n";
            echo "  Attempting to sync config and open fiscal day...\n";
            try {
                $fiscalService->syncConfig();
                $status = $fiscalService->getFiscalDayStatus();
                if ($status && $status['fiscalDayStatus'] === 'FiscalDayClosed') {
                    $result = $fiscalService->openFiscalDay();
                    echo "  ✓ Fiscal day opened (Day No: {$result['fiscalDayNo']})\n";
                }
            } catch (Exception $e2) {
                echo "  ✗ Error: " . $e2->getMessage() . "\n";
            }
        } else {
            echo "  ✗ Registration failed: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n" . str_repeat("-", 60) . "\n\n";
}

echo "=== FINAL STATUS CHECK ===\n\n";
require_once __DIR__ . '/verify_all_fiscal_status.php';

