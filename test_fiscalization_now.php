<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

$branchId = 1; // Head Office

echo "=== Testing Fiscalization ===\n\n";

try {
    $fiscalService = new FiscalService($branchId);
    
    // Check fiscal day
    $fiscalDay = $fiscalService->getFiscalDayStatus();
    if ($fiscalDay && $fiscalDay['fiscalDayStatus'] === 'FiscalDayOpened') {
        echo "✓ Fiscal day is open (Day #{$fiscalDay['fiscalDayNo']})\n\n";
    } else {
        echo "Opening fiscal day...\n";
        $result = $fiscalService->openFiscalDay();
        echo "✓ Fiscal day opened (Day #{$result['fiscalDayNo']})\n\n";
    }
    
    echo "✓ Fiscalization service is working!\n";
    echo "You can now make a sale and it should be fiscalized automatically.\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

