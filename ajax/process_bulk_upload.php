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

// Skip BOM if present
$firstLine = fgets($handle);
if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
    rewind($handle);
    fseek($handle, 3);
}

// Read header row
$headers = fgetcsv($handle);
if ($headers === false) {
    fclose($handle);
    echo json_encode(['success' => false, 'message' => 'Invalid CSV format. Header row not found.']);
    exit;
}

// Normalize headers (trim whitespace, handle BOM)
$headers = array_map(function($h) {
    $h = trim($h);
    // Remove BOM if present
    if (substr($h, 0, 3) === "\xEF\xBB\xBF") {
        $h = substr($h, 3);
    }
    return trim($h);
}, $headers);

// Expected headers
$expectedHeaders = [
    'Category Name', 'Branch Name', 'Product Name', 'Brand', 'Model', 'Color',
    'Storage', 'Battery Health', 'SIM Configuration', 'Serial Number', 'IMEI',
    'Batch Number', 'Expiry Date', 'Weight', 'Unit of Measure', 'Manufacturer',
    'Barcode', 'Description', 'Specifications', 'Cost Price', 'Selling Price',
    'Quantity in Stock', 'Reorder Level', 'Tax ID', 'Status'
];

// Validate headers
$headerMap = [];
foreach ($expectedHeaders as $expected) {
    $index = array_search($expected, $headers);
    if ($index === false) {
        fclose($handle);
        echo json_encode(['success' => false, 'message' => "Missing required column: $expected"]);
        exit;
    }
    $headerMap[$expected] = $index;
}

// Get all categories and branches for lookup
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
$taxMap = [];
foreach ($allTaxes as $tax) {
    $taxMap[$tax['taxID']] = $tax;
}

// Process rows
$results = [
    'success' => [],
    'errors' => [],
    'skipped' => []
];

$rowNumber = 1; // Start at 1 (header is row 0)

$db->beginTransaction();

try {
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
                $errors[] = "Category '$categoryName' not found. Available categories: " . implode(', ', array_keys($categoryMap));
            }
        }
        
        if (empty($branchName)) {
            $errors[] = "Branch Name is required";
        } else {
            $branch = $branchMap[strtolower($branchName)] ?? null;
            if (!$branch) {
                $errors[] = "Branch '$branchName' not found. Available branches: " . implode(', ', array_keys($branchMap));
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
        
        if (empty($costPrice) || !is_numeric($costPrice)) {
            $errors[] = "Cost Price is required and must be numeric";
        }
        
        if (empty($sellingPrice) || !is_numeric($sellingPrice)) {
            $errors[] = "Selling Price is required and must be numeric";
        }
        
        if ($quantityInStock === '' || $quantityInStock === null || !is_numeric($quantityInStock)) {
            $errors[] = "Quantity in Stock is required and must be numeric";
        }
        
        if ($reorderLevel === '' || $reorderLevel === null || !is_numeric($reorderLevel)) {
            $errors[] = "Reorder Level is required and must be numeric";
        }
        
        // If there are errors, skip this row
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
            continue;
        }
        
        // Determine if this is a unique product and general category
        $isUniqueProduct = false;
        $isGeneralCategory = false;
        if ($category) {
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
                              !empty($serialNumber) || !empty($imei);
        }
        
        // CRITICAL: Force qty=1 and reorder_level=0 for unique products
        if ($isUniqueProduct) {
            $quantityInStock = 1;
            $reorderLevel = 0;
        }
        
        // Prepare product data
        $productData = [
            'product_code' => generateProductCode(),
            'category_id' => $category['id'],
            'product_name' => $isGeneralCategory ? sanitizeInput($productName) : null,
            'brand' => $isGeneralCategory ? null : sanitizeInput($brand),
            'model' => $isGeneralCategory ? null : sanitizeInput($model),
            'color' => sanitizeInput($color),
            'storage' => sanitizeInput($storage),
            'sim_configuration' => sanitizeInput($simConfig),
            'serial_number' => sanitizeInput($serialNumber),
            'imei' => sanitizeInput($imei),
            'batch_number' => sanitizeInput($batchNumber),
            'expiry_date' => !empty($expiryDate) ? date('Y-m-d', strtotime($expiryDate)) : null,
            'weight' => !empty($weight) && is_numeric($weight) ? floatval($weight) : null,
            'unit_of_measure' => sanitizeInput($unitOfMeasure),
            'manufacturer' => sanitizeInput($manufacturer),
            'barcode' => sanitizeInput($barcode),
            'description' => sanitizeInput($description),
            'specifications' => sanitizeInput($specifications),
            'cost_price' => floatval($costPrice),
            'selling_price' => floatval($sellingPrice),
            'quantity_in_stock' => intval($quantityInStock),
            'reorder_level' => intval($reorderLevel),
            'branch_id' => $branch['id'],
            'tax_id' => !empty($taxId) && is_numeric($taxId) && isset($taxMap[$taxId]) ? intval($taxId) : null,
            'status' => !empty($status) && strtolower($status) === 'inactive' ? 'Inactive' : 'Active',
            'battery_health' => !empty($batteryHealth) && is_numeric($batteryHealth) ? intval($batteryHealth) : null,
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Insert product
        $productId = $db->insert('products', $productData);
        
        if (!$productId) {
            $results['errors'][] = [
                'row' => $rowNumber,
                'errors' => ['Failed to create product: ' . $db->getLastError()],
                'data' => [
                    'category' => $categoryName,
                    'branch' => $branchName,
                    'product' => $productName ?: ($brand . ' ' . $model)
                ]
            ];
            continue;
        }
        
        $results['success'][] = [
            'row' => $rowNumber,
            'product_id' => $productId,
            'product_code' => $productData['product_code'],
            'product' => $productName ?: ($brand . ' ' . $model),
            'category' => $categoryName,
            'branch' => $branchName,
            'is_unique' => $isUniqueProduct,
            'quantity' => $productData['quantity_in_stock'],
            'reorder_level' => $productData['reorder_level']
        ];
    }
    
    fclose($handle);
    
    // Commit transaction
    $db->commitTransaction();
    
    // Prepare response
    $totalProcessed = count($results['success']) + count($results['errors']);
    $message = "Processed $totalProcessed rows. ";
    $message .= count($results['success']) . " products created successfully. ";
    if (count($results['errors']) > 0) {
        $message .= count($results['errors']) . " rows had errors.";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'results' => $results,
        'summary' => [
            'total_rows' => $totalProcessed,
            'successful' => count($results['success']),
            'errors' => count($results['errors']),
            'skipped' => count($results['skipped'])
        ]
    ]);
    
} catch (Exception $e) {
    $db->rollbackTransaction();
    fclose($handle);
    echo json_encode([
        'success' => false,
        'message' => 'Error processing bulk upload: ' . $e->getMessage()
    ]);
}

