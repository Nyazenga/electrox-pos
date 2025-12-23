<?php
// Simulate session for tenant
session_start();
$_SESSION['tenant_name'] = 'primary'; // Based on user's earlier message

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Checking Sale 87 in Tenant Database ===\n\n";

$tenantName = getCurrentTenantDbName();
echo "Tenant: " . ($tenantName ?? 'NULL') . "\n";

// Check tenant database
$db = Database::getInstance();
$dbName = $db->getRow("SELECT DATABASE() as db");
echo "Connected to DB: " . ($dbName['db'] ?? 'Unknown') . "\n\n";

// Check sale 87
$sale = $db->getRow("SELECT * FROM sales WHERE id = 87");

if ($sale) {
    echo "✓ Sale 87 FOUND\n";
    echo "  Branch ID: {$sale['branch_id']}\n";
    echo "  Total: {$sale['total_amount']}\n";
    echo "  Payment Status: {$sale['payment_status']}\n";
    echo "  Created: {$sale['created_at']}\n";
    echo "  Fiscalized: " . (isset($sale['fiscalized']) ? ($sale['fiscalized'] ? 'YES' : 'NO') : 'N/A') . "\n";
    echo "  Fiscal Details: " . (isset($sale['fiscal_details']) && !empty($sale['fiscal_details']) ? 'Yes' : 'No') . "\n\n";
    
    // Check branch fiscalization status
    $primaryDb = Database::getPrimaryInstance();
    $branch = $primaryDb->getRow(
        "SELECT id, branch_name, fiscalization_enabled FROM branches WHERE id = :id",
        [':id' => $sale['branch_id']]
    );
    
    if ($branch) {
        echo "Branch Info:\n";
        echo "  Name: {$branch['branch_name']}\n";
        echo "  Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'YES ✓' : 'NO ✗') . "\n\n";
        
        if (!$branch['fiscalization_enabled']) {
            echo "⚠️  PROBLEM: Fiscalization is DISABLED for this branch!\n";
        }
    }
    
    // Check fiscal receipt
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT * FROM fiscal_receipts WHERE sale_id = 87"
    );
    
    if ($fiscalReceipt) {
        echo "✓ Fiscal Receipt Found:\n";
        echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
        echo "  Device ID: {$fiscalReceipt['device_id']}\n";
    } else {
        echo "✗ No Fiscal Receipt Found\n";
    }
    
} else {
    echo "✗ Sale 87 NOT FOUND\n";
}

// Check for any logs with "PROCESS SALE" or "FISCALIZATION"
echo "\n=== Checking for Logs ===\n";
$logFile = __DIR__ . '/logs/error.log';
if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    $lines = explode("\n", $logs);
    
    // Get last 300 lines
    $recentLines = array_slice($lines, -300);
    
    $foundLogs = [];
    foreach ($recentLines as $line) {
        if (stripos($line, 'PROCESS SALE') !== false || 
            stripos($line, 'FISCALIZATION') !== false ||
            stripos($line, 'fiscalize') !== false) {
            $foundLogs[] = trim($line);
        }
    }
    
    if (empty($foundLogs)) {
        echo "  ✗ NO FISCALIZATION LOGS FOUND!\n";
        echo "  This means the fiscalization code was NOT executed.\n";
        echo "  Possible reasons:\n";
        echo "    1. The script wasn't called\n";
        echo "    2. Error before reaching fiscalization code\n";
        echo "    3. Logging is disabled\n";
    } else {
        echo "  Found " . count($foundLogs) . " log entries:\n";
        foreach (array_slice($foundLogs, -30) as $log) {
            echo "    " . $log . "\n";
        }
    }
}

