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

// Build category mapping for instructions
$categoryMapping = [];
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
    
    $categoryMapping[] = [
        'id' => $cat['id'],
        'name' => $cat['name'],
        'is_general' => $isGeneral,
        'is_unique' => $isUnique
    ];
}

// Create CSV content
$csvContent = [];
$headers = [
    'Category Name', // Must match exactly with category name in system
    'Branch Name', // Must match exactly with branch name
    'Product Name', // Required for General category, leave empty for others
    'Brand', // Required for non-General categories, leave empty for General
    'Model', // Required for non-General categories, leave empty for General
    'Color', // Optional (hex code like #FF0000 or color name)
    'Storage', // For smartphones/laptops/tablets (e.g., "128GB", "256GB")
    'Battery Health', // For smartphones/tablets/wearables (0-100)
    'SIM Configuration', // For smartphones (e.g., "Dual SIM", "Single SIM")
    'Serial Number', // For unique products (smartphones/laptops) - one per product
    'IMEI', // For smartphones - one per product
    'Batch Number', // For General category products with batch tracking
    'Expiry Date', // For General category (YYYY-MM-DD format)
    'Weight', // For General category (numeric, e.g., 2.5)
    'Unit of Measure', // For General category (e.g., "kg", "g", "L", "ml")
    'Manufacturer', // For General category
    'Barcode', // Optional (product barcode)
    'Description', // Optional (product description)
    'Specifications', // Optional (detailed specifications)
    'Cost Price', // Required (numeric, e.g., 10.50)
    'Selling Price', // Required (numeric, e.g., 15.00)
    'Quantity in Stock', // Required (numeric, will be forced to 1 for unique products)
    'Reorder Level', // Required (numeric, will be forced to 0 for unique products)
    'Tax ID', // Optional (numeric tax ID from fiscal config)
    'Status' // Active or Inactive (default: Active)
];

$csvContent[] = $headers;

// Add example rows for each category type
$exampleRows = [];

// Example 1: General category product
$generalCategory = null;
foreach ($categoryMapping as $cat) {
    if ($cat['is_general']) {
        $generalCategory = $cat;
        break;
    }
}
if ($generalCategory) {
    $exampleRows[] = [
        $generalCategory['name'], // Category Name
        !empty($branches) ? $branches[0]['branch_name'] : 'Main Branch', // Branch Name
        'Sugar White 2kg', // Product Name (required for General)
        '', // Brand (empty for General)
        '', // Model (empty for General)
        '#FFFFFF', // Color
        '', // Storage (not used for General)
        '', // Battery Health (not used for General)
        '', // SIM Configuration (not used for General)
        '', // Serial Number (not used for General)
        '', // IMEI (not used for General)
        'BATCH001', // Batch Number (optional for General)
        date('Y-m-d', strtotime('+1 year')), // Expiry Date
        '2.5', // Weight
        'kg', // Unit of Measure
        'Manufacturer Name', // Manufacturer
        '1234567890123', // Barcode
        'White granulated sugar', // Description
        '2kg pack', // Specifications
        '5.00', // Cost Price
        '8.00', // Selling Price
        '50', // Quantity in Stock
        '10', // Reorder Level
        '', // Tax ID (optional)
        'Active' // Status
    ];
}

// Example 2: Smartphone (unique product)
$smartphoneCategory = null;
foreach ($categoryMapping as $cat) {
    if (strpos(strtolower($cat['name']), 'smartphone') !== false || strpos(strtolower($cat['name']), 'phone') !== false) {
        $smartphoneCategory = $cat;
        break;
    }
}
if ($smartphoneCategory) {
    $exampleRows[] = [
        $smartphoneCategory['name'], // Category Name
        !empty($branches) ? $branches[0]['branch_name'] : 'Main Branch', // Branch Name
        '', // Product Name (empty for non-General)
        'Apple', // Brand (required)
        'iPhone 15 Pro', // Model (required)
        'Space Gray', // Color
        '256GB', // Storage
        '100', // Battery Health
        'Dual SIM', // SIM Configuration
        'SN123456789', // Serial Number (required for unique products)
        '123456789012345', // IMEI (required for smartphones)
        '', // Batch Number (not used)
        '', // Expiry Date (not used)
        '', // Weight (not used)
        '', // Unit of Measure (not used)
        '', // Manufacturer (not used)
        '1234567890124', // Barcode
        'Latest iPhone model', // Description
        'A17 Pro chip, 6.1 inch display', // Specifications
        '800.00', // Cost Price
        '1200.00', // Selling Price
        '1', // Quantity in Stock (will be forced to 1 for unique products)
        '0', // Reorder Level (will be forced to 0 for unique products)
        '', // Tax ID (optional)
        'Active' // Status
    ];
}

// Example 3: Laptop (unique product)
$laptopCategory = null;
foreach ($categoryMapping as $cat) {
    if (strpos(strtolower($cat['name']), 'laptop') !== false) {
        $laptopCategory = $cat;
        break;
    }
}
if ($laptopCategory) {
    $exampleRows[] = [
        $laptopCategory['name'], // Category Name
        !empty($branches) ? $branches[0]['branch_name'] : 'Main Branch', // Branch Name
        '', // Product Name (empty for non-General)
        'Dell', // Brand
        'XPS 15', // Model
        'Silver', // Color
        '512GB SSD', // Storage
        '', // Battery Health (optional for laptops)
        '', // SIM Configuration (not used)
        'SN987654321', // Serial Number (required for unique products)
        '', // IMEI (not used for laptops)
        '', // Batch Number (not used)
        '', // Expiry Date (not used)
        '', // Weight (not used)
        '', // Unit of Measure (not used)
        '', // Manufacturer (not used)
        '1234567890125', // Barcode
        'Premium laptop', // Description
        'Intel i7, 16GB RAM, 15.6 inch', // Specifications
        '1200.00', // Cost Price
        '1800.00', // Selling Price
        '1', // Quantity in Stock (will be forced to 1)
        '0', // Reorder Level (will be forced to 0)
        '', // Tax ID (optional)
        'Active' // Status
    ];
}

// Add example rows to CSV
foreach ($exampleRows as $row) {
    $csvContent[] = $row;
}

// Add a few empty rows for user to fill
for ($i = 0; $i < 3; $i++) {
    $csvContent[] = array_fill(0, count($headers), '');
}

// Output CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="product_bulk_upload_template_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Add BOM for UTF-8 Excel compatibility
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

foreach ($csvContent as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit;

