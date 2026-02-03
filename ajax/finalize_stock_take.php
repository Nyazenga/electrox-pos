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

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['stock_take_id'])) {
    echo json_encode(['success' => false, 'message' => 'Stock take ID required']);
    exit;
}

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$stockTakeId = intval($input['stock_take_id']);

// Get stock take
$stockTake = $primaryDb->getRow("SELECT * FROM stock_takes WHERE id = :id", [':id' => $stockTakeId]);

if (!$stockTake) {
    error_log("Stock take not found: ID = $stockTakeId");
    echo json_encode(['success' => false, 'message' => 'Stock take not found with ID: ' . $stockTakeId]);
    exit;
}

if (strtolower($stockTake['status']) !== 'draft') {
    error_log("Stock take status is not draft: Status = " . $stockTake['status'] . ", ID = $stockTakeId");
    echo json_encode(['success' => false, 'message' => 'Stock take is not in Draft status. Current status: ' . $stockTake['status']]);
    exit;
}

// Use items from request if provided (ensures we use latest data), otherwise get from database
$items = [];
if (isset($input['items']) && is_array($input['items']) && !empty($input['items'])) {
    // Use items sent from frontend (current table data)
    foreach ($input['items'] as $item) {
        $items[] = [
            'product_id' => intval($item['product_id']),
            'current_stock' => floatval($item['current_stock'] ?? 0),
            'counted_stock' => floatval($item['counted_stock'] ?? 0),
            'difference' => floatval($item['difference'] ?? 0),
            'notes' => $item['notes'] ?? ''
        ];
    }
} else {
    // Fallback: Get stock take items from database
    $items = $primaryDb->getRows("SELECT * FROM stock_take_items WHERE stock_take_id = :id", [':id' => $stockTakeId]);
}

if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'No items found in stock take']);
    exit;
}

$allowNegativeStock = getSetting('allow_negative_stock', '0') == '1';

try {
    $db->beginTransaction();
    $primaryDb->beginTransaction();
    
    // Update stock levels (overwrite, unlike GRN which adds)
    foreach ($items as $item) {
        $productId = intval($item['product_id']);
        // Handle both array format (from frontend) and database row format
        $countedStock = floatval($item['counted_stock'] ?? 0);
        
        // Get current product with category info
        $product = $db->getRow(
            "SELECT p.*, pc.name as category_name FROM products p 
             LEFT JOIN product_categories pc ON p.category_id = pc.id 
             WHERE p.id = :id",
            [':id' => $productId]
        );
        
        if (!$product) {
            continue;
        }
        
        // If negative stock is not allowed and counted stock would be negative, skip
        if (!$allowNegativeStock && $countedStock < 0) {
            continue;
        }
        
        // Overwrite stock level for all products (stock take overwrites, GRN adds)
        // For products requiring specific list, quantity_in_stock should match the counted stock
        // The product_specific_list entries should be managed separately to match this quantity
        $db->update('products', [
            'quantity_in_stock' => intval($countedStock)
        ], ['id' => $productId]);
    }
    
    // Update stock take status
    $primaryDb->update('stock_takes', [
        'status' => 'completed',
        'completed_at' => date('Y-m-d H:i:s')
    ], ['id' => $stockTakeId]);
    
    // Calculate summary statistics for report
    $totalItems = count($items);
    $itemsWithGains = 0;
    $itemsWithLosses = 0;
    $itemsNoChange = 0;
    $totalGainQuantity = 0;
    $totalLossQuantity = 0;
    $netDifference = 0;
    
    $detailedBreakdown = [];
    
    foreach ($items as $item) {
        $difference = floatval($item['difference'] ?? 0);
        $netDifference += $difference;
        
        if ($difference > 0) {
            $itemsWithGains++;
            $totalGainQuantity += $difference;
        } elseif ($difference < 0) {
            $itemsWithLosses++;
            $totalLossQuantity += abs($difference);
        } else {
            $itemsNoChange++;
        }
        
        // Get product details for detailed breakdown
        $product = $db->getRow("SELECT id, product_code, 
                                COALESCE(product_name, CONCAT(COALESCE(brand, ''), ' ', COALESCE(model, ''))) as display_name
                                FROM products WHERE id = :id", [':id' => intval($item['product_id'])]);
        
        if ($product) {
            $detailedBreakdown[] = [
                'product_id' => intval($item['product_id']),
                'product_code' => $product['product_code'] ?? '',
                'product_name' => $product['display_name'] ?? '',
                'current_stock' => floatval($item['current_stock'] ?? 0),
                'counted_stock' => floatval($item['counted_stock'] ?? 0),
                'difference' => $difference,
                'notes' => $item['notes'] ?? ''
            ];
        }
    }
    
    // Create stock take report record
    $reportData = [
        'stock_take_id' => $stockTakeId,
        'branch_id' => intval($stockTake['branch_id']),
        'taken_by' => intval($stockTake['taken_by']),
        'report_date' => date('Y-m-d H:i:s'),
        'total_items' => $totalItems,
        'items_with_gains' => $itemsWithGains,
        'items_with_losses' => $itemsWithLosses,
        'items_no_change' => $itemsNoChange,
        'total_gain_quantity' => round($totalGainQuantity, 2),
        'total_loss_quantity' => round($totalLossQuantity, 2),
        'net_difference' => round($netDifference, 2),
        'summary_data' => json_encode([
            'detailed_breakdown' => $detailedBreakdown,
            'summary' => [
                'total_items' => $totalItems,
                'items_with_gains' => $itemsWithGains,
                'items_with_losses' => $itemsWithLosses,
                'items_no_change' => $itemsNoChange,
                'total_gain_quantity' => round($totalGainQuantity, 2),
                'total_loss_quantity' => round($totalLossQuantity, 2),
                'net_difference' => round($netDifference, 2)
            ]
        ])
    ];
    
    $reportId = $primaryDb->insert('stock_take_reports', $reportData);
    
    if ($reportId === false) {
        throw new Exception('Failed to create stock take report');
    }
    
    $db->commitTransaction();
    $primaryDb->commitTransaction();
    
    echo json_encode([
        'success' => true,
        'message' => 'Stock take finalized successfully. Stock levels have been updated.',
        'report_id' => $reportId
    ]);
    
} catch (Exception $e) {
    $db->rollbackTransaction();
    $primaryDb->rollbackTransaction();
    error_log("Stock take finalization error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to finalize: ' . $e->getMessage()]);
}

