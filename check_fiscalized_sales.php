<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

$fiscalReceipts = $primaryDb->getRows(
    'SELECT sale_id, receipt_id, receipt_global_no, 
            LENGTH(receipt_qr_code) as qr_code_length,
            LENGTH(receipt_qr_data) as qr_data_length
     FROM fiscal_receipts 
     WHERE sale_id IS NOT NULL 
     ORDER BY id DESC 
     LIMIT 10'
);

echo "Fiscalized Sales:\n";
if (empty($fiscalReceipts)) {
    echo "  No fiscalized sales found\n";
} else {
    foreach ($fiscalReceipts as $fr) {
        echo "  Sale ID: {$fr['sale_id']}\n";
        echo "    Receipt ID: {$fr['receipt_id']}\n";
        echo "    Global No: {$fr['receipt_global_no']}\n";
        echo "    QR Code: " . ($fr['qr_code_length'] > 0 ? "Present ({$fr['qr_code_length']} bytes)" : "Empty") . "\n";
        echo "    QR Data: " . ($fr['qr_data_length'] > 0 ? "Present ({$fr['qr_data_length']} bytes)" : "Empty") . "\n";
        echo "\n";
    }
}

