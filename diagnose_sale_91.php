<?php
/**
 * Diagnose why sale 91 wasn't fiscalized
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/fiscal_helper.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

echo "=== Diagnosing Sale 91 ===\n\n";

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Check sale
$sale = $db->getRow("SELECT * FROM sales WHERE id = 91");
if (!$sale) {
    echo "✗ Sale 91 not found\n";
    exit(1);
}

echo "✓ Sale 91 found:\n";
echo "  Branch ID: {$sale['branch_id']}\n";
echo "  Total: {$sale['total_amount']}\n";
echo "  Fiscalized: " . ($sale['fiscalized'] ?? 'NULL') . "\n";
echo "  Created: {$sale['created_at']}\n\n";

// Check branch
$branch = $primaryDb->getRow("SELECT id, branch_name, fiscalization_enabled FROM branches WHERE id = :id", [':id' => $sale['branch_id']]);
if (!$branch) {
    echo "✗ Branch {$sale['branch_id']} not found\n";
    exit(1);
}

echo "✓ Branch found:\n";
echo "  Name: {$branch['branch_name']}\n";
echo "  Fiscalization Enabled: " . ($branch['fiscalization_enabled'] ? 'YES' : 'NO') . "\n\n";

// Check if already fiscalized
$fiscalReceipt = $primaryDb->getRow("SELECT * FROM fiscal_receipts WHERE sale_id = 91");
if ($fiscalReceipt) {
    echo "⚠ Sale 91 is already fiscalized:\n";
    echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
    echo "  Verification Code: {$fiscalReceipt['receipt_verification_code']}\n";
    exit(0);
}

// Try to fiscalize
echo "Attempting to fiscalize sale 91...\n\n";

try {
    error_log("DIAGNOSE: Starting fiscalization for sale 91");
    $result = fiscalizeSale(91, $sale['branch_id'], $db);
    error_log("DIAGNOSE: Fiscalization result: " . var_export($result, true));
    
    if ($result && is_array($result)) {
        echo "✓ Fiscalization successful!\n";
        echo "  Receipt ID: " . ($result['receiptID'] ?? 'N/A') . "\n";
        echo "  Receipt Global No: " . ($result['receiptGlobalNo'] ?? 'N/A') . "\n";
        
        // Verify
        $fiscalReceipt = $primaryDb->getRow("SELECT * FROM fiscal_receipts WHERE sale_id = 91");
        if ($fiscalReceipt) {
            echo "\n✓ Fiscal receipt created in database\n";
            echo "  Receipt Global No: {$fiscalReceipt['receipt_global_no']}\n";
            echo "  Has QR Code: " . (!empty($fiscalReceipt['receipt_qr_code']) ? 'Yes' : 'No') . "\n";
        }
        
        $updatedSale = $db->getRow("SELECT fiscalized, fiscal_details FROM sales WHERE id = 91");
        if ($updatedSale && $updatedSale['fiscalized']) {
            echo "\n✓ Sale record updated: fiscalized = 1\n";
        }
    } else {
        echo "✗ Fiscalization returned false or invalid result\n";
        echo "Result: " . var_export($result, true) . "\n";
    }
} catch (Exception $e) {
    echo "✗ Fiscalization failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    error_log("DIAGNOSE ERROR: " . $e->getMessage());
    error_log("DIAGNOSE STACK: " . $e->getTraceAsString());
}

