<?php
/**
 * Test Sale Fiscalization
 * This script tests making a sale and verifies fiscalization
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_helper.php';

echo "=== Testing Sale Fiscalization ===\n\n";

// Get primary database
$primaryDb = Database::getPrimaryInstance();
$db = Database::getInstance();

// Check if fiscalization is enabled
$branch = $primaryDb->getRow(
    "SELECT id, branch_name, fiscalization_enabled FROM branches WHERE id = 1 LIMIT 1"
);

if (!$branch) {
    echo "✗ No branch found\n";
    exit(1);
}

echo "Branch: {$branch['branch_name']}\n";
echo "Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'Yes' : 'No') . "\n\n";

if (!$branch['fiscalization_enabled']) {
    echo "⚠ Fiscalization is not enabled for this branch\n";
    echo "Please enable it in Settings > Fiscalization (ZIMRA)\n";
    exit(1);
}

// Check device
$device = $primaryDb->getRow(
    "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
    [':branch_id' => $branch['id']]
);

if (!$device) {
    echo "✗ No active fiscal device found\n";
    exit(1);
}

echo "Device ID: {$device['device_id']}\n";
echo "Device Serial: {$device['device_serial_no']}\n";
echo "Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n\n";

// Check fiscal day
$fiscalDay = $primaryDb->getRow(
    "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status = 'FiscalDayOpened' ORDER BY id DESC LIMIT 1",
    [':branch_id' => $branch['id'], ':device_id' => $device['device_id']]
);

if (!$fiscalDay) {
    echo "⚠ No open fiscal day found. Attempting to open one...\n";
    try {
        require_once APP_PATH . '/includes/fiscal_service.php';
        $fiscalService = new FiscalService($branch['id']);
        $fiscalService->openFiscalDay();
        echo "✓ Fiscal day opened\n\n";
    } catch (Exception $e) {
        echo "✗ Failed to open fiscal day: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "✓ Fiscal day is open (Day #{$fiscalDay['fiscal_day_no']})\n\n";
}

// Get a product
$product = $db->getRow("SELECT * FROM products WHERE is_active = 1 LIMIT 1");

if (!$product) {
    echo "✗ No active products found. Please add a product first.\n";
    exit(1);
}

echo "Product: {$product['product_name']}\n";
echo "Price: {$product['selling_price']}\n\n";

// Create a test sale
$saleData = [
    'receipt_number' => 'TEST-' . date('YmdHis'),
    'shift_id' => 1,
    'branch_id' => $branch['id'],
    'user_id' => 1,
    'customer_id' => null,
    'sale_date' => date('Y-m-d H:i:s'),
    'subtotal' => $product['selling_price'],
    'discount_type' => null,
    'discount_amount' => 0,
    'tax_amount' => 0,
    'total_amount' => $product['selling_price'],
    'payment_status' => 'paid',
    'created_at' => date('Y-m-d H:i:s')
];

$saleId = $db->insert('sales', $saleData);

if (!$saleId) {
    echo "✗ Failed to create sale: " . $db->getLastError() . "\n";
    exit(1);
}

echo "✓ Sale created (ID: $saleId)\n";

// Add sale item
$saleItem = [
    'sale_id' => $saleId,
    'product_id' => $product['id'],
    'product_name' => $product['product_name'],
    'unit_price' => $product['selling_price'],
    'quantity' => 1,
    'total_price' => $product['selling_price']
];

$itemId = $db->insert('sale_items', $saleItem);
echo "✓ Sale item added (ID: $itemId)\n";

// Add payment
$payment = [
    'sale_id' => $saleId,
    'payment_method' => 'cash',
    'amount' => $product['selling_price']
];

$paymentId = $db->insert('sale_payments', $payment);
echo "✓ Payment added (ID: $paymentId)\n\n";

// Now fiscalize
echo "Attempting to fiscalize sale...\n";
try {
    fiscalizeSale($saleId, $branch['id'], $db);
    echo "✓ Sale fiscalized successfully!\n\n";
    
    // Check fiscal receipt
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT * FROM fiscal_receipts WHERE sale_id = :sale_id",
        [':sale_id' => $saleId]
    );
    
    if ($fiscalReceipt) {
        echo "Fiscal Receipt Details:\n";
        echo "  Receipt ID: {$fiscalReceipt['receipt_id']}\n";
        echo "  Global No: {$fiscalReceipt['receipt_global_no']}\n";
        echo "  Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
        echo "  Status: {$fiscalReceipt['submission_status']}\n";
        echo "  QR Code: " . (empty($fiscalReceipt['receipt_qr_code']) ? 'Not generated' : 'Generated') . "\n";
    }
    
    // Check sale fiscal details
    $sale = $db->getRow("SELECT fiscalized, fiscal_details FROM sales WHERE id = :id", [':id' => $saleId]);
    if ($sale && $sale['fiscalized']) {
        echo "\n✓ Sale marked as fiscalized\n";
        $details = json_decode($sale['fiscal_details'], true);
        if ($details) {
            echo "  Receipt Global No: " . ($details['receipt_global_no'] ?? 'N/A') . "\n";
            echo "  Device ID: " . ($details['device_id'] ?? 'N/A') . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "✗ Fiscalization failed: " . $e->getMessage() . "\n";
    echo "  Error details logged to error.log\n";
}

echo "\n=== Test Complete ===\n";
echo "\nTo view all fiscalizations, visit:\n";
echo "  http://localhost/electrox-pos/view_all_fiscalizations.php\n";
echo "\nTo check fiscalization status, visit:\n";
echo "  http://localhost/electrox-pos/check_fiscalization_status.php\n";

