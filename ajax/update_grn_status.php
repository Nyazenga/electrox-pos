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
$grnId = intval($input['grn_id'] ?? 0);
$status = trim($input['status'] ?? '');

if (!$grnId || !in_array($status, ['Draft', 'Approved', 'Rejected'])) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

try {
    $db = Database::getInstance();
    $userId = $_SESSION['user_id'] ?? null;
    
    // Get current GRN status
    $grn = $db->getRow("SELECT status, branch_id, grn_number FROM goods_received_notes WHERE id = :id", [':id' => $grnId]);
    if (!$grn) {
        throw new Exception('GRN not found');
    }
    
    $oldStatus = $grn['status'] ?? 'Draft';
    $branchId = $grn['branch_id'] ?? null;
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        $updateData = ['status' => $status];
        if ($status == 'Approved') {
            $updateData['approved_by'] = $userId;
            $updateData['approved_at'] = date('Y-m-d H:i:s');
        }
        
        $result = $db->update('goods_received_notes', $updateData, ['id' => $grnId]);
        
        if ($result === false) {
            throw new Exception('Failed to update GRN status: ' . $db->getLastError());
        }
        
        // If status changed to "Approved", add stock (only if not already approved)
        if ($status === 'Approved' && $oldStatus !== 'Approved') {
            // Get GRN items
            $grnItems = $db->getRows("SELECT * FROM grn_items WHERE grn_id = :id", [':id' => $grnId]);
            
            if ($grnItems !== false && !empty($grnItems)) {
                foreach ($grnItems as $item) {
                    $productId = intval($item['product_id'] ?? 0);
                    $quantity = intval($item['quantity'] ?? 0);
                    $costPrice = floatval($item['cost_price'] ?? 0);
                    $sellingPrice = floatval($item['selling_price'] ?? 0);
                    
                    if ($productId > 0 && $quantity > 0) {
                        // Update product cost and selling prices
                        if ($costPrice > 0 || $sellingPrice > 0) {
                            $priceUpdate = [];
                            if ($costPrice > 0) $priceUpdate['cost_price'] = $costPrice;
                            if ($sellingPrice > 0) $priceUpdate['selling_price'] = $sellingPrice;
                            if (!empty($priceUpdate)) {
                                $db->update('products', $priceUpdate, ['id' => $productId]);
                            }
                        }
                        
                        // Add stock
                        if (function_exists('updateStock')) {
                            $GLOBALS['current_transaction_db'] = $db;
                            updateStock($productId, $quantity, $branchId, 'Purchase', true);
                        } else {
                            // Manual stock update
                            $product = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id" . ($branchId ? " AND branch_id = :branch_id" : ""),
                                $branchId ? [':id' => $productId, ':branch_id' => $branchId] : [':id' => $productId]);
                            
                            $previousQuantity = $product !== false ? (int)($product['quantity_in_stock'] ?? 0) : 0;
                            $newQuantity = $previousQuantity + $quantity;
                            
                            $updateWhere = ['id' => $productId];
                            if ($branchId) {
                                $updateWhere['branch_id'] = $branchId;
                            }
                            
                            $db->update('products', ['quantity_in_stock' => $newQuantity], $updateWhere);
                            
                            $db->insert('stock_movements', [
                                'product_id' => $productId,
                                'branch_id' => $branchId,
                                'movement_type' => 'Purchase',
                                'quantity' => $quantity,
                                'previous_quantity' => $previousQuantity,
                                'new_quantity' => $newQuantity,
                                'reference_id' => $grnId,
                                'reference_type' => 'GRN',
                                'user_id' => $userId,
                                'notes' => 'GRN Approved: ' . $grn['grn_number'],
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            }
        }
        
        // If status changed from "Approved" to something else, reverse stock
        if ($oldStatus === 'Approved' && $status !== 'Approved') {
            // Get GRN items
            $grnItems = $db->getRows("SELECT * FROM grn_items WHERE grn_id = :id", [':id' => $grnId]);
            
            if ($grnItems !== false && !empty($grnItems)) {
                foreach ($grnItems as $item) {
                    $productId = intval($item['product_id'] ?? 0);
                    $quantity = intval($item['quantity'] ?? 0);
                    
                    if ($productId > 0 && $quantity > 0) {
                        // Deduct stock (reverse the addition)
                        if (function_exists('updateStock')) {
                            $GLOBALS['current_transaction_db'] = $db;
                            updateStock($productId, -$quantity, $branchId, 'Adjustment', true);
                        } else {
                            // Manual stock update
                            $product = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id" . ($branchId ? " AND branch_id = :branch_id" : ""),
                                $branchId ? [':id' => $productId, ':branch_id' => $branchId] : [':id' => $productId]);
                            
                            if ($product !== false) {
                                $previousQuantity = (int)($product['quantity_in_stock'] ?? 0);
                                $newQuantity = max(0, $previousQuantity - $quantity);
                                
                                $updateWhere = ['id' => $productId];
                                if ($branchId) {
                                    $updateWhere['branch_id'] = $branchId;
                                }
                                
                                $db->update('products', ['quantity_in_stock' => $newQuantity], $updateWhere);
                                
                                $db->insert('stock_movements', [
                                    'product_id' => $productId,
                                    'branch_id' => $branchId,
                                    'movement_type' => 'Adjustment',
                                    'quantity' => -$quantity,
                                    'previous_quantity' => $previousQuantity,
                                    'new_quantity' => $newQuantity,
                                    'reference_id' => $grnId,
                                    'reference_type' => 'GRN',
                                    'user_id' => $userId,
                                    'notes' => 'GRN Status Reversal: ' . $grn['grn_number'],
                                    'created_at' => date('Y-m-d H:i:s')
                                ]);
                            }
                        }
                    }
                }
            }
        }
        
        $db->commitTransaction();
        
        logActivity($userId, 'grn_status_updated', ['grn_id' => $grnId, 'status' => $status]);
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'GRN status updated successfully']);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        throw $e;
    }
    
} catch (Exception $e) {
    ob_end_clean();
    logError("Update GRN status error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}


