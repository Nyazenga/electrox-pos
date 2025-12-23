<?php
/**
 * Re-register BELGRAVIA device 30199 after reset
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== RE-REGISTERING BELGRAVIA DEVICE 30199 ===\n\n";

// Get BELGRAVIA branch
$belgravia = $db->getRow("SELECT * FROM branches WHERE branch_name = 'BELGRAVIA' LIMIT 1");
if (!$belgravia) {
    echo "ERROR: BELGRAVIA branch not found!\n";
    exit(1);
}

$belgraviaBranchId = $belgravia['id'];
echo "BELGRAVIA Branch ID: {$belgraviaBranchId}\n\n";

// Get fiscal device for BELGRAVIA
$device = $db->getRow(
    "SELECT * FROM fiscal_devices WHERE branch_id = ? AND device_id = 30199",
    [$belgraviaBranchId]
);

if (!$device) {
    echo "ERROR: Fiscal device 30199 not found for BELGRAVIA branch!\n";
    exit(1);
}

echo "Device ID: {$device['device_id']}\n";
echo "Serial: {$device['device_serial_no']}\n";
echo "Activation Key: {$device['activation_key']}\n";
echo "Current Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
echo "Has Certificate: " . (!empty($device['certificate_pem']) ? 'Yes' : 'No') . "\n\n";

try {
    $fiscalService = new FiscalService($belgraviaBranchId);
    
    // Step 1: Register device
    echo "Step 1: Registering device with ZIMRA...\n";
    try {
        $result = $fiscalService->registerDevice();
        echo "  ✓ Device registered successfully!\n";
        echo "  Certificate stored in database.\n\n";
        
        // Verify certificate was stored
        $updatedDevice = $db->getRow(
            "SELECT * FROM fiscal_devices WHERE branch_id = ? AND device_id = 30199",
            [$belgraviaBranchId]
        );
        if ($updatedDevice && !empty($updatedDevice['certificate_pem'])) {
            echo "  ✓ Certificate verified in database (length: " . strlen($updatedDevice['certificate_pem']) . " bytes)\n\n";
        } else {
            echo "  ⚠ WARNING: Certificate may not have been saved properly\n\n";
        }
        
    } catch (Exception $e) {
        echo "  ✗ Registration failed: " . $e->getMessage() . "\n";
        echo "  Please check the activation key and try again.\n";
        exit(1);
    }
    
    // Step 2: Sync configuration
    echo "Step 2: Syncing configuration from ZIMRA...\n";
    try {
        $result = $fiscalService->syncConfig();
        echo "  ✓ Configuration synced successfully!\n\n";
    } catch (Exception $e) {
        echo "  ✗ Error syncing config: " . $e->getMessage() . "\n";
        exit(1);
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
            echo "Step 4: Fiscal day status is: {$status['fiscalDayStatus']}\n\n";
        }
    } else {
        echo "  ⚠ Could not get fiscal day status from ZIMRA\n\n";
    }
    
    // Final status check
    echo "=== FINAL STATUS FOR BELGRAVIA ===\n";
    $finalStatus = $fiscalService->getFiscalDayStatus();
    if ($finalStatus && isset($finalStatus['fiscalDayStatus'])) {
        echo "Fiscal Day Status: {$finalStatus['fiscalDayStatus']}\n";
        if (isset($finalStatus['lastFiscalDayNo'])) {
            echo "Fiscal Day No: {$finalStatus['lastFiscalDayNo']}\n";
        }
        if (isset($finalStatus['lastReceiptGlobalNo'])) {
            echo "Last Receipt Global No: {$finalStatus['lastReceiptGlobalNo']}\n";
        }
        $isReady = ($finalStatus['fiscalDayStatus'] === 'FiscalDayOpened' || $finalStatus['fiscalDayStatus'] === 'FiscalDayCloseFailed');
        echo "Status: " . ($isReady ? "✓ READY to accept fiscal receipts" : "⚠ NOT READY") . "\n";
    } else {
        echo "Could not verify final status\n";
    }
    
} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Now checking ALL branches status...\n\n";
require_once __DIR__ . '/verify_all_fiscal_status.php';

