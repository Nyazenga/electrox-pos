<?php
/**
 * Diagnostic script to check fiscal day status
 * This will show the exact state of both ZIMRA and local database
 */

define('APP_PATH', __DIR__);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/fiscal_service.php';
require_once __DIR__ . '/includes/zimra_api.php';

$branchId = 1; // HEAD OFFICE - adjust if needed

echo "========================================\n";
echo "FISCAL DAY DIAGNOSTIC REPORT\n";
echo "========================================\n\n";

try {
    $fiscalService = new FiscalService($branchId);
    $db = Database::getPrimaryInstance();
    
    // Get device info
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
        [':branch_id' => $branchId]
    );
    
    if (!$device) {
        echo "ERROR: No active fiscal device found for branch $branchId\n";
        exit(1);
    }
    
    echo "Device ID: " . $device['device_id'] . "\n";
    echo "Branch ID: $branchId\n\n";
    
    // Check ZIMRA status
    echo "--- ZIMRA STATUS (Source of Truth) ---\n";
    try {
        $zimraStatus = $fiscalService->getFiscalDayStatus();
        if ($zimraStatus) {
            echo "Fiscal Day Status: " . ($zimraStatus['fiscalDayStatus'] ?? 'Unknown') . "\n";
            echo "Last Fiscal Day No: " . ($zimraStatus['lastFiscalDayNo'] ?? 'Unknown') . "\n";
            echo "Last Receipt Global No: " . ($zimraStatus['lastReceiptGlobalNo'] ?? 'Unknown') . "\n";
        } else {
            echo "Could not retrieve ZIMRA status\n";
        }
    } catch (Exception $e) {
        echo "ERROR getting ZIMRA status: " . $e->getMessage() . "\n";
    }
    echo "\n";
    
    // Check local database
    echo "--- LOCAL DATABASE STATUS ---\n";
    $localDays = $db->getRows(
        "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id ORDER BY id DESC LIMIT 5",
        [':branch_id' => $branchId, ':device_id' => $device['device_id']]
    );
    
    if (empty($localDays)) {
        echo "No fiscal days found in local database\n";
    } else {
        echo "Found " . count($localDays) . " fiscal day record(s):\n";
        foreach ($localDays as $day) {
            echo "  - ID: {$day['id']}, Day No: {$day['fiscal_day_no']}, Status: {$day['status']}, Opened: {$day['fiscal_day_opened']}\n";
        }
    }
    
    $openDay = $db->getRow(
        "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status = 'FiscalDayOpened'",
        [':branch_id' => $branchId, ':device_id' => $device['device_id']]
    );
    
    if ($openDay) {
        echo "\nOpen fiscal day in local DB:\n";
        echo "  - ID: {$openDay['id']}\n";
        echo "  - Day No: {$openDay['fiscal_day_no']}\n";
        echo "  - Opened: {$openDay['fiscal_day_opened']}\n";
    } else {
        echo "\nNo open fiscal day in local database\n";
    }
    echo "\n";
    
    // Analysis
    echo "--- ANALYSIS ---\n";
    $zimraOpen = isset($zimraStatus['fiscalDayStatus']) && $zimraStatus['fiscalDayStatus'] === 'FiscalDayOpened';
    $localOpen = $openDay !== null;
    
    if ($zimraOpen && !$localOpen) {
        echo "PROBLEM: ZIMRA has an open fiscal day, but local database doesn't!\n";
        echo "SOLUTION: Need to sync local database with ZIMRA\n";
        echo "  - ZIMRA Day No: " . ($zimraStatus['lastFiscalDayNo'] ?? 'Unknown') . "\n";
    } elseif (!$zimraOpen && $localOpen) {
        echo "PROBLEM: Local database has an open fiscal day, but ZIMRA doesn't!\n";
        echo "SOLUTION: Need to close local record or sync with ZIMRA\n";
    } elseif ($zimraOpen && $localOpen) {
        $zimraDayNo = $zimraStatus['lastFiscalDayNo'] ?? null;
        $localDayNo = $openDay['fiscal_day_no'] ?? null;
        if ($zimraDayNo != $localDayNo) {
            echo "PROBLEM: Fiscal day numbers don't match!\n";
            echo "  - ZIMRA Day No: $zimraDayNo\n";
            echo "  - Local Day No: $localDayNo\n";
            echo "SOLUTION: Need to sync local database with ZIMRA\n";
        } else {
            echo "OK: Both ZIMRA and local database are in sync\n";
            echo "  - Day No: $zimraDayNo\n";
        }
    } else {
        echo "OK: No fiscal day is open (both ZIMRA and local database agree)\n";
    }
    
} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n========================================\n";
echo "END OF DIAGNOSTIC REPORT\n";
echo "========================================\n";

