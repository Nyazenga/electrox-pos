<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Checking Sale 86 ===\n\n";

$db = Database::getInstance();
$sale = $db->getRow("SELECT * FROM sales WHERE id = 86");

if (!$sale) {
    die("Sale 86 not found\n");
}

echo "Sale Details:\n";
echo "  Sale ID: {$sale['id']}\n";
echo "  Branch ID: {$sale['branch_id']}\n";
echo "  Total: {$sale['total_amount']}\n";
echo "  Payment Status: {$sale['payment_status']}\n";
echo "  Created: {$sale['created_at']}\n";
echo "  Fiscalized: " . (isset($sale['fiscalized']) ? $sale['fiscalized'] : 'N/A') . "\n";
echo "  Fiscal Details: " . (isset($sale['fiscal_details']) && !empty($sale['fiscal_details']) ? 'Yes' : 'No') . "\n\n";

// Check fiscal receipt
$primaryDb = Database::getPrimaryInstance();
$fiscalReceipt = $primaryDb->getRow(
    "SELECT * FROM fiscal_receipts WHERE sale_id = 86"
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

// Check recent error logs for this sale
echo "\n=== Checking Error Logs ===\n";
$logFile = __DIR__ . '/logs/error.log';
if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    $saleLogs = [];
    $lines = explode("\n", $logs);
    foreach ($lines as $line) {
        if (stripos($line, '86') !== false && (
            stripos($line, 'fiscal') !== false || 
            stripos($line, 'sale') !== false ||
            stripos($line, 'process') !== false
        )) {
            $saleLogs[] = $line;
        }
    }
    
    if (empty($saleLogs)) {
        echo "No fiscalization logs found for sale 86\n";
    } else {
        echo "Found " . count($saleLogs) . " log entries:\n";
        foreach (array_slice($saleLogs, -10) as $log) {
            echo "  " . $log . "\n";
        }
    }
}

