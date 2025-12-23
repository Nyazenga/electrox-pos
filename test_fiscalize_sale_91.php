<?php
/**
 * Test fiscalization for sale 91
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_helper.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

echo "=== Testing Fiscalization for Sale 91 ===\n\n";

$db = Database::getInstance();

// Get sale
$sale = $db->getRow("SELECT * FROM sales WHERE id = 91");

if (!$sale) {
    echo "✗ Sale 91 not found\n";
    exit;
}

echo "✓ Sale 91 found:\n";
echo "  Branch ID: {$sale['branch_id']}\n";
echo "  Total: {$sale['total_amount']}\n";
echo "  Fiscalized: " . ($sale['fiscalized'] ?? 'NULL') . "\n\n";

// Check branch
$primaryDb = Database::getPrimaryInstance();
$branch = $primaryDb->getRow("SELECT id, branch_name, fiscalization_enabled FROM branches WHERE id = :id", [':id' => $sale['branch_id']]);

if (!$branch) {
    echo "✗ Branch {$sale['branch_id']} not found\n";
    exit;
}

echo "✓ Branch found:\n";
echo "  Name: {$branch['branch_name']}\n";
echo "  Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'YES' : 'NO') . "\n\n";

if (!$branch['fiscalization_enabled']) {
    echo "✗ Fiscalization is not enabled for this branch\n";
    exit;
}

// Try to fiscalize
echo "Attempting to fiscalize sale 91...\n\n";

try {
    $result = fiscalizeSale(91, $sale['branch_id'], $db);
    
    if ($result && is_array($result)) {
        echo "✓ Fiscalization successful!\n";
        echo "  Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
        echo "  Receipt Global No: " . ($result['receiptGlobalNo'] ?? 'N/A') . "\n";
        echo "  QR Code: " . (isset($result['qrCode']) ? 'Yes' : 'No') . "\n";
        
        // Check fiscal receipt
        $fiscalReceipt = $primaryDb->getRow(
            "SELECT * FROM fiscal_receipts WHERE sale_id = 91 ORDER BY id DESC LIMIT 1"
        );
        
        if ($fiscalReceipt) {
            echo "\n✓ Fiscal receipt created:\n";
            echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
            echo "  Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
            echo "  Has QR Code: " . (!empty($fiscalReceipt['receipt_qr_code']) ? 'Yes' : 'No') . "\n";
        }
        
        // Check sale record
        $updatedSale = $db->getRow("SELECT fiscalized, fiscal_details FROM sales WHERE id = 91");
        if ($updatedSale) {
            echo "\nSale record updated:\n";
            echo "  Fiscalized: {$updatedSale['fiscalized']}\n";
            echo "  Fiscal Details: " . (empty($updatedSale['fiscal_details']) ? 'None' : 'Present') . "\n";
        }
    } else {
        echo "✗ Fiscalization returned false or invalid result\n";
        echo "Result: " . var_export($result, true) . "\n";
    }
} catch (Exception $e) {
    echo "✗ Fiscalization failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

