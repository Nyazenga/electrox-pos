<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Detailed Check for Sale 86 ===\n\n";

// Check in tenant database
$db = Database::getInstance();
$sale = $db->getRow("SELECT * FROM sales WHERE id = 86");

if ($sale) {
    echo "✓ Sale 86 FOUND in tenant database\n";
    echo "  Branch ID: {$sale['branch_id']}\n";
    echo "  Total: {$sale['total_amount']}\n";
    echo "  Payment Status: {$sale['payment_status']}\n";
    echo "  Fiscalized: " . (isset($sale['fiscalized']) ? $sale['fiscalized'] : 'N/A') . "\n";
    echo "  Fiscal Details: " . (isset($sale['fiscal_details']) && !empty($sale['fiscal_details']) ? 'Yes' : 'No') . "\n\n";
    
    // Check if fiscalization is enabled for this branch
    $primaryDb = Database::getPrimaryInstance();
    $branch = $primaryDb->getRow(
        "SELECT id, branch_name, fiscalization_enabled FROM branches WHERE id = :id",
        [':id' => $sale['branch_id']]
    );
    
    if ($branch) {
        echo "Branch Info:\n";
        echo "  Name: {$branch['branch_name']}\n";
        echo "  Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'YES' : 'NO') . "\n\n";
        
        if (!$branch['fiscalization_enabled']) {
            echo "⚠️  FISCALIZATION IS DISABLED FOR THIS BRANCH!\n";
            echo "   This is why fiscalization was not called.\n\n";
        }
    }
    
    // Check fiscal receipt
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT * FROM fiscal_receipts WHERE sale_id = 86"
    );
    
    if ($fiscalReceipt) {
        echo "✓ Fiscal Receipt Found:\n";
        echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
        echo "  Device ID: {$fiscalReceipt['device_id']}\n";
    } else {
        echo "✗ No Fiscal Receipt Found\n";
    }
    
} else {
    echo "✗ Sale 86 NOT FOUND in tenant database\n";
    echo "  Checking all recent sales...\n\n";
    
    $recentSales = $db->getRows("SELECT id, branch_id, total_amount, created_at FROM sales ORDER BY id DESC LIMIT 10");
    if (empty($recentSales)) {
        echo "  No sales found at all in database\n";
    } else {
        echo "  Recent sales:\n";
        foreach ($recentSales as $s) {
            echo "    Sale ID: {$s['id']}, Branch: {$s['branch_id']}, Total: {$s['total_amount']}, Created: {$s['created_at']}\n";
        }
    }
}

// Check error logs for any mention of sale 86 or recent sales
echo "\n=== Checking Error Logs ===\n";
$logFile = __DIR__ . '/logs/error.log';
if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    $lines = explode("\n", $logs);
    $recentLines = array_slice($lines, -200); // Last 200 lines
    
    $foundLogs = [];
    foreach ($recentLines as $line) {
        if (stripos($line, '86') !== false || 
            stripos($line, 'PROCESS SALE') !== false ||
            stripos($line, 'FISCALIZATION') !== false) {
            $foundLogs[] = trim($line);
        }
    }
    
    if (empty($foundLogs)) {
        echo "  ✗ NO LOGS FOUND for sale 86 or recent fiscalization attempts\n";
        echo "  This confirms fiscalization code was NOT called.\n";
    } else {
        echo "  Found " . count($foundLogs) . " relevant log entries:\n";
        foreach (array_slice($foundLogs, -20) as $log) {
            echo "    " . $log . "\n";
        }
    }
}

