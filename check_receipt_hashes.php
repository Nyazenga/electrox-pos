<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

echo "========================================\n";
echo "CHECKING RECEIPT HASHES\n";
echo "========================================\n\n";

$receipts = $db->getRows(
    "SELECT receipt_id, receipt_counter, receipt_global_no, receipt_hash, receipt_server_signature, submission_status
     FROM fiscal_receipts 
     WHERE device_id = 30199 
     ORDER BY receipt_counter ASC"
);

foreach ($receipts as $r) {
    echo "Receipt Counter: " . $r['receipt_counter'] . "\n";
    echo "  Receipt ID: " . $r['receipt_id'] . "\n";
    echo "  Global No: " . $r['receipt_global_no'] . "\n";
    echo "  Receipt Hash (saved): " . ($r['receipt_hash'] ?? 'NULL') . "\n";
    
    $sig = json_decode($r['receipt_server_signature'] ?? '{}', true);
    if (isset($sig['hash'])) {
        echo "  ZIMRA Hash (from response): " . $sig['hash'] . "\n";
    } else {
        echo "  ZIMRA Hash: NOT IN RESPONSE\n";
    }
    echo "  Status: " . ($r['submission_status'] ?? 'N/A') . "\n";
    echo "\n";
}

