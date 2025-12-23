<?php
/**
 * Check receipt sequence to see if there are any gaps
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

// Get current fiscal day
$fiscalDay = $db->getRow(
    "SELECT * FROM fiscal_days WHERE device_id = 30199 AND status = 'FiscalDayOpened' ORDER BY fiscal_day_no DESC LIMIT 1"
);

if (!$fiscalDay) {
    echo "No open fiscal day found\n";
    exit;
}

echo "Fiscal Day: {$fiscalDay['fiscal_day_no']}\n";
echo "Status: {$fiscalDay['status']}\n\n";

// Get all receipts for this fiscal day
$receipts = $db->getRows(
    "SELECT receipt_counter, receipt_global_no, receipt_id, submission_status, receipt_hash, receipt_server_signature
     FROM fiscal_receipts 
     WHERE device_id = 30199 AND fiscal_day_no = :fiscal_day_no
     ORDER BY receipt_counter ASC",
    [':fiscal_day_no' => $fiscalDay['fiscal_day_no']]
);

echo "Receipts for fiscal day {$fiscalDay['fiscal_day_no']}:\n";
echo str_repeat('-', 80) . "\n";
printf("%-10s %-15s %-15s %-20s %-40s\n", "Counter", "Global No", "Receipt ID", "Status", "Hash (first 30 chars)");
echo str_repeat('-', 80) . "\n";

foreach ($receipts as $r) {
    $hash = null;
    if (!empty($r['receipt_server_signature'])) {
        $serverSig = json_decode($r['receipt_server_signature'], true);
        $hash = $serverSig['hash'] ?? null;
    }
    if (!$hash && !empty($r['receipt_hash'])) {
        $hash = $r['receipt_hash'];
    }
    
    printf("%-10s %-15s %-15s %-20s %-40s\n",
        $r['receipt_counter'] ?? 'N/A',
        $r['receipt_global_no'] ?? 'N/A',
        $r['receipt_id'] ?? 'N/A',
        $r['submission_status'] ?? 'N/A',
        $hash ? substr($hash, 0, 30) . '...' : 'NULL'
    );
}

echo "\n";
echo "Expected sequence: 1, 2, 3, ...\n";
echo "Checking for gaps...\n";

$expectedCounter = 1;
foreach ($receipts as $r) {
    $counter = $r['receipt_counter'] ?? null;
    if ($counter != $expectedCounter) {
        echo "⚠ GAP: Expected counter $expectedCounter, but found counter $counter\n";
    }
    $expectedCounter = $counter + 1;
}

echo "\nDone.\n";

