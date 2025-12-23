<?php
define('APP_PATH', __DIR__);
require_once 'config.php';
require_once 'includes/db.php';

$db = Database::getInstance();

// Check receipts for device 30200
$receipts = $db->getRows(
    "SELECT fr.receipt_global_no, fr.receipt_counter, fr.receipt_id, 
            fr.submission_status, fr.fiscal_day_no, fr.total_amount,
            (SELECT COUNT(*) FROM fiscal_receipt_taxes frt WHERE frt.fiscal_receipt_id = fr.id) as tax_count
     FROM fiscal_receipts fr
     WHERE fr.device_id = 30200
     ORDER BY fr.receipt_global_no ASC
     LIMIT 20"
);

echo "Receipts for Device 30200:\n";
echo "Total: " . count($receipts) . "\n\n";

foreach ($receipts as $r) {
    $receiptId = $r['receipt_id'] ?? 'NULL';
    $status = $r['submission_status'] ?? 'N/A';
    $taxCount = $r['tax_count'] ?? 0;
    
    echo "Receipt #" . $r['receipt_global_no'] . 
         " (Counter: " . $r['receipt_counter'] . 
         ", Day: " . $r['fiscal_day_no'] . 
         ", Status: $status" .
         ", ZIMRA ID: $receiptId" .
         ", Taxes: $taxCount)\n";
}


