<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Checking Tenant Database and Sale 86 ===\n\n";

// Check current tenant
$tenantName = getCurrentTenantDbName();
echo "Current Tenant: " . ($tenantName ?? 'NULL') . "\n";
echo "Expected DB: electrox_" . ($tenantName ?? 'primary') . "\n\n";

// Check tenant database
$db = Database::getInstance();
$dbName = $db->getRow("SELECT DATABASE() as db");
echo "Connected to DB: " . ($dbName['db'] ?? 'Unknown') . "\n\n";

// Check sale 86
$sale = $db->getRow("SELECT * FROM sales WHERE id = 86");
if ($sale) {
    echo "✓ Sale 86 FOUND\n";
    echo "  Branch ID: {$sale['branch_id']}\n";
    echo "  Total: {$sale['total_amount']}\n";
    echo "  Created: {$sale['created_at']}\n";
    echo "  Fiscalized: " . (isset($sale['fiscalized']) ? $sale['fiscalized'] : 'N/A') . "\n\n";
    
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
            echo "⚠️  PROBLEM FOUND: Fiscalization is DISABLED for this branch!\n";
            echo "   This is why fiscalization was not called.\n";
            echo "   Solution: Enable fiscalization in Settings > Fiscalization (ZIMRA)\n";
        }
    }
} else {
    echo "✗ Sale 86 NOT FOUND\n";
    echo "  Checking all sales...\n";
    $allSales = $db->getRows("SELECT id, branch_id, total_amount, created_at FROM sales ORDER BY id DESC LIMIT 10");
    if (empty($allSales)) {
        echo "  No sales found in database\n";
    } else {
        echo "  Recent sales:\n";
        foreach ($allSales as $s) {
            echo "    Sale ID: {$s['id']}, Branch: {$s['branch_id']}, Total: {$s['total_amount']}\n";
        }
    }
}

