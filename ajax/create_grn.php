<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();
$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.create');

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit;
}

$grnNumber = trim($input['grn_number'] ?? '');
$supplierId = intval($input['supplier_id'] ?? 0);
$branchId = intval($input['branch_id'] ?? 0);
$receivedDate = $input['received_date'] ?? date('Y-m-d');
$notes = trim($input['notes'] ?? '');
$items = $input['items'] ?? [];

if (empty($grnNumber)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'GRN number is required']);
    exit;
}

if (empty($items) || !is_array($items)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'At least one item is required']);
    exit;
}

try {
    $db = Database::getInstance();
    $userId = $_SESSION['user_id'] ?? null;
    
    // Check if GRN number already exists
    $existing = $db->getRow("SELECT id FROM goods_received_notes WHERE grn_number = :number", [':number' => $grnNumber]);
    if ($existing) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'GRN number already exists']);
        exit;
    }
    
    // Calculate total value
    $totalValue = 0;
    foreach ($items as $item) {
        $totalValue += floatval($item['cost_price'] ?? 0) * intval($item['quantity'] ?? 0);
    }
    
    $db->beginTransaction();
    
    // Create GRN
    $grnData = [
        'grn_number' => $grnNumber,
        'supplier_id' => $supplierId > 0 ? $supplierId : null,
        'branch_id' => $branchId,
        'received_date' => $receivedDate,
        'received_by' => $userId,
        'total_value' => $totalValue,
        'status' => 'Draft',
        'notes' => $notes
    ];
    
    $grnId = $db->insert('goods_received_notes', $grnData);
    
    if (!$grnId) {
        $db->rollbackTransaction();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to create GRN: ' . $db->getLastError()]);
        exit;
    }
    
    // Create GRN items and update stock
    foreach ($items as $item) {
        $productId = intval($item['product_id'] ?? 0);
        $quantity = intval($item['quantity'] ?? 0);
        $costPrice = floatval($item['cost_price'] ?? 0);
        $sellingPrice = floatval($item['selling_price'] ?? 0);
        $wholesalePrice = !empty($item['wholesale_price']) ? floatval($item['wholesale_price']) : null;
        $serialNumbers = trim($item['serial_numbers'] ?? '');
        
        if ($productId <= 0 || $quantity <= 0) {
            continue;
        }
        
        // Check if product requires specific list
        $product = $db->getRow(
            "SELECT p.*, pc.name as category_name FROM products p 
             LEFT JOIN product_categories pc ON p.category_id = pc.id 
             WHERE p.id = :id",
            [':id' => $productId]
        );
        
        if (!$product) {
            continue;
        }
        
        $requiresSpecificList = productRequiresSpecificList($product, $db);
        $specificListEntries = $item['specific_list_entries'] ?? [];
        
        // Validate specific list entries if required
        if ($requiresSpecificList) {
            if (empty($specificListEntries) || count($specificListEntries) !== $quantity) {
                $db->rollbackTransaction();
                ob_end_clean();
                $productName = $product['product_name'] ?? trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? '')) ?? 'Product #' . $productId;
                echo json_encode([
                    'success' => false, 
                    'message' => "Product \"{$productName}\" requires individual instance details. Please provide details for all {$quantity} items."
                ]);
                exit;
            }
            
            // Validate each entry has serial or IMEI
            foreach ($specificListEntries as $entry) {
                if (empty($entry['serial_number']) && empty($entry['imei'])) {
                    $db->rollbackTransaction();
                    ob_end_clean();
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Each item must have either Serial Number or IMEI'
                    ]);
                    exit;
                }
            }
        }
        
        // Create GRN item
        $itemData = [
            'grn_id' => $grnId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'serial_numbers' => !empty($serialNumbers) ? $serialNumbers : null
        ];
        
        $itemId = $db->insert('grn_items', $itemData);
        
        if (!$itemId) {
            $db->rollbackTransaction();
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to create GRN item']);
            exit;
        }
        
        // Create product_specific_list entries if required
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
                    'grn_item_id' => $itemId,
                    'created_by' => $userId
                ];
                
                // Check for duplicate serial/IMEI
                if (!empty($specificData['serial_number'])) {
                    $existing = $db->getRow(
                        "SELECT id FROM product_specific_list WHERE serial_number = :serial AND branch_id = :branch_id",
                        [':serial' => $specificData['serial_number'], ':branch_id' => $branchId]
                    );
                    if ($existing) {
                        $db->rollbackTransaction();
                        ob_end_clean();
                        echo json_encode([
                            'success' => false, 
                            'message' => "Serial number {$specificData['serial_number']} already exists"
                        ]);
                        exit;
                    }
                }
                
                if (!empty($specificData['imei'])) {
                    $existing = $db->getRow(
                        "SELECT id FROM product_specific_list WHERE imei = :imei AND branch_id = :branch_id",
                        [':imei' => $specificData['imei'], ':branch_id' => $branchId]
                    );
                    if ($existing) {
                        $db->rollbackTransaction();
                        ob_end_clean();
                        echo json_encode([
                            'success' => false, 
                            'message' => "IMEI {$specificData['imei']} already exists"
                        ]);
                        exit;
                    }
                }
                
                $specificId = $db->insert('product_specific_list', $specificData);
                if (!$specificId) {
                    $db->rollbackTransaction();
                    ob_end_clean();
                    echo json_encode(['success' => false, 'message' => 'Failed to create product specific list entry']);
                    exit;
                }
            }
            
            // Update product quantity to match count of available entries
            $count = getProductSpecificListCount($productId, $branchId, 'available', $db);
            $db->update('products', ['quantity_in_stock' => $count], ['id' => $productId]);
        }
        
        // Update product cost and selling prices
        if ($costPrice > 0 || $sellingPrice > 0 || $wholesalePrice !== null) {
            // Get current product prices for tracking
            $currentProduct = $db->getRow("SELECT cost_price, selling_price, wholesale_price FROM products WHERE id = :id", [':id' => $productId]);
            $oldCostPrice = $currentProduct ? floatval($currentProduct['cost_price'] ?? 0) : 0;
            $oldSellingPrice = $currentProduct ? floatval($currentProduct['selling_price'] ?? 0) : 0;
            
            $priceUpdate = [];
            if ($costPrice > 0) $priceUpdate['cost_price'] = $costPrice;
            if ($sellingPrice > 0) $priceUpdate['selling_price'] = $sellingPrice;
            if ($wholesalePrice !== null) $priceUpdate['wholesale_price'] = $wholesalePrice;
            if (!empty($priceUpdate)) {
                $db->update('products', $priceUpdate, ['id' => $productId]);
                
                // Track price changes
                $primaryDb = Database::getPrimaryInstance();
                if ($costPrice > 0 && $oldCostPrice != $costPrice) {
                    $primaryDb->insert('price_change_history', [
                        'product_id' => $productId,
                        'branch_id' => $branchId,
                        'old_price' => $oldCostPrice,
                        'new_price' => $costPrice,
                        'price_type' => 'cost_price',
                        'changed_by' => $userId,
                        'change_reason' => 'GRN Created',
                        'changed_at' => date('Y-m-d H:i:s')
                    ]);
                }
                if ($sellingPrice > 0 && $oldSellingPrice != $sellingPrice) {
                    $primaryDb->insert('price_change_history', [
                        'product_id' => $productId,
                        'branch_id' => $branchId,
                        'old_price' => $oldSellingPrice,
                        'new_price' => $sellingPrice,
                        'price_type' => 'selling_price',
                        'changed_by' => $userId,
                        'change_reason' => 'GRN Created',
                        'changed_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
        }
        
        // NOTE: For products requiring specific list, stock is already updated above
        // For normal products, stock will be added when GRN status changes to "Approved"
        // Do NOT add stock here for normal products - only when approved
    }
    
    $db->commitTransaction();
    
    // Log activity
    try {
        logActivity($userId, 'grn_created', ['grn_id' => $grnId, 'grn_number' => $grnNumber]);
    } catch (Exception $e) {
        // Ignore logging errors
    }
    
    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'GRN created successfully', 'grn_id' => $grnId]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollbackTransaction();
    }
    ob_end_clean();
    logError("Create GRN error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}


