<?php
require_once dirname(__FILE__) . '/../config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/settings_functions.php';

initSession();

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

header('Content-Type: application/json');

$branchId = intval($_GET['branch_id'] ?? 0);

if (!$branchId) {
    echo json_encode(['success' => false, 'message' => 'Branch ID is required']);
    exit;
}

try {
    $db = Database::getInstance();
    $primaryDb = Database::getPrimaryInstance();
    
    // Get products for the branch
    $products = $db->getRows("SELECT p.*, 
                             COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as display_name,
                             pc.name as category_name,
                             p.tax_id as product_tax_id,
                             pc.tax_id as category_tax_id
                             FROM products p
                             LEFT JOIN product_categories pc ON p.category_id = pc.id
                             WHERE p.status = 'Active' AND p.branch_id = :branch_id
                             ORDER BY COALESCE(p.product_name, p.brand, ''), p.model", 
                             [':branch_id' => $branchId]);
    
    if ($products === false) $products = [];
    
    // Get applicable taxes from fiscal_config
    $applicableTaxes = [];
    $fiscalConfig = $primaryDb->getRow(
        "SELECT applicable_taxes FROM fiscal_config WHERE branch_id = :branch_id LIMIT 1",
        [':branch_id' => $branchId]
    );
    if ($fiscalConfig && !empty($fiscalConfig['applicable_taxes'])) {
        $applicableTaxes = json_decode($fiscalConfig['applicable_taxes'], true);
        if (!is_array($applicableTaxes)) {
            $applicableTaxes = [];
        }
    }
    
    // Create tax map
    $taxMap = [];
    foreach ($applicableTaxes as $tax) {
        if (isset($tax['taxID'])) {
            $taxId = intval($tax['taxID']);
            $taxPercent = isset($tax['taxPercent']) ? floatval($tax['taxPercent']) : 0;
            $taxMap[$taxId] = $taxPercent;
            $taxMap[(string)$taxId] = $taxPercent;
        }
    }
    
    // Get default tax rate
    $defaultTaxRate = getDefaultTaxRate();
    
    // Add tax information to products
    foreach ($products as &$product) {
        $productTaxId = $product['product_tax_id'] ?? null;
        $categoryTaxId = $product['category_tax_id'] ?? null;
        
        $finalTaxId = $productTaxId ?: $categoryTaxId;
        $taxPercent = $defaultTaxRate;
        
        if ($finalTaxId) {
            $taxIdInt = intval($finalTaxId);
            if (isset($taxMap[$taxIdInt])) {
                $taxPercent = $taxMap[$taxIdInt];
            } elseif (isset($taxMap[(string)$taxIdInt])) {
                $taxPercent = $taxMap[(string)$taxIdInt];
            } elseif (isset($taxMap[$finalTaxId])) {
                $taxPercent = $taxMap[$finalTaxId];
            }
        }
        
        $product['tax_percent'] = $taxPercent;
        $product['tax_id'] = $finalTaxId;
    }
    unset($product);
    
    echo json_encode([
        'success' => true,
        'products' => $products
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

