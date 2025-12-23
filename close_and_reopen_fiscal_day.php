<?php
/**
 * Close current fiscal day and open a new one to reset counters
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$deviceId = 30199;
$branchId = 1;

echo "========================================\n";
echo "CLOSE AND REOPEN FISCAL DAY\n";
echo "========================================\n\n";

try {
    $fiscalService = new FiscalService($branchId);
    
    // Get current status
    echo "1. Getting current fiscal day status...\n";
    $status = $fiscalService->getFiscalDayStatus();
    echo "   Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    echo "   Fiscal Day No: " . ($status['lastFiscalDayNo'] ?? 'N/A') . "\n";
    echo "   Last Receipt Global No: " . ($status['lastReceiptGlobalNo'] ?? 'N/A') . "\n\n";
    
    // Try to close the fiscal day
    echo "2. Attempting to close fiscal day...\n";
    try {
        $closeResult = $fiscalService->closeFiscalDay();
        echo "   ✓ Fiscal day closed successfully\n";
        echo "   Result: " . json_encode($closeResult, JSON_PRETTY_PRINT) . "\n\n";
    } catch (Exception $closeError) {
        echo "   ✗ Could not close: " . $closeError->getMessage() . "\n\n";
        
        // If close fails, check if we can force it
        if (strpos($closeError->getMessage(), 'FISC01') !== false || 
            strpos($closeError->getMessage(), 'not closed') !== false) {
            echo "   ⚠ Fiscal day appears to be stuck. May need device reset.\n\n";
        }
    }
    
    // Wait a moment
    sleep(2);
    
    // Try to open a new fiscal day
    echo "3. Attempting to open new fiscal day...\n";
    try {
        $openResult = $fiscalService->openFiscalDay();
        echo "   ✓ New fiscal day opened successfully\n";
        echo "   Fiscal Day No: " . ($openResult['fiscalDayNo'] ?? 'N/A') . "\n\n";
        
        // Get new status
        echo "4. Getting new fiscal day status...\n";
        $newStatus = $fiscalService->getFiscalDayStatus();
        echo "   Status: " . ($newStatus['fiscalDayStatus'] ?? 'N/A') . "\n";
        echo "   Fiscal Day No: " . ($newStatus['lastFiscalDayNo'] ?? 'N/A') . "\n";
        echo "   Last Receipt Global No: " . ($newStatus['lastReceiptGlobalNo'] ?? 'N/A') . "\n\n";
        
        echo "========================================\n";
        echo "SUCCESS - New fiscal day opened!\n";
        echo "You can now submit receipts with counter = 1\n";
        echo "========================================\n";
        
    } catch (Exception $openError) {
        echo "   ✗ Could not open new fiscal day: " . $openError->getMessage() . "\n\n";
        
        if (strpos($openError->getMessage(), 'FISC01') !== false) {
            echo "========================================\n";
            echo "FISCAL DAY IS STUCK\n";
            echo "========================================\n";
            echo "The fiscal day cannot be closed because:\n";
            echo "- There may be unclosed receipts\n";
            echo "- The day may be in an invalid state\n";
            echo "\nOPTIONS:\n";
            echo "1. Contact ZIMRA to reset device $deviceId\n";
            echo "2. Try submitting with counter = " . (($status['lastReceiptGlobalNo'] ?? 0) + 1) . "\n";
            echo "3. Check ZIMRA portal for actual receipt count\n";
            echo "========================================\n";
        }
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n";
