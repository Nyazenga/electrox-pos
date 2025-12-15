<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$invoiceNumber = $argv[1] ?? 'PROF-20251215-4638';

$db = Database::getInstance();

$invoice = $db->getRow("SELECT * FROM invoices WHERE invoice_number = :number", [':number' => $invoiceNumber]);

if (!$invoice) {
    echo "Invoice not found: {$invoiceNumber}\n";
    exit(1);
}

echo "Invoice: {$invoice['invoice_number']}\n";
echo "Status: {$invoice['status']}\n";
echo "Total: $" . number_format($invoice['total_amount'], 2) . "\n\n";

$sale = $db->getRow("SELECT * FROM sales WHERE invoice_id = :id", [':id' => $invoice['id']]);

if ($sale) {
    echo "✓ Sale found:\n";
    echo "  Receipt: {$sale['receipt_number']}\n";
    echo "  Total: $" . number_format($sale['total_amount'], 2) . "\n";
    echo "  Date: {$sale['sale_date']}\n\n";
    
    $items = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :id", [':id' => $sale['id']]);
    echo "Sale Items: " . count($items) . "\n";
    foreach ($items as $item) {
        echo "  - {$item['product_name']} x{$item['quantity']} = $" . number_format($item['total_price'], 2) . "\n";
    }
    
    $payments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $sale['id']]);
    echo "\nPayments: " . count($payments) . "\n";
    foreach ($payments as $payment) {
        echo "  - " . ucfirst($payment['payment_method']) . ": $" . number_format($payment['amount'], 2) . "\n";
    }
} else {
    echo "✗ No sale found for this invoice\n";
    
    $items = $db->getRows("SELECT * FROM invoice_items WHERE invoice_id = :id AND product_id IS NOT NULL", [':id' => $invoice['id']]);
    echo "\nInvoice Items with products: " . count($items) . "\n";
    if (empty($items)) {
        echo "  (No products in invoice items - sale will not be created)\n";
    }
}

