<?php
/**
 * Setup RIDGEWAY branch fiscal device - register, sync config, and open fiscal day
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== SETTING UP RIDGEWAY FISCAL DEVICE ===\n\n";

// Get RIDGEWAY branch
$ridgeway = $db->getRow("SELECT * FROM branches WHERE branch_name = 'RIDGEWAY' LIMIT 1");
if (!$ridgeway) {
    echo "ERROR: RIDGEWAY branch not found!\n";
    exit(1);
}

$ridgewayBranchId = $ridgeway['id'];
echo "RIDGEWAY Branch ID: {$ridgewayBranchId}\n\n";

// Get fiscal device for RIDGEWAY
$device = $db->getRow(
    "SELECT * FROM fiscal_devices WHERE branch_id = ? AND device_id = 30200",
    [$ridgewayBranchId]
);

if (!$device) {
    echo "ERROR: Fiscal device 30200 not found for RIDGEWAY branch!\n";
    exit(1);
}

echo "Device ID: {$device['device_id']}\n";
echo "Serial: {$device['device_serial_no']}\n";
echo "Activation Key: {$device['activation_key']}\n";
echo "Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
echo "Has Certificate: " . (!empty($device['certificate_pem']) ? 'Yes' : 'No') . "\n\n";

try {
    $fiscalService = new FiscalService($ridgewayBranchId);
    
    // Step 1: Register device if not registered
    if (!$device['is_registered'] || empty($device['certificate_pem'])) {
        echo "Step 1: Registering device with ZIMRA...\n";
        try {
            $result = $fiscalService->registerDevice();
            echo "  ✓ Device registered successfully!\n\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'DEV02') !== false || strpos($e->getMessage(), 'already registered') !== false) {
                echo "  ⚠ Device may already be registered: " . $e->getMessage() . "\n";
                echo "  Continuing with next steps...\n\n";
            } else {
                throw $e;
            }
        }
    } else {
        echo "Step 1: Device already registered, skipping registration.\n\n";
    }
    
    // Step 2: Sync configuration
    echo "Step 2: Syncing configuration from ZIMRA...\n";
    try {
        $result = $fiscalService->syncConfig();
        echo "  ✓ Configuration synced successfully!\n\n";
    } catch (Exception $e) {
        echo "  ⚠ Warning: Could not sync config: " . $e->getMessage() . "\n";
        echo "  Continuing...\n\n";
    }
    
    // Step 3: Check fiscal day status
    echo "Step 3: Checking fiscal day status...\n";
    $status = $fiscalService->getFiscalDayStatus();
    if ($status && isset($status['fiscalDayStatus'])) {
        echo "  Current Status: {$status['fiscalDayStatus']}\n";
        if (isset($status['lastFiscalDayNo'])) {
            echo "  Last Fiscal Day No: {$status['lastFiscalDayNo']}\n";
        }
        if (isset($status['lastReceiptGlobalNo'])) {
            echo "  Last Receipt Global No: {$status['lastReceiptGlobalNo']}\n";
        }
        echo "\n";
        
        // Step 4: Open fiscal day if closed
        if ($status['fiscalDayStatus'] === 'FiscalDayClosed') {
            echo "Step 4: Opening fiscal day...\n";
            try {
                $result = $fiscalService->openFiscalDay();
                echo "  ✓ Fiscal day opened successfully! Day No: {$result['fiscalDayNo']}\n\n";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'already open') !== false) {
                    echo "  ⚠ Fiscal day is already open\n\n";
                } else {
                    throw $e;
                }
            }
        } elseif ($status['fiscalDayStatus'] === 'FiscalDayOpened') {
            echo "Step 4: Fiscal day is already open. No action needed.\n\n";
        } else {
            echo "Step 4: Fiscal day status is: {$status['fiscalDayStatus']}. Manual intervention may be required.\n\n";
        }
    } else {
        echo "  ⚠ Could not get fiscal day status from ZIMRA\n\n";
    }
    
    // Final status check
    echo "=== FINAL STATUS ===\n";
    $finalStatus = $fiscalService->getFiscalDayStatus();
    if ($finalStatus && isset($finalStatus['fiscalDayStatus'])) {
        echo "Fiscal Day Status: {$finalStatus['fiscalDayStatus']}\n";
        if (isset($finalStatus['lastFiscalDayNo'])) {
            echo "Fiscal Day No: {$finalStatus['lastFiscalDayNo']}\n";
        }
        echo "Status: " . ($finalStatus['fiscalDayStatus'] === 'FiscalDayOpened' ? '✓ Ready to accept fiscal receipts' : '⚠ Not ready') . "\n";
    } else {
        echo "Could not verify final status\n";
    }
    
} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

