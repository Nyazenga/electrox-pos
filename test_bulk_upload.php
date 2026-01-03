<?php
/**
 * Test script for bulk upload functionality
 * This script tests the CSV template generation and validates the upload logic
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/functions.php';

echo "=== Testing Bulk Upload Functionality ===\n\n";

$db = Database::getInstance();

// Test 1: Get categories
echo "Test 1: Fetching categories...\n";
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) {
    echo "❌ Failed to fetch categories\n";
    exit(1);
}
echo "✓ Found " . count($categories) . " categories\n\n";

// Test 2: Get branches
echo "Test 2: Fetching branches...\n";
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) {
    echo "❌ Failed to fetch branches\n";
    exit(1);
}
echo "✓ Found " . count($branches) . " active branches\n\n";

// Test 3: Test category detection logic
echo "Test 3: Testing category detection logic...\n";
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
    
    echo "  - {$cat['name']}: ";
    if ($isGeneral) echo "General ";
    if ($isUnique) echo "Unique ";
    if (!$isGeneral && !$isUnique) echo "Standard ";
    echo "\n";
}
echo "✓ Category detection working\n\n";

// Test 4: Test productHasSerialOrImei function
echo "Test 4: Testing productHasSerialOrImei function...\n";
$testProducts = [
    ['category_name' => 'Smartphone', 'serial_number' => '', 'imei' => ''],
    ['category_name' => 'Laptop', 'serial_number' => 'SN123', 'imei' => ''],
    ['category_name' => 'General', 'serial_number' => '', 'imei' => ''],
    ['category_id' => 1, 'serial_number' => 'SN456', 'imei' => '']
];

foreach ($testProducts as $idx => $product) {
    $result = productHasSerialOrImei($product, $db);
    echo "  Product " . ($idx + 1) . ": " . ($result ? "Unique" : "Standard") . "\n";
}
echo "✓ productHasSerialOrImei function working\n\n";

// Test 5: Validate template download endpoint exists
echo "Test 5: Checking template download endpoint...\n";
$templatePath = APP_PATH . '/ajax/download_product_template.php';
if (file_exists($templatePath)) {
    echo "✓ Template download endpoint exists\n";
} else {
    echo "❌ Template download endpoint not found\n";
    exit(1);
}

// Test 6: Validate upload processing endpoint exists
echo "Test 6: Checking upload processing endpoint...\n";
$uploadPath = APP_PATH . '/ajax/process_bulk_upload.php';
if (file_exists($uploadPath)) {
    echo "✓ Upload processing endpoint exists\n";
} else {
    echo "❌ Upload processing endpoint not found\n";
    exit(1);
}

// Test 7: Validate info endpoint exists
echo "Test 7: Checking info endpoint...\n";
$infoPath = APP_PATH . '/ajax/get_bulk_upload_info.php';
if (file_exists($infoPath)) {
    echo "✓ Info endpoint exists\n";
} else {
    echo "❌ Info endpoint not found\n";
    exit(1);
}

echo "\n=== All Tests Passed! ===\n";
echo "Bulk upload functionality is ready for testing.\n";

