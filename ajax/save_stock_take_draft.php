<?php
// Suppress any output before JSON
ob_start();

try {
    require_once dirname(dirname(__FILE__)) . '/config.php';
    require_once APP_PATH . '/includes/session.php';
    require_once APP_PATH . '/includes/db.php';
    require_once APP_PATH . '/includes/auth.php';
    
    initSession();
    
    // Clear any output
    ob_clean();
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
    
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
    
    if (!$input || !isset($input['branch_id']) || !isset($input['items'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid data. Branch ID and items are required.']);
        exit;
    }
    
    if (!is_array($input['items']) || empty($input['items'])) {
        echo json_encode(['success' => false, 'message' => 'Items array is empty or invalid.']);
        exit;
    }
} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Initialization error: ' . $e->getMessage()]);
    exit;
}

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$userId = $_SESSION['user_id'] ?? 0;
$branchId = intval($input['branch_id']);
$stockTakeId = isset($input['stock_take_id']) && $input['stock_take_id'] ? intval($input['stock_take_id']) : null;

try {
    $primaryDb->beginTransaction();
    
    // Create or update stock take
    if ($stockTakeId) {
        $result = $primaryDb->update('stock_takes', [
            'status' => 'draft' // Ensure status is draft (lowercase)
        ], ['id' => $stockTakeId]);
        
        if ($result === false) {
            $error = $primaryDb->getLastError();
            error_log("Stock take update failed. ID: $stockTakeId, Error: $error");
            throw new Exception('Failed to update stock take. Database error: ' . $error);
        }
    } else {
        // Generate unique stock take number
        $stockTakeNumber = 'ST-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check if number exists, generate new one if needed
        $exists = $primaryDb->getRow("SELECT id FROM stock_takes WHERE stock_take_number = :num", [':num' => $stockTakeNumber]);
        while ($exists) {
            $stockTakeNumber = 'ST-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $exists = $primaryDb->getRow("SELECT id FROM stock_takes WHERE stock_take_number = :num", [':num' => $stockTakeNumber]);
        }
        
        $insertData = [
            'stock_take_number' => $stockTakeNumber,
            'branch_id' => $branchId,
            'take_type' => 'full',
            'status' => 'draft', // lowercase to match ENUM
            'taken_by' => $userId,
            'taken_at' => date('Y-m-d H:i:s')
        ];
        
        $stockTakeId = $primaryDb->insert('stock_takes', $insertData);
        
        if (!$stockTakeId) {
            $error = $primaryDb->getLastError();
            error_log("Stock take insert failed. Data: " . json_encode($insertData));
            error_log("Database error: " . $error);
            throw new Exception('Failed to create stock take. Database error: ' . $error);
        }
    }
    
    // Delete existing items
    $primaryDb->executeQuery("DELETE FROM stock_take_items WHERE stock_take_id = :id", [':id' => $stockTakeId]);
    
    // Insert new items
    foreach ($input['items'] as $item) {
        if (!isset($item['product_id'])) {
            continue; // Skip invalid items
        }
        
        $currentStock = intval(round(floatval($item['current_stock'] ?? 0)));
        $countedStock = intval(round(floatval($item['counted_stock'] ?? 0)));
        $difference = $countedStock - $currentStock;
        
        $insertId = $primaryDb->insert('stock_take_items', [
            'stock_take_id' => $stockTakeId,
            'product_id' => intval($item['product_id']),
            'current_stock' => $currentStock,
            'counted_stock' => $countedStock,
            'difference' => $difference,
            'notes' => $item['notes'] ?? ''
        ]);
        
        if (!$insertId) {
            throw new Exception('Failed to insert stock take item for product ID: ' . $item['product_id']);
        }
    }
    
    $primaryDb->commitTransaction();
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Stock take draft saved successfully',
        'stock_take_id' => $stockTakeId
    ]);
    
} catch (Exception $e) {
    if (isset($primaryDb)) {
        $primaryDb->rollbackTransaction();
    }
    error_log("Stock take draft save error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Failed to save draft: ' . $e->getMessage()]);
    exit;
}

