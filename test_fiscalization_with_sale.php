<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';

// Get branch ID
$primaryDb = Database::getPrimaryInstance();
$branch = $primaryDb->getRow("SELECT id FROM branches WHERE fiscalization_enabled = 1 LIMIT 1");

if (!$branch) {
    die("No branch with fiscalization enabled\n");
}

$branchId = $branch['id'];
echo "Testing fiscalization for branch ID: $branchId\n\n";

// Test FiscalService initialization
echo "Initializing FiscalService...\n";
try {
    $fiscalService = new FiscalService($branchId);
    echo "✓ FiscalService initialized\n\n";
    
    // Check fiscal day status
    echo "Checking fiscal day status...\n";
    $status = $fiscalService->getFiscalDayStatus();
    echo "  Status: " . ($status['fiscalDayStatus'] ?? 'N/A') . "\n";
    
    if ($status['fiscalDayStatus'] !== 'FiscalDayOpened') {
        echo "Opening fiscal day...\n";
        $result = $fiscalService->openFiscalDay();
        echo "  ✓ Fiscal day opened (Day #{$result['fiscalDayNo']})\n";
    }
    
    echo "\n✓ Fiscalization is working!\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
}

