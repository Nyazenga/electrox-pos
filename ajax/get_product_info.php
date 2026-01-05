<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get product with category and category tax_id
$product = $db->getRow(
    "SELECT p.*, pc.name as category_name, pc.tax_id as category_tax_id 
     FROM products p 
     LEFT JOIN product_categories pc ON p.category_id = pc.id 
     WHERE p.id = :id", 
    [':id' => $id]
);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

// Get tax information for display
$taxDisplay = '-';
$productTaxId = $product['tax_id'] ?? null;
$categoryTaxId = $product['category_tax_id'] ?? null;
$finalTaxId = $productTaxId ?: $categoryTaxId;

if ($finalTaxId && $product['branch_id']) {
    // Get applicable taxes from fiscal_config
    $fiscalConfig = $primaryDb->getRow(
        "SELECT applicable_taxes FROM fiscal_config WHERE branch_id = :branch_id LIMIT 1",
        [':branch_id' => $product['branch_id']]
    );
    
    if ($fiscalConfig && !empty($fiscalConfig['applicable_taxes'])) {
        $applicableTaxes = json_decode($fiscalConfig['applicable_taxes'], true);
        if (is_array($applicableTaxes)) {
            // Filter out 5% Non-VAT Withholding Tax
            require_once APP_PATH . '/includes/fiscal_helper.php';
            $applicableTaxes = filterOut5PercentTax($applicableTaxes);
            
            // Find matching tax by taxID
            foreach ($applicableTaxes as $tax) {
                if (isset($tax['taxID']) && intval($tax['taxID']) == intval($finalTaxId)) {
                    $taxPercent = isset($tax['taxPercent']) ? floatval($tax['taxPercent']) : null;
                    $taxName = $tax['taxName'] ?? 'Tax';
                    
                    if ($taxPercent !== null) {
                        $taxDisplay = $taxName . ' (' . number_format($taxPercent, 2) . '%)';
                    } else {
                        $taxDisplay = $taxName . ' (Exempt)';
                    }
                    break;
                }
            }
        }
    }
}

// Add tax display to product array
$product['tax'] = $taxDisplay;

// Get stock in other branches if user has permission
$stockInOtherBranches = [];
if ($auth->hasPermission('inventory.view_other_branches')) {
    $currentBranchId = $_SESSION['branch_id'] ?? null;
    $stockInOtherBranches = $db->getRows(
        "SELECT b.id, b.branch_name, 
                COALESCE(SUM(CASE WHEN p2.id = :product_id THEN p2.quantity_in_stock ELSE 0 END), 0) as stock
         FROM branches b
         LEFT JOIN products p2 ON p2.branch_id = b.id AND p2.id = :product_id
         WHERE b.status = 'Active' AND b.id != :current_branch_id
         GROUP BY b.id, b.branch_name
         ORDER BY b.branch_name",
        [
            ':product_id' => $id,
            ':current_branch_id' => $currentBranchId
        ]
    );
    if ($stockInOtherBranches === false) {
        $stockInOtherBranches = [];
    }
}

$result = ['success' => true, 'product' => $product];
if (!empty($stockInOtherBranches)) {
    $result['stock_in_other_branches'] = $stockInOtherBranches;
}

echo json_encode($result);

