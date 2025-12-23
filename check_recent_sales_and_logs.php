<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Checking Recent Sales and Fiscalization Logs ===\n\n";

// Check recent sales
$db = Database::getInstance();
$sales = $db->getRows("SELECT id, branch_id, total_amount, payment_status, created_at, fiscalized FROM sales ORDER BY id DESC LIMIT 10");

echo "Recent Sales (Last 10):\n";
if (empty($sales)) {
    echo "  No sales found\n";
} else {
    foreach ($sales as $sale) {
        echo "  Sale ID: {$sale['id']}, Branch: {$sale['branch_id']}, Total: {$sale['total_amount']}, Fiscalized: " . ($sale['fiscalized'] ?? 0) . ", Created: {$sale['created_at']}\n";
    }
}

// Check fiscal receipts
$primaryDb = Database::getPrimaryInstance();
$fiscalReceipts = $primaryDb->getRows("SELECT * FROM fiscal_receipts ORDER BY id DESC LIMIT 10");

echo "\nRecent Fiscal Receipts (Last 10):\n";
if (empty($fiscalReceipts)) {
    echo "  No fiscal receipts found\n";
} else {
    foreach ($fiscalReceipts as $fr) {
        echo "  Sale ID: {$fr['sale_id']}, Receipt Global No: {$fr['receipt_global_no']}, Device: {$fr['device_id']}, Created: {$fr['created_at']}\n";
    }
}

// Check error logs for fiscalization attempts
echo "\n=== Checking Error Logs for Fiscalization ===\n";
$logFile = __DIR__ . '/logs/error.log';
if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    $fiscalLogs = [];
    $lines = explode("\n", $logs);
    
    // Get last 100 lines
    $recentLines = array_slice($lines, -100);
    
    foreach ($recentLines as $line) {
        if (stripos($line, 'FISCALIZATION') !== false || 
            stripos($line, 'FISCALIZE') !== false ||
            stripos($line, 'PROCESS SALE') !== false ||
            stripos($line, 'API SALES') !== false) {
            $fiscalLogs[] = $line;
        }
    }
    
    if (empty($fiscalLogs)) {
        echo "  ✗ NO FISCALIZATION LOGS FOUND!\n";
        echo "  This means fiscalization code was NOT called.\n";
    } else {
        echo "  Found " . count($fiscalLogs) . " fiscalization log entries:\n";
        foreach (array_slice($fiscalLogs, -20) as $log) {
            echo "    " . trim($log) . "\n";
        }
    }
} else {
    echo "  Error log file not found\n";
}

// Check ZIMRA API calls
echo "\n=== Checking for ZIMRA API Calls ===\n";
if (file_exists($logFile)) {
    $zimraLogs = [];
    $lines = explode("\n", $logs);
    $recentLines = array_slice($lines, -100);
    
    foreach ($recentLines as $line) {
        if (stripos($line, 'ZIMRA API') !== false || 
            stripos($line, 'fdmsapitest.zimra.co.zw') !== false ||
            stripos($line, 'SubmitReceipt') !== false ||
            stripos($line, 'OpenDay') !== false) {
            $zimraLogs[] = $line;
        }
    }
    
    if (empty($zimraLogs)) {
        echo "  ✗ NO ZIMRA API CALLS FOUND!\n";
        echo "  This means no calls were made to ZIMRA endpoints.\n";
    } else {
        echo "  Found " . count($zimraLogs) . " ZIMRA API log entries:\n";
        foreach (array_slice($zimraLogs, -20) as $log) {
            echo "    " . trim($log) . "\n";
        }
    }
}

