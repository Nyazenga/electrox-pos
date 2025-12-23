<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();
$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('transfers.change_status');

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
$transferId = intval($input['transfer_id'] ?? 0);
$status = trim($input['status'] ?? '');

if (!$transferId || !in_array($status, ['Pending', 'Approved', 'InTransit', 'Received', 'Rejected', 'Completed'])) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

try {
    $db = Database::getInstance();
    $userId = $_SESSION['user_id'] ?? null;
    
    // Get current transfer status
    $transfer = $db->getRow("SELECT status, from_branch_id, to_branch_id, transfer_number FROM stock_transfers WHERE id = :id", [':id' => $transferId]);
    if (!$transfer) {
        throw new Exception('Transfer not found');
    }
    
    $oldStatus = $transfer['status'] ?? 'Pending';
    $fromBranchId = $transfer['from_branch_id'] ?? null;
    $toBranchId = $transfer['to_branch_id'] ?? null;
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        $updateData = ['status' => $status];
        if ($status == 'Approved') {
            $updateData['approved_by'] = $userId;
        } elseif ($status == 'Received' || $status == 'Completed') {
            $updateData['received_by'] = $userId;
            if ($status == 'Completed') {
                $updateData['status'] = 'Completed';
            }
        }
        
        $result = $db->update('stock_transfers', $updateData, ['id' => $transferId]);
        
        if ($result === false) {
            throw new Exception('Failed to update transfer status: ' . $db->getLastError());
        }
        
        // If status changed to "Approved" or "Completed", move stock
        if (($status === 'Approved' || $status === 'Completed') && $oldStatus !== 'Approved' && $oldStatus !== 'Completed') {
            // Get transfer items
            $transferItems = $db->getRows("SELECT * FROM transfer_items WHERE transfer_id = :id", [':id' => $transferId]);
            
            if ($transferItems !== false && !empty($transferItems)) {
                foreach ($transferItems as $item) {
                    $productId = intval($item['product_id'] ?? 0);
                    $quantity = intval($item['quantity'] ?? 0);
                    
                    if ($productId > 0 && $quantity > 0 && $fromBranchId && $toBranchId) {
                        // Check available stock in source branch
                        $fromProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", 
                            [':id' => $productId, ':branch_id' => $fromBranchId]);
                        
                        if (!$fromProduct || ($fromProduct['quantity_in_stock'] ?? 0) < $quantity) {
                            throw new Exception("Insufficient stock for product ID: {$productId} in source branch");
                        }
                        
                        // Deduct from source branch
                        $fromPreviousQuantity = (int)($fromProduct['quantity_in_stock'] ?? 0);
                        $fromNewQuantity = $fromPreviousQuantity - $quantity;
                        
                        $db->update('products', [
                            'quantity_in_stock' => $fromNewQuantity
                        ], ['id' => $productId, 'branch_id' => $fromBranchId]);
                        
                        $db->insert('stock_movements', [
                            'product_id' => $productId,
                            'branch_id' => $fromBranchId,
                            'movement_type' => 'Transfer',
                            'quantity' => -$quantity,
                            'previous_quantity' => $fromPreviousQuantity,
                            'new_quantity' => $fromNewQuantity,
                            'reference_id' => $transferId,
                            'reference_type' => 'Transfer',
                            'user_id' => $userId,
                            'notes' => 'Transfer Out: ' . $transfer['transfer_number'],
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        // Add to destination branch
                        $toProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", 
                            [':id' => $productId, ':branch_id' => $toBranchId]);
                        
                        if ($toProduct) {
                            // Product exists, update quantity
                            $toPreviousQuantity = (int)($toProduct['quantity_in_stock'] ?? 0);
                            $toNewQuantity = $toPreviousQuantity + $quantity;
                            
                            $db->update('products', [
                                'quantity_in_stock' => $toNewQuantity
                            ], ['id' => $productId, 'branch_id' => $toBranchId]);
                            
                            $db->insert('stock_movements', [
                                'product_id' => $productId,
                                'branch_id' => $toBranchId,
                                'movement_type' => 'Transfer',
                                'quantity' => $quantity,
                                'previous_quantity' => $toPreviousQuantity,
                                'new_quantity' => $toNewQuantity,
                                'reference_id' => $transferId,
                                'reference_type' => 'Transfer',
                                'user_id' => $userId,
                                'notes' => 'Transfer In: ' . $transfer['transfer_number'],
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        } else {
                            // Product doesn't exist in destination branch - create entry or skip
                            // For now, we'll skip adding to destination if product doesn't exist
                            // This could be enhanced to create the product entry
                            error_log("Product ID {$productId} does not exist in destination branch {$toBranchId}");
                        }
                    }
                }
            }
        }
        
        // If status changed from "Approved"/"Completed" to something else, reverse stock movement
        if (($oldStatus === 'Approved' || $oldStatus === 'Completed') && $status !== 'Approved' && $status !== 'Completed') {
            // Get transfer items
            $transferItems = $db->getRows("SELECT * FROM transfer_items WHERE transfer_id = :id", [':id' => $transferId]);
            
            if ($transferItems !== false && !empty($transferItems)) {
                foreach ($transferItems as $item) {
                    $productId = intval($item['product_id'] ?? 0);
                    $quantity = intval($item['quantity'] ?? 0);
                    
                    if ($productId > 0 && $quantity > 0 && $fromBranchId && $toBranchId) {
                        // Restore to source branch
                        $fromProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", 
                            [':id' => $productId, ':branch_id' => $fromBranchId]);
                        
                        if ($fromProduct !== false) {
                            $fromPreviousQuantity = (int)($fromProduct['quantity_in_stock'] ?? 0);
                            $fromNewQuantity = $fromPreviousQuantity + $quantity;
                            
                            $db->update('products', [
                                'quantity_in_stock' => $fromNewQuantity
                            ], ['id' => $productId, 'branch_id' => $fromBranchId]);
                            
                            $db->insert('stock_movements', [
                                'product_id' => $productId,
                                'branch_id' => $fromBranchId,
                                'movement_type' => 'Transfer',
                                'quantity' => $quantity,
                                'previous_quantity' => $fromPreviousQuantity,
                                'new_quantity' => $fromNewQuantity,
                                'reference_id' => $transferId,
                                'reference_type' => 'Transfer',
                                'user_id' => $userId,
                                'notes' => 'Transfer Reversal Out: ' . $transfer['transfer_number'],
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                        
                        // Deduct from destination branch
                        $toProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", 
                            [':id' => $productId, ':branch_id' => $toBranchId]);
                        
                        if ($toProduct !== false) {
                            $toPreviousQuantity = (int)($toProduct['quantity_in_stock'] ?? 0);
                            $toNewQuantity = max(0, $toPreviousQuantity - $quantity);
                            
                            $db->update('products', [
                                'quantity_in_stock' => $toNewQuantity
                            ], ['id' => $productId, 'branch_id' => $toBranchId]);
                            
                            $db->insert('stock_movements', [
                                'product_id' => $productId,
                                'branch_id' => $toBranchId,
                                'movement_type' => 'Transfer',
                                'quantity' => -$quantity,
                                'previous_quantity' => $toPreviousQuantity,
                                'new_quantity' => $toNewQuantity,
                                'reference_id' => $transferId,
                                'reference_type' => 'Transfer',
                                'user_id' => $userId,
                                'notes' => 'Transfer Reversal In: ' . $transfer['transfer_number'],
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            }
        }
        
        $db->commitTransaction();
        
        logActivity($userId, 'transfer_status_updated', ['transfer_id' => $transferId, 'status' => $status]);
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Transfer status updated successfully']);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        throw $e;
    }
    
} catch (Exception $e) {
    ob_end_clean();
    logError("Update transfer status error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}


