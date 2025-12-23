<?php
/**
 * Check fiscal day status and properly close it if needed
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$deviceId = 30199;
$branchId = 1;

echo "========================================\n";
echo "CHECKING AND RESETTING FISCAL DAY\n";
echo "========================================\n\n";

$db = Database::getPrimaryInstance();
$db->update('fiscal_devices', ['is_active' => 0], ['device_id' => 30200]);
$db->update('fiscal_devices', ['is_active' => 1, 'branch_id' => $branchId], ['device_id' => $deviceId]);

$fiscalService = new FiscalService($branchId);

// Get status
$status = $fiscalService->getFiscalDayStatus();
echo "Current Status:\n";
echo "  Fiscal Day Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
echo "  Last Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
echo "  Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n\n";

// If day is open or close failed, try to close it
if (($status['fiscalDayStatus'] ?? '') === 'FiscalDayOpened' || ($status['fiscalDayStatus'] ?? '') === 'FiscalDayCloseFailed') {
    echo "Attempting to close fiscal day...\n";
    try {
        $closeResult = $fiscalService->closeFiscalDay();
        echo "✓ Close initiated\n";
        
        // Wait and check status
        for ($i = 0; $i < 5; $i++) {
            sleep(2);
            $checkStatus = $fiscalService->getFiscalDayStatus();
            $dayStatus = $checkStatus['fiscalDayStatus'] ?? '';
            echo "  Status after " . (($i + 1) * 2) . " seconds: $dayStatus\n";
            if ($dayStatus === 'FiscalDayClosed') {
                echo "✓ Fiscal day closed successfully\n";
                break;
            }
        }
    } catch (Exception $e) {
        echo "✗ Error closing: " . $e->getMessage() . "\n";
    }
}

// Get final status
$finalStatus = $fiscalService->getFiscalDayStatus();
echo "\nFinal Status:\n";
echo "  Fiscal Day Status: " . ($finalStatus['fiscalDayStatus'] ?? 'N/A') . "\n";
echo "  Last Fiscal Day No: " . ($finalStatus['lastFiscalDayNo'] ?? 'N/A') . "\n";
echo "  Last Receipt Global No: " . ($finalStatus['lastReceiptGlobalNo'] ?? 'N/A') . "\n";

echo "\n========================================\n";

