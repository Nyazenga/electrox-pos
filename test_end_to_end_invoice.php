<?php
/**
 * End-to-End Invoice Fiscalization Test
 * Creates an invoice, marks it as paid, and verifies fiscalization
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_service.php';
require_once APP_PATH . '/includes/fiscal_helper.php';

echo "=== End-to-End Invoice Fiscalization Test ===\n\n";

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get Head Office branch
$branch = $primaryDb->getRow(
    "SELECT id FROM branches WHERE branch_code = 'HO' OR branch_name LIKE '%Head Office%' LIMIT 1"
);

if (!$branch) {
    die("✗ Head Office branch not found\n");
}

$branchId = $branch['id'];

// Enable fiscalization for branch
$primaryDb->update('branches', ['fiscalization_enabled' => 1], ['id' => $branchId]);
echo "✓ Fiscalization enabled for branch\n";

// Get or create a test customer
$customer = $db->getRow("SELECT id FROM customers LIMIT 1");
if (!$customer) {
    $customerId = $db->insert('customers', [
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'email' => 'test@example.com',
        'phone' => '0771234567'
    ]);
    echo "✓ Test customer created\n";
} else {
    $customerId = $customer['id'];
    echo "✓ Using existing customer\n";
}

// Get a test product
$product = $db->getRow("SELECT id, unit_price FROM products WHERE status = 'active' LIMIT 1");
if (!$product) {
    die("✗ No active products found. Please create a product first.\n");
}

echo "✓ Using product ID: " . $product['id'] . "\n\n";

// Create test invoice
echo "Creating test invoice...\n";
$invoiceNumber = 'TEST-INV-' . time();
$invoiceId = $db->insert('invoices', [
    'invoice_number' => $invoiceNumber,
    'customer_id' => $customerId,
    'branch_id' => $branchId,
    'invoice_type' => 'TaxInvoice',
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'subtotal' => 100.00,
    'tax_amount' => 15.00,
    'total_amount' => 115.00,
    'status' => 'Draft',
    'user_id' => 1
]);

echo "✓ Invoice created: $invoiceNumber (ID: $invoiceId)\n";

// Add invoice item
$db->insert('invoice_items', [
    'invoice_id' => $invoiceId,
    'product_id' => $product['id'],
    'description' => 'Test Product',
    'quantity' => 1,
    'unit_price' => 100.00,
    'line_total' => 100.00,
    'tax_id' => null,
    'tax_percent' => 15.00
]);

echo "✓ Invoice item added\n\n";

// Test fiscalization
echo "Testing fiscalization...\n";
try {
    // Check if fiscal day is open
    $fiscalDay = $primaryDb->getRow(
        "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND status = 'FiscalDayOpened'",
        [':branch_id' => $branchId]
    );
    
    if (!$fiscalDay) {
        echo "Opening fiscal day...\n";
        $fiscalService = new FiscalService($branchId);
        $fiscalService->openFiscalDay();
        echo "✓ Fiscal day opened\n";
    }
    
    // Fiscalize invoice
    echo "Fiscalizing invoice...\n";
    fiscalizeInvoice($invoiceId, $db);
    
    // Check if fiscalized
    $invoice = $db->getRow("SELECT fiscalized, fiscal_details FROM invoices WHERE id = :id", [':id' => $invoiceId]);
    
    if ($invoice['fiscalized']) {
        echo "✓ Invoice fiscalized successfully!\n";
        $fiscalDetails = json_decode($invoice['fiscal_details'], true);
        echo "  Receipt Global No: " . ($fiscalDetails['receipt_global_no'] ?? 'N/A') . "\n";
        echo "  Verification Code: " . ($fiscalDetails['verification_code'] ?? 'N/A') . "\n";
        echo "  QR Code: " . (isset($fiscalDetails['qr_code']) ? 'Generated' : 'N/A') . "\n";
        
        // Check fiscal receipt in database
        $fiscalReceipt = $primaryDb->getRow(
            "SELECT * FROM fiscal_receipts WHERE invoice_id = :invoice_id",
            [':invoice_id' => $invoiceId]
        );
        
        if ($fiscalReceipt) {
            echo "  ✓ Fiscal receipt saved to database\n";
            echo "  Receipt ID: " . ($fiscalReceipt['receipt_id'] ?? 'N/A') . "\n";
            echo "  Status: " . ($fiscalReceipt['status'] ?? 'N/A') . "\n";
        }
    } else {
        echo "✗ Invoice not fiscalized\n";
    }
    
} catch (Exception $e) {
    echo "✗ Fiscalization failed: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
echo "\nNext: Check PDF receipt for fiscal details and QR code\n";

