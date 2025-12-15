<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();

echo "=== Invoice Sales Verification ===\n\n";

// Check all paid invoices
$paidInvoices = $db->getRows("SELECT id, invoice_number, status, total_amount, invoice_date FROM invoices WHERE status = 'Paid' ORDER BY id DESC LIMIT 10");

if (empty($paidInvoices)) {
    echo "No paid invoices found.\n";
    exit(0);
}

echo "Checking " . count($paidInvoices) . " paid invoice(s):\n\n";

foreach ($paidInvoices as $invoice) {
    $sale = $db->getRow("SELECT id, receipt_number, total_amount FROM sales WHERE invoice_id = :id", [':id' => $invoice['id']]);
    
    $status = $sale ? "✓ HAS SALE" : "✗ NO SALE";
    echo "{$invoice['invoice_number']} - {$status}\n";
    
    if (!$sale) {
        $items = $db->getRows("SELECT COUNT(*) as count FROM invoice_items WHERE invoice_id = :id AND product_id IS NOT NULL", [':id' => $invoice['id']]);
        $itemCount = $items ? intval($items[0]['count']) : 0;
        echo "  Items with products: {$itemCount}\n";
    } else {
        echo "  Receipt: {$sale['receipt_number']}\n";
    }
    echo "\n";
}

