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
$userId = $_SESSION['user_id'] ?? null;
$branchId = $_SESSION['branch_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (empty($_FILES['csv_file']['tmp_name'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

// Validate file type
$fileExtension = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
if (!in_array($fileExtension, ['csv', 'txt'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Please upload a CSV file.']);
    exit;
}

// Read CSV file
$csvFile = $_FILES['csv_file']['tmp_name'];
$handle = fopen($csvFile, 'r');
if ($handle === false) {
    echo json_encode(['success' => false, 'message' => 'Failed to read uploaded file']);
    exit;
}

// Check for BOM
$firstBytes = fread($handle, 3);
$hasBOM = ($firstBytes === "\xEF\xBB\xBF");

// Always rewind to start
rewind($handle);

// Skip BOM if present
if ($hasBOM) {
    fseek($handle, 3);
}

// Read header row
$headers = fgetcsv($handle);
if ($headers === false || empty($headers)) {
    fclose($handle);
    error_log("BULK UPLOAD ERROR: Failed to read CSV headers. File: " . $_FILES['csv_file']['name']);
    echo json_encode(['success' => false, 'message' => 'Invalid CSV format. Header row not found.']);
    exit;
}

// Normalize headers (trim whitespace, handle BOM, remove quotes)
$headers = array_map(function($h) {
    // Remove BOM if present
    if (substr($h, 0, 3) === "\xEF\xBB\xBF") {
        $h = substr($h, 3);
    }
    // Trim whitespace and remove surrounding quotes
    $h = trim($h);
    if ((substr($h, 0, 1) === '"' && substr($h, -1) === '"') || 
        (substr($h, 0, 1) === "'" && substr($h, -1) === "'")) {
        $h = substr($h, 1, -1);
    }
    return trim($h);
}, $headers);

// Log headers for debugging (only if error occurs)
error_log("BULK UPLOAD DEBUG: Headers read: " . json_encode($headers));

// Expected headers
$expectedHeaders = [
    'Category Name', 'Branch Name', 'Product Name', 'Brand', 'Model', 'Color',
    'Storage', 'Battery Health', 'SIM Configuration', 'Serial Number', 'IMEI',
    'Batch Number', 'Expiry Date', 'Weight', 'Unit of Measure', 'Manufacturer',
    'Barcode', 'Description', 'Specifications', 'Cost Price', 'Selling Price',
    'Quantity in Stock', 'Reorder Level', 'Tax ID', 'Status'
];

// Validate headers - use case-insensitive matching for robustness
$headerMap = [];
$missingHeaders = [];
$headersLower = array_map('strtolower', $headers);

foreach ($expectedHeaders as $expected) {
    // First try exact match
    $index = array_search($expected, $headers);
    
    // If not found, try case-insensitive match
    if ($index === false) {
        $expectedLower = strtolower($expected);
        $index = array_search($expectedLower, $headersLower);
    }
    
    // If still not found, try with trimmed spaces
    if ($index === false) {
        foreach ($headers as $i => $h) {
            if (strcasecmp(trim($h), trim($expected)) === 0) {
                $index = $i;
                break;
            }
        }
    }
    
    if ($index === false) {
        $missingHeaders[] = $expected;
    } else {
        $headerMap[$expected] = $index;
    }
}

// If any headers are missing, return detailed error
if (!empty($missingHeaders)) {
    fclose($handle);
    $missingList = implode(', ', $missingHeaders);
    $foundHeaders = implode(', ', $headers);
    error_log("BULK UPLOAD ERROR: Missing headers: $missingList");
    error_log("BULK UPLOAD ERROR: Found headers: $foundHeaders");
    echo json_encode([
        'success' => false, 
        'message' => "Missing required column(s): $missingList",
        'debug' => [
            'expected' => $expectedHeaders,
            'found' => $headers,
            'missing' => $missingHeaders
        ]
    ]);
    exit;
}

// Get all categories and branches for lookup (cache once)
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
$categoryMap = [];
foreach ($categories as $cat) {
    $categoryMap[strtolower(trim($cat['name']))] = $cat;
}

$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
$branchMap = [];
foreach ($branches as $branch) {
    $branchMap[strtolower(trim($branch['branch_name']))] = $branch;
}

// Get all applicable taxes (cache once)
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
$taxMap = [];
foreach ($allTaxes as $tax) {
    $taxMap[$tax['taxID']] = $tax;
}

// PASS 1: Validate ALL rows first (no database operations - fast)
$rowsToProcess = [];
$results = [
    'success' => [],
    'errors' => []
];

$rowNumber = 1; // Start at 1 (header is row 0)

while (($row = fgetcsv($handle)) !== false) {
    $rowNumber++;
    
    // Skip empty rows
    if (empty(array_filter($row))) {
        continue;
    }
    
    // Get values from CSV
    $getValue = function($headerName) use ($row, $headerMap) {
        $index = $headerMap[$headerName] ?? -1;
        return $index >= 0 && isset($row[$index]) ? trim($row[$index]) : '';
    };
    
    $categoryName = $getValue('Category Name');
    $branchName = $getValue('Branch Name');
    $productName = $getValue('Product Name');
    $brand = $getValue('Brand');
    $model = $getValue('Model');
    $color = $getValue('Color');
    $storage = $getValue('Storage');
    $batteryHealth = $getValue('Battery Health');
    $simConfig = $getValue('SIM Configuration');
    $serialNumber = $getValue('Serial Number');
    $imei = $getValue('IMEI');
    $batchNumber = $getValue('Batch Number');
    $expiryDate = $getValue('Expiry Date');
    $weight = $getValue('Weight');
    $unitOfMeasure = $getValue('Unit of Measure');
    $manufacturer = $getValue('Manufacturer');
    $barcode = $getValue('Barcode');
    $description = $getValue('Description');
    $specifications = $getValue('Specifications');
    $costPrice = $getValue('Cost Price');
    $sellingPrice = $getValue('Selling Price');
    $quantityInStock = $getValue('Quantity in Stock');
    $reorderLevel = $getValue('Reorder Level');
    $taxId = $getValue('Tax ID');
    $status = $getValue('Status');
    
    // Validate required fields
    $errors = [];
    
    if (empty($categoryName)) {
        $errors[] = "Category Name is required";
    } else {
        $category = $categoryMap[strtolower($categoryName)] ?? null;
        if (!$category) {
            $errors[] = "Category '$categoryName' not found";
        }
    }
    
    if (empty($branchName)) {
        $errors[] = "Branch Name is required";
    } else {
        $branch = $branchMap[strtolower($branchName)] ?? null;
        if (!$branch) {
            $errors[] = "Branch '$branchName' not found";
        }
    }
    
    if ($category) {
        $categoryNameLower = strtolower($category['name']);
        $isGeneralCategory = (strpos($categoryNameLower, 'general') !== false || 
                              strpos($categoryNameLower, 'grocery') !== false || 
                              strpos($categoryNameLower, 'food') !== false ||
                              strpos($categoryNameLower, 'consumable') !== false ||
                              strpos($categoryNameLower, 'beverage') !== false);
        
        if ($isGeneralCategory) {
            if (empty($productName)) {
                $errors[] = "Product Name is required for General category";
            }
        } else {
            if (empty($brand)) {
                $errors[] = "Brand is required for non-General categories";
            }
            if (empty($model)) {
                $errors[] = "Model is required for non-General categories";
            }
        }
    }
    
    if ($costPrice === '' || $costPrice === null || !is_numeric($costPrice)) {
        $errors[] = "Cost Price is required and must be numeric";
    }
    
    if ($sellingPrice === '' || $sellingPrice === null || !is_numeric($sellingPrice)) {
        $errors[] = "Selling Price is required and must be numeric";
    }
    
    if ($quantityInStock === '' || $quantityInStock === null || !is_numeric($quantityInStock)) {
        $errors[] = "Quantity in Stock is required and must be numeric";
    }
    
    if ($reorderLevel === '' || $reorderLevel === null || !is_numeric($reorderLevel)) {
        $errors[] = "Reorder Level is required and must be numeric";
    }
    
    // If there are errors, add to errors list
    if (!empty($errors)) {
        $results['errors'][] = [
            'row' => $rowNumber,
            'errors' => $errors,
            'data' => [
                'category' => $categoryName,
                'branch' => $branchName,
                'product' => $productName ?: ($brand . ' ' . $model)
            ]
        ];
    } else {
        // Store validated row data for processing
        $rowsToProcess[] = [
            'row' => $rowNumber,
            'category' => $category,
            'branch' => $branch,
            'productName' => $productName,
            'brand' => $brand,
            'model' => $model,
            'color' => $color,
            'storage' => $storage,
            'batteryHealth' => $batteryHealth,
            'simConfig' => $simConfig,
            'serialNumber' => $serialNumber,
            'imei' => $imei,
            'batchNumber' => $batchNumber,
            'expiryDate' => $expiryDate,
            'weight' => $weight,
            'unitOfMeasure' => $unitOfMeasure,
            'manufacturer' => $manufacturer,
            'barcode' => $barcode,
            'description' => $description,
            'specifications' => $specifications,
            'costPrice' => $costPrice,
            'sellingPrice' => $sellingPrice,
            'quantityInStock' => $quantityInStock,
            'reorderLevel' => $reorderLevel,
            'taxId' => $taxId,
            'status' => $status
        ];
    }
}

fclose($handle);

// If ANY errors exist, return them without creating any products (all-or-nothing)
if (!empty($results['errors'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Validation failed. Please fix errors and try again. No products were created.',
        'results' => $results,
        'summary' => [
            'total_rows' => count($results['errors']) + count($rowsToProcess),
            'successful' => 0,
            'errors' => count($results['errors'])
        ]
    ]);
    exit;
}

// PASS 2: All rows validated - now create products in transaction (all-or-nothing)
$db->beginTransaction();

try {
    foreach ($rowsToProcess as $rowData) {
        $category = $rowData['category'];
        $branch = $rowData['branch'];
        $categoryNameLower = strtolower($category['name']);
        $isGeneralCategory = (strpos($categoryNameLower, 'general') !== false || 
                              strpos($categoryNameLower, 'grocery') !== false || 
                              strpos($categoryNameLower, 'food') !== false ||
                              strpos($categoryNameLower, 'consumable') !== false ||
                              strpos($categoryNameLower, 'beverage') !== false);
        $isUniqueProduct = (strpos($categoryNameLower, 'smartphone') !== false || 
                          strpos($categoryNameLower, 'phone') !== false || 
                          strpos($categoryNameLower, 'laptop') !== false ||
                          strpos($categoryNameLower, 'tablet') !== false) ||
                          !empty($rowData['serialNumber']) || !empty($rowData['imei']);
        
        // CRITICAL: Force qty=1 and reorder_level=0 for unique products
        $quantityInStock = $rowData['quantityInStock'];
        $reorderLevel = $rowData['reorderLevel'];
        if ($isUniqueProduct) {
            $quantityInStock = 1;
            $reorderLevel = 0;
        }
        
        // Get serial number and IMEI
        $serialNumber = !empty($rowData['serialNumber']) ? sanitizeInput($rowData['serialNumber']) : null;
        $imei = !empty($rowData['imei']) ? sanitizeInput($rowData['imei']) : null;
        
        // Validate for duplicate serial numbers
        if (!empty($serialNumber)) {
            $existingSerial = $db->getRow("SELECT id, product_code FROM products WHERE serial_number = :serial_number", [':serial_number' => $serialNumber]);
            if ($existingSerial) {
                throw new Exception('Row ' . $rowData['row'] . ': Serial Number "' . $serialNumber . '" already exists in the database (Product Code: ' . $existingSerial['product_code'] . '). Cannot create duplicate.');
            }
        }
        
        // Validate for duplicate IMEI
        if (!empty($imei)) {
            $existingImei = $db->getRow("SELECT id, product_code FROM products WHERE imei = :imei", [':imei' => $imei]);
            if ($existingImei) {
                throw new Exception('Row ' . $rowData['row'] . ': IMEI "' . $imei . '" already exists in the database (Product Code: ' . $existingImei['product_code'] . '). Cannot create duplicate.');
            }
        }
        
        // Prepare product data
        $productData = [
            'product_code' => generateProductCode(),
            'category_id' => $category['id'],
            'product_name' => $isGeneralCategory ? sanitizeInput($rowData['productName']) : null,
            'brand' => $isGeneralCategory ? null : sanitizeInput($rowData['brand']),
            'model' => $isGeneralCategory ? null : sanitizeInput($rowData['model']),
            'color' => sanitizeInput($rowData['color']),
            'storage' => sanitizeInput($rowData['storage']),
            'sim_configuration' => sanitizeInput($rowData['simConfig']),
            'serial_number' => $serialNumber,
            'imei' => $imei,
            'batch_number' => sanitizeInput($rowData['batchNumber']),
            'expiry_date' => !empty($rowData['expiryDate']) ? date('Y-m-d', strtotime($rowData['expiryDate'])) : null,
            'weight' => !empty($rowData['weight']) && is_numeric($rowData['weight']) ? floatval($rowData['weight']) : null,
            'unit_of_measure' => sanitizeInput($rowData['unitOfMeasure']),
            'manufacturer' => sanitizeInput($rowData['manufacturer']),
            'barcode' => sanitizeInput($rowData['barcode']),
            'description' => sanitizeInput($rowData['description']),
            'specifications' => sanitizeInput($rowData['specifications']),
            'cost_price' => floatval($rowData['costPrice']),
            'selling_price' => floatval($rowData['sellingPrice']),
            'quantity_in_stock' => intval($quantityInStock),
            'reorder_level' => intval($reorderLevel),
            'branch_id' => $branch['id'],
            'tax_id' => !empty($rowData['taxId']) && is_numeric($rowData['taxId']) && isset($taxMap[$rowData['taxId']]) ? intval($rowData['taxId']) : null,
            'status' => !empty($rowData['status']) && strtolower($rowData['status']) === 'inactive' ? 'Inactive' : 'Active',
            'battery_health' => !empty($rowData['batteryHealth']) && is_numeric($rowData['batteryHealth']) ? intval($rowData['batteryHealth']) : null,
            'created_by' => $userId,
            'source' => 'bulk_upload',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Insert product
        $productId = $db->insert('products', $productData);
        
        if (!$productId) {
            throw new Exception('Failed to create product on row ' . $rowData['row'] . ': ' . $db->getLastError());
        }
        
        $results['success'][] = [
            'row' => $rowData['row'],
            'product_id' => $productId,
            'product_code' => $productData['product_code'],
            'product' => $rowData['productName'] ?: ($rowData['brand'] . ' ' . $rowData['model']),
            'category' => $category['name'],
            'branch' => $branch['branch_name'],
            'is_unique' => $isUniqueProduct,
            'quantity' => $productData['quantity_in_stock'],
            'reorder_level' => $productData['reorder_level']
        ];
    }
    
    // Commit transaction only if all products created successfully
    $db->commitTransaction();
    
    // Prepare response
    $totalProcessed = count($results['success']);
    $message = "Successfully created {$totalProcessed} product(s).";
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'results' => $results,
        'summary' => [
            'total_rows' => $totalProcessed,
            'successful' => count($results['success']),
            'errors' => 0,
            'skipped' => 0
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on any error (all-or-nothing)
    $db->rollbackTransaction();
    echo json_encode([
        'success' => false,
        'message' => 'Error processing bulk upload: ' . $e->getMessage() . '. All changes have been rolled back - no products were created.',
        'results' => $results,
        'summary' => [
            'total_rows' => count($rowsToProcess),
            'successful' => 0,
            'errors' => 1
        ]
    ]);
}
