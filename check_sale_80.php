<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

$sale = $db->getRow('SELECT * FROM sales WHERE id = 80');
echo "Sale ID: " . $sale['id'] . "\n";
echo "Fiscalized: " . ($sale['fiscalized'] ?? 0) . "\n";
echo "Fiscal Details: " . ($sale['fiscal_details'] ?? 'null') . "\n\n";

$fiscalReceipt = $primaryDb->getRow('SELECT * FROM fiscal_receipts WHERE sale_id = 80');
if ($fiscalReceipt) {
    echo "Fiscal Receipt Found:\n";
    echo "  Receipt ID: " . $fiscalReceipt['receipt_id'] . "\n";
    echo "  Global No: " . $fiscalReceipt['receipt_global_no'] . "\n";
    echo "  Verification Code: " . $fiscalReceipt['receipt_verification_code'] . "\n";
    echo "  QR Code (base64): " . (empty($fiscalReceipt['receipt_qr_code']) ? 'Empty' : 'Present (' . strlen($fiscalReceipt['receipt_qr_code']) . ' bytes)') . "\n";
    echo "  QR Data: " . (empty($fiscalReceipt['receipt_qr_data']) ? 'Empty' : substr($fiscalReceipt['receipt_qr_data'], 0, 50) . '...') . "\n";
} else {
    echo "No fiscal receipt found for sale 80\n";
}

