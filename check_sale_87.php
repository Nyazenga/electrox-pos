<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Checking Sale 87 ===\n\n";

// Check in tenant database
$db = Database::getInstance();
$sale = $db->getRow("SELECT * FROM sales WHERE id = 87");

if ($sale) {
    echo "✓ Sale 87 FOUND\n";
    echo "  Branch ID: {$sale['branch_id']}\n";
    echo "  Total: {$sale['total_amount']}\n";
    echo "  Payment Status: {$sale['payment_status']}\n";
    echo "  Created: {$sale['created_at']}\n";
    echo "  Fiscalized: " . (isset($sale['fiscalized']) ? ($sale['fiscalized'] ? 'YES' : 'NO') : 'N/A') . "\n";
    echo "  Fiscal Details: " . (isset($sale['fiscal_details']) && !empty($sale['fiscal_details']) ? 'Yes' : 'No') . "\n\n";
    
    if (isset($sale['fiscal_details']) && !empty($sale['fiscal_details'])) {
        $fiscalDetails = json_decode($sale['fiscal_details'], true);
        echo "Fiscal Details:\n";
        print_r($fiscalDetails);
        echo "\n";
    }
    
    // Check if fiscalization is enabled for this branch
    $primaryDb = Database::getPrimaryInstance();
    $branch = $primaryDb->getRow(
        "SELECT id, branch_name, fiscalization_enabled FROM branches WHERE id = :id",
        [':id' => $sale['branch_id']]
    );
    
    if ($branch) {
        echo "Branch Info:\n";
        echo "  Name: {$branch['branch_name']}\n";
        echo "  Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'YES ✓' : 'NO ✗') . "\n\n";
    }
    
    // Check fiscal receipt
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT * FROM fiscal_receipts WHERE sale_id = 87"
    );
    
    if ($fiscalReceipt) {
        echo "✓ Fiscal Receipt Found:\n";
        echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
        echo "  Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
        echo "  Device ID: {$fiscalReceipt['device_id']}\n";
        echo "  Created: {$fiscalReceipt['created_at']}\n";
    } else {
        echo "✗ No Fiscal Receipt Found\n";
    }
    
} else {
    echo "✗ Sale 87 NOT FOUND\n";
}

// Check error logs
echo "\n=== Checking Error Logs for Sale 87 ===\n";
$logFile = __DIR__ . '/logs/error.log';
if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    $lines = explode("\n", $logs);
    $recentLines = array_slice($lines, -200); // Last 200 lines
    
    $foundLogs = [];
    foreach ($recentLines as $line) {
        if (stripos($line, '87') !== false || 
            stripos($line, 'PROCESS SALE') !== false ||
            stripos($line, 'FISCALIZATION') !== false) {
            $foundLogs[] = trim($line);
        }
    }
    
    if (empty($foundLogs)) {
        echo "  ✗ NO LOGS FOUND for sale 87\n";
    } else {
        echo "  Found " . count($foundLogs) . " relevant log entries:\n";
        foreach ($foundLogs as $log) {
            echo "    " . $log . "\n";
        }
    }
}

