<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();
$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.create');

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get all categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Get all branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get all applicable taxes
function getAllApplicableTaxes($primaryDb) {
    $configs = $primaryDb->getRows(
        "SELECT DISTINCT applicable_taxes FROM fiscal_config WHERE applicable_taxes IS NOT NULL AND applicable_taxes != ''"
    );
    
    $allTaxes = [];
    $seenTaxIds = [];
    
    foreach ($configs as $config) {
        $taxes = json_decode($config['applicable_taxes'], true);
        if (is_array($taxes)) {
            foreach ($taxes as $tax) {
                $taxId = $tax['taxID'] ?? null;
                if ($taxId && !in_array($taxId, $seenTaxIds)) {
                    $allTaxes[] = $tax;
                    $seenTaxIds[] = $taxId;
                }
            }
        }
    }
    
    return $allTaxes;
}

$allTaxes = getAllApplicableTaxes($primaryDb);

// Build category information
$categoryInfo = [];
foreach ($categories as $cat) {
    $catName = strtolower($cat['name']);
    $isGeneral = (strpos($catName, 'general') !== false || 
                  strpos($catName, 'grocery') !== false || 
                  strpos($catName, 'food') !== false ||
                  strpos($catName, 'consumable') !== false ||
                  strpos($catName, 'beverage') !== false);
    $isUnique = (strpos($catName, 'smartphone') !== false || 
                strpos($catName, 'phone') !== false || 
                strpos($catName, 'laptop') !== false ||
                strpos($catName, 'tablet') !== false);
    
    $categoryInfo[] = [
        'id' => $cat['id'],
        'name' => $cat['name'],
        'is_general' => $isGeneral,
        'is_unique' => $isUnique
    ];
}

// Build branch information
$branchInfo = [];
foreach ($branches as $branch) {
    $branchInfo[] = [
        'id' => $branch['id'],
        'branch_name' => $branch['branch_name']
    ];
}

// Build tax information
$taxInfo = [];
foreach ($allTaxes as $tax) {
    $taxInfo[] = [
        'taxID' => $tax['taxID'] ?? null,
        'taxName' => $tax['taxName'] ?? 'Tax',
        'taxPercent' => $tax['taxPercent'] ?? 0,
        'taxCode' => $tax['taxCode'] ?? ''
    ];
}

echo json_encode([
    'success' => true,
    'categories' => $categoryInfo,
    'branches' => $branchInfo,
    'taxes' => $taxInfo
]);

