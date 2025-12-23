<?php
define('APP_PATH', __DIR__);
require_once 'config.php';
require_once 'includes/db.php';

$db = Database::getInstance();
$receipts = $db->getRows(
    "SELECT fr.id, fr.receipt_global_no, frt.tax_id, frt.tax_percent, frt.tax_amount, frt.sales_amount_with_tax 
     FROM fiscal_receipts fr 
     LEFT JOIN fiscal_receipt_taxes frt ON fr.id = frt.fiscal_receipt_id 
     WHERE fr.device_id = 30200 AND fr.fiscal_day_no = 1 AND fr.submission_status = 'Submitted' 
     ORDER BY fr.receipt_global_no 
     LIMIT 10"
);

echo "Receipt Tax Data:\n";
echo "==================\n\n";

foreach ($receipts as $r) {
    echo "Receipt ID: " . ($r['id'] ?? 'N/A') . "\n";
    echo "Receipt Global No: " . ($r['receipt_global_no'] ?? 'N/A') . "\n";
    echo "Tax ID: " . ($r['tax_id'] ?? 'NULL') . "\n";
    echo "Tax Percent: " . ($r['tax_percent'] ?? 'NULL') . "\n";
    echo "Tax Amount: " . ($r['tax_amount'] ?? 'NULL') . "\n";
    echo "Sales Amount With Tax: " . ($r['sales_amount_with_tax'] ?? 'NULL') . "\n";
    echo "---\n";
}


