<?php
/**
 * Script to open a fiscal day
 * Can be called from command line or via PowerShell
 */

// Bootstrap
define('APP_PATH', __DIR__);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/fiscal_service.php';

// Get branch ID from command line argument or use default (HEAD OFFICE = 1)
$branchId = isset($argv[1]) ? intval($argv[1]) : 1;

// If branch ID is 0 or not provided, try to find HEAD OFFICE
if ($branchId <= 0) {
    $primaryDb = Database::getPrimaryInstance();
    $branch = $primaryDb->getRow(
        "SELECT id, branch_name FROM branches WHERE branch_name LIKE '%HEAD%' OR branch_name LIKE '%OFFICE%' OR id = 1 LIMIT 1"
    );
    if ($branch) {
        $branchId = $branch['id'];
        echo "Using branch: {$branch['branch_name']} (ID: $branchId)\n";
    } else {
        die("ERROR: Could not find HEAD OFFICE branch. Please specify branch ID as argument.\n");
    }
}

echo "=== Open Fiscal Day ===\n\n";
echo "Branch ID: $branchId\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $fiscalService = new FiscalService($branchId);
    
    // Step 1: Check current status
    echo "Step 1: Checking current fiscal day status...\n";
    $status = $fiscalService->getFiscalDayStatus();
    if ($status && isset($status['fiscalDayStatus'])) {
        echo "  Current status: " . $status['fiscalDayStatus'] . "\n";
        if (isset($status['lastFiscalDayNo'])) {
            echo "  Last fiscal day no: " . $status['lastFiscalDayNo'] . "\n";
        }
        
        // If day is already open, inform user
        if ($status['fiscalDayStatus'] === 'FiscalDayOpened') {
            echo "\n  ⚠ WARNING: Fiscal day is already open!\n";
            echo "  Day No: " . ($status['lastFiscalDayNo'] ?? 'Unknown') . "\n";
            echo "  You cannot open a new day while one is open.\n";
            echo "  If you need to open a new day, close the current one first.\n";
            exit(0);
        }
        
        // If close failed, inform user
        if ($status['fiscalDayStatus'] === 'FiscalDayCloseFailed') {
            echo "\n  ⚠ WARNING: Previous close attempt failed!\n";
            echo "  Day No: " . ($status['lastFiscalDayNo'] ?? 'Unknown') . "\n";
            echo "  You should close this day first before opening a new one.\n";
            exit(1);
        }
    } else {
        echo "  No current fiscal day found (or status unavailable)\n";
    }
    echo "\n";
    
    // Step 2: Open fiscal day
    echo "Step 2: Opening fiscal day...\n";
    $result = $fiscalService->openFiscalDay();
    
    if (isset($result['synced']) && $result['synced']) {
        echo "  ℹ Fiscal day was already open on ZIMRA. Local database synced.\n";
    } else {
        echo "  ✓ Fiscal day opened successfully!\n";
    }
    
    echo "  Fiscal day no: " . ($result['fiscalDayNo'] ?? 'Unknown') . "\n";
    if (isset($result['fiscalDayOpened'])) {
        echo "  Fiscal day opened: " . $result['fiscalDayOpened'] . "\n";
    }
    if (isset($result['operationID'])) {
        echo "  Operation ID: " . $result['operationID'] . "\n";
    }
    echo "\n";
    
    // Step 3: Verify fiscal day
    echo "Step 3: Verifying fiscal day...\n";
    $status = $fiscalService->getFiscalDayStatus();
    if ($status && isset($status['fiscalDayStatus'])) {
        echo "  Status: " . $status['fiscalDayStatus'] . "\n";
        if (isset($status['lastFiscalDayNo'])) {
            echo "  Fiscal day no: " . $status['lastFiscalDayNo'] . "\n";
        }
        
        if ($status['fiscalDayStatus'] === 'FiscalDayOpened') {
            echo "\n  ✓✓✓ SUCCESS! Fiscal day is open and ready for sales.\n";
        } else {
            echo "\n  ⚠ Warning: Fiscal day status is not 'FiscalDayOpened'\n";
        }
    } else {
        echo "  ⚠ Could not verify fiscal day status\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    
    // Provide helpful error messages
    if (strpos($e->getMessage(), 'FISC01') !== false || strpos($e->getMessage(), 'not closed') !== false) {
        echo "\n  This error means a fiscal day is already open on ZIMRA.\n";
        echo "  You need to close the current fiscal day first before opening a new one.\n";
        echo "  Use the 'Close Fiscal Day' action in Settings → Fiscalization.\n";
    } elseif (strpos($e->getMessage(), 'certificate') !== false || strpos($e->getMessage(), 'Certificate') !== false) {
        echo "\n  This error is related to the device certificate.\n";
        echo "  Please check that the device is registered and has a valid certificate.\n";
    }
    
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== Done ===\n";
echo "Fiscal day is now open. You can now process sales with fiscalization.\n";


