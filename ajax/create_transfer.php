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

$transferNumber = trim($input['transfer_number'] ?? '');
$fromBranchId = intval($input['from_branch_id'] ?? 0);
$toBranchId = intval($input['to_branch_id'] ?? 0);
$transferDate = $input['transfer_date'] ?? date('Y-m-d');
$notes = trim($input['notes'] ?? '');
$items = $input['items'] ?? [];

if (empty($transferNumber)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Transfer number is required']);
    exit;
}

if ($fromBranchId === $toBranchId) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'From and To branches cannot be the same']);
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
    
    // Check if transfer number already exists
    $existing = $db->getRow("SELECT id FROM stock_transfers WHERE transfer_number = :number", [':number' => $transferNumber]);
    if ($existing) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Transfer number already exists']);
        exit;
    }
    
    $db->beginTransaction();
    
    // Create transfer
    $transferData = [
        'transfer_number' => $transferNumber,
        'from_branch_id' => $fromBranchId,
        'to_branch_id' => $toBranchId,
        'transfer_date' => $transferDate,
        'initiated_by' => $userId,
        'status' => 'Pending',
        'total_items' => count($items),
        'notes' => $notes
    ];
    
    $transferId = $db->insert('stock_transfers', $transferData);
    
    if (!$transferId) {
        $db->rollbackTransaction();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to create transfer: ' . $db->getLastError()]);
        exit;
    }
    
    // Create transfer items and update stock
    foreach ($items as $item) {
        $productId = intval($item['product_id'] ?? 0);
        $quantity = intval($item['quantity'] ?? 0);
        $serialNumbers = trim($item['serial_numbers'] ?? '');
        
        if ($productId <= 0 || $quantity <= 0) {
            continue;
        }
        
        // Check available stock in from branch
        $product = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", 
            [':id' => $productId, ':branch_id' => $fromBranchId]);
        
        if (!$product || ($product['quantity_in_stock'] ?? 0) < $quantity) {
            $db->rollbackTransaction();
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Insufficient stock for product ID: ' . $productId]);
            exit;
        }
        
        // Create transfer item
        $itemData = [
            'transfer_id' => $transferId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'serial_numbers' => !empty($serialNumbers) ? $serialNumbers : null
        ];
        
        $itemId = $db->insert('transfer_items', $itemData);
        
        if (!$itemId) {
            $db->rollbackTransaction();
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to create transfer item']);
            exit;
        }
        
        // NOTE: Stock will be moved when Transfer status changes to "Approved" or "Completed"
        // Do NOT move stock here - only when approved/completed
        // Just verify stock availability (already checked above)
    }
    
    $db->commitTransaction();
    
    // Log activity
    try {
        logActivity($userId, 'transfer_created', ['transfer_id' => $transferId, 'transfer_number' => $transferNumber]);
    } catch (Exception $e) {
        // Ignore logging errors
    }
    
    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Transfer created successfully', 'transfer_id' => $transferId]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollbackTransaction();
    }
    ob_end_clean();
    logError("Create transfer error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}


