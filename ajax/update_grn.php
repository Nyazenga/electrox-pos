<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();
$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.edit');

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

$grnId = intval($input['grn_id'] ?? 0);
$grnNumber = trim($input['grn_number'] ?? '');
$supplierId = intval($input['supplier_id'] ?? 0);
$branchId = intval($input['branch_id'] ?? 0);
$receivedDate = $input['received_date'] ?? date('Y-m-d');
$notes = trim($input['notes'] ?? '');
$items = $input['items'] ?? [];

if ($grnId <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid GRN ID']);
    exit;
}

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
    
    // Check if GRN exists and is editable (Draft status)
    $existingGRN = $db->getRow("SELECT * FROM goods_received_notes WHERE id = :id", [':id' => $grnId]);
    if (!$existingGRN) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'GRN not found']);
        exit;
    }
    
    if ($existingGRN['status'] !== 'Draft') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Only Draft GRNs can be edited']);
        exit;
    }
    
    // Check if GRN number already exists (excluding current GRN)
    $existing = $db->getRow("SELECT id FROM goods_received_notes WHERE grn_number = :number AND id != :id", [
        ':number' => $grnNumber,
        ':id' => $grnId
    ]);
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
    
    // Update GRN
    $grnData = [
        'grn_number' => $grnNumber,
        'supplier_id' => $supplierId > 0 ? $supplierId : null,
        'branch_id' => $branchId,
        'received_date' => $receivedDate,
        'total_value' => $totalValue,
        'notes' => $notes
    ];
    
    $updated = $db->update('goods_received_notes', $grnData, ['id' => $grnId]);
    
    if (!$updated) {
        $db->rollbackTransaction();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to update GRN: ' . $db->getLastError()]);
        exit;
    }
    
    // Delete existing GRN items
    $db->executeQuery("DELETE FROM grn_items WHERE grn_id = :id", [':id' => $grnId]);
    
    // Create new GRN items
    foreach ($items as $item) {
        $productId = intval($item['product_id'] ?? 0);
        $quantity = intval($item['quantity'] ?? 0);
        $costPrice = floatval($item['cost_price'] ?? 0);
        $sellingPrice = floatval($item['selling_price'] ?? 0);
        $serialNumbers = trim($item['serial_numbers'] ?? '');
        
        if ($productId <= 0 || $quantity <= 0) {
            continue;
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
        
        // Update product cost and selling prices
        if ($costPrice > 0 || $sellingPrice > 0) {
            $priceUpdate = [];
            if ($costPrice > 0) $priceUpdate['cost_price'] = $costPrice;
            if ($sellingPrice > 0) $priceUpdate['selling_price'] = $sellingPrice;
            if (!empty($priceUpdate)) {
                $db->update('products', $priceUpdate, ['id' => $productId]);
            }
        }
    }
    
    $db->commitTransaction();
    
    // Log activity
    try {
        logActivity($userId, 'grn_updated', ['grn_id' => $grnId, 'grn_number' => $grnNumber]);
    } catch (Exception $e) {
        // Ignore logging errors
    }
    
    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'GRN updated successfully', 'grn_id' => $grnId]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollbackTransaction();
    }
    ob_end_clean();
    error_log("Update GRN Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to update GRN: ' . $e->getMessage()]);
}

