<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

header('Content-Type: application/json; charset=utf-8');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    $auth->requirePermission('products.stock_take');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$productId = intval($_POST['product_id'] ?? 0);
$branchId = intval($_POST['branch_id'] ?? $_SESSION['branch_id'] ?? 0);
$countedStock = floatval($_POST['counted_stock'] ?? 0);
$notes = $_POST['notes'] ?? '';

if (!$productId || !$branchId) {
    echo json_encode(['success' => false, 'message' => 'Product ID and Branch ID required']);
    exit;
}

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$userId = $_SESSION['user_id'] ?? 0;

// Get product with category info
$product = $db->getRow(
    "SELECT p.*, pc.name as category_name FROM products p 
     LEFT JOIN product_categories pc ON p.category_id = pc.id 
     WHERE p.id = :id AND p.branch_id = :branch_id",
    [':id' => $productId, ':branch_id' => $branchId]
);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

// For products requiring specific list, we need to handle product_specific_list entries
$requiresSpecificList = productRequiresSpecificList($product, $db);
$specificListEntries = json_decode($_POST['specific_list_entries'] ?? '[]', true) ?: [];

if ($requiresSpecificList) {
    // Validate specific list entries if provided
    if (!empty($specificListEntries) && count($specificListEntries) !== $countedStock) {
        echo json_encode([
            'success' => false, 
            'message' => "For this product type, please provide details for all {$countedStock} items."
        ]);
        exit;
    }
}

$allowNegativeStock = getSetting('allow_negative_stock', '0') == '1';

if (!$allowNegativeStock && $countedStock < 0) {
    echo json_encode(['success' => false, 'message' => 'Negative stock is not allowed']);
    exit;
}

try {
    $db->beginTransaction();
    $primaryDb->beginTransaction();
    
    $currentStock = floatval($product['quantity_in_stock'] ?? 0);
    $difference = $countedStock - $currentStock;
    
    // Convert to integers for database (table uses INT)
    $currentStockInt = intval(round($currentStock));
    $countedStockInt = intval(round($countedStock));
    
    // Generate unique stock take number
    $stockTakeNumber = 'ST-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $exists = $primaryDb->getRow("SELECT id FROM stock_takes WHERE stock_take_number = :num", [':num' => $stockTakeNumber]);
    while ($exists) {
        $stockTakeNumber = 'ST-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $exists = $primaryDb->getRow("SELECT id FROM stock_takes WHERE stock_take_number = :num", [':num' => $stockTakeNumber]);
    }
    
    // Create stock take record
    $stockTakeId = $primaryDb->insert('stock_takes', [
        'stock_take_number' => $stockTakeNumber,
        'branch_id' => $branchId,
        'take_type' => 'single',
        'product_id' => $productId,
        'status' => 'completed',
        'taken_by' => $userId,
        'taken_at' => date('Y-m-d H:i:s'),
        'completed_at' => date('Y-m-d H:i:s'),
        'notes' => $notes
    ]);
    
    if (!$stockTakeId) {
        throw new Exception('Failed to create stock take: ' . $primaryDb->getLastError());
    }
    
    $currentStockInt = intval(round($currentStock));
    $countedStockInt = intval(round($countedStock));
    $differenceInt = $countedStockInt - $currentStockInt;
    
    // Create stock take item
    $itemId = $primaryDb->insert('stock_take_items', [
        'stock_take_id' => $stockTakeId,
        'product_id' => $productId,
        'current_stock' => $currentStockInt,
        'counted_stock' => $countedStockInt,
        'difference' => $differenceInt,
        'notes' => $notes
    ]);
    
    if (!$itemId) {
        throw new Exception('Failed to create stock take item: ' . $primaryDb->getLastError());
    }
    
    // Handle product_specific_list entries if required
    if ($requiresSpecificList && !empty($specificListEntries)) {
        foreach ($specificListEntries as $entry) {
            $specificData = [
                'product_id' => $productId,
                'branch_id' => $branchId,
                'color' => !empty($entry['color']) ? sanitizeInput($entry['color']) : null,
                'storage' => !empty($entry['storage']) ? sanitizeInput($entry['storage']) : null,
                'sim_configuration' => !empty($entry['sim_configuration']) ? sanitizeInput($entry['sim_configuration']) : null,
                'serial_number' => !empty($entry['serial_number']) ? sanitizeInput($entry['serial_number']) : null,
                'imei' => !empty($entry['imei']) ? sanitizeInput($entry['imei']) : null,
                'battery_health' => !empty($entry['battery_health']) ? intval($entry['battery_health']) : null,
                'manufacturer' => !empty($entry['manufacturer']) ? sanitizeInput($entry['manufacturer']) : null,
                'warranty_months' => !empty($entry['warranty_months']) ? intval($entry['warranty_months']) : 0,
                'warranty_terms' => !empty($entry['warranty_terms']) ? sanitizeInput($entry['warranty_terms']) : null,
                'condition' => !empty($entry['condition']) ? sanitizeInput($entry['condition']) : 'New',
                'trade_in_eligible' => !empty($entry['trade_in_eligible']) ? 1 : 0,
                'status' => 'available',
                'stock_take_item_id' => $itemId,
                'created_by' => $userId
            ];
            
            // Check for duplicate serial/IMEI
            if (!empty($specificData['serial_number'])) {
                $existing = $db->getRow(
                    "SELECT id FROM product_specific_list WHERE serial_number = :serial AND branch_id = :branch_id",
                    [':serial' => $specificData['serial_number'], ':branch_id' => $branchId]
                );
                if ($existing) {
                    throw new Exception("Serial number {$specificData['serial_number']} already exists");
                }
            }
            
            if (!empty($specificData['imei'])) {
                $existing = $db->getRow(
                    "SELECT id FROM product_specific_list WHERE imei = :imei AND branch_id = :branch_id",
                    [':imei' => $specificData['imei'], ':branch_id' => $branchId]
                );
                if ($existing) {
                    throw new Exception("IMEI {$specificData['imei']} already exists");
                }
            }
            
            $specificId = $db->insert('product_specific_list', $specificData);
            if (!$specificId) {
                throw new Exception('Failed to create product specific list entry');
            }
        }
        
        // Update product quantity to match count of available entries
        $count = getProductSpecificListCount($productId, $branchId, 'available', $db);
        $db->update('products', ['quantity_in_stock' => $count], ['id' => $productId]);
    } else {
        // Normal product: Overwrite stock level
    $db->update('products', [
        'quantity_in_stock' => $countedStock
    ], ['id' => $productId]);
    }
    
    $db->commitTransaction();
    $primaryDb->commitTransaction();
    
    echo json_encode([
        'success' => true,
        'message' => 'Stock take saved and stock level updated'
    ]);
    
} catch (Exception $e) {
    $db->rollbackTransaction();
    $primaryDb->rollbackTransaction();
    error_log("Single stock take error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $e->getMessage()]);
}

