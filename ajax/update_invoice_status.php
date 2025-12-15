<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

// Suppress errors for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json');

try {
    $auth = Auth::getInstance();
    if (!$auth->isLoggedIn()) {
        throw new Exception('Not authenticated');
    }
    
    $auth->requirePermission('invoices.edit');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['invoice_id']) || !isset($input['status'])) {
        throw new Exception('Missing required parameters');
    }
    
    $invoiceId = intval($input['invoice_id']);
    $status = $input['status'];
    
    $validStatuses = ['Draft', 'Sent', 'Viewed', 'Paid', 'Overdue', 'Void'];
    if (!in_array($status, $validStatuses)) {
        throw new Exception('Invalid status');
    }
    
    $db = Database::getInstance();
    $userId = $_SESSION['user_id'] ?? null;
    
    // Check if invoice exists and get current status
    $invoice = $db->getRow("SELECT id, invoice_number, status, branch_id FROM invoices WHERE id = :id", [':id' => $invoiceId]);
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    
    $oldStatus = $invoice['status'] ?? 'Draft';
    $branchId = $invoice['branch_id'] ?? $_SESSION['branch_id'] ?? null;
    
    // Begin transaction for status update and stock operations
    $db->beginTransaction();
    
    try {
        // Update status
        $result = $db->update('invoices', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $invoiceId]);
        
        if ($result === false) {
            throw new Exception('Failed to update invoice status');
        }
        
        // If status changed to "Paid", process stock deduction and sales
        if ($status === 'Paid' && $oldStatus !== 'Paid') {
            // Get invoice items with product_id
            $invoiceItems = $db->getRows("SELECT * FROM invoice_items WHERE invoice_id = :id AND product_id IS NOT NULL", [':id' => $invoiceId]);
            
            if ($invoiceItems !== false && !empty($invoiceItems)) {
                // Deduct stock for each item
                foreach ($invoiceItems as $item) {
                    $productId = intval($item['product_id']);
                    $quantity = intval($item['quantity'] ?? 1);
                    
                    if ($productId > 0 && $quantity > 0) {
                        // Use updateStock function if available
                        if (function_exists('updateStock')) {
                            $GLOBALS['current_transaction_db'] = $db;
                            updateStock($productId, -$quantity, $branchId, 'Sale', true);
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
                                    'movement_type' => 'Sale',
                                    'quantity' => -$quantity,
                                    'previous_quantity' => $previousQuantity,
                                    'new_quantity' => $newQuantity,
                                    'reference_id' => $invoiceId,
                                    'reference_type' => 'Invoice',
                                    'user_id' => $userId,
                                    'notes' => 'Invoice: ' . $invoice['invoice_number'],
                                    'created_at' => date('Y-m-d H:i:s')
                                ]);
                            }
                        }
                    }
                }
            }
        }
        
        // If status changed from "Paid" to something else, reverse stock (restore stock)
        if ($oldStatus === 'Paid' && $status !== 'Paid') {
            // Get invoice items with product_id
            $invoiceItems = $db->getRows("SELECT * FROM invoice_items WHERE invoice_id = :id AND product_id IS NOT NULL", [':id' => $invoiceId]);
            
            if ($invoiceItems !== false && !empty($invoiceItems)) {
                // Restore stock for each item
                foreach ($invoiceItems as $item) {
                    $productId = intval($item['product_id']);
                    $quantity = intval($item['quantity'] ?? 1);
                    
                    if ($productId > 0 && $quantity > 0) {
                        if (function_exists('updateStock')) {
                            $GLOBALS['current_transaction_db'] = $db;
                            updateStock($productId, $quantity, $branchId, 'Return', true);
                        } else {
                            // Manual stock update
                            $product = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id" . ($branchId ? " AND branch_id = :branch_id" : ""),
                                $branchId ? [':id' => $productId, ':branch_id' => $branchId] : [':id' => $productId]);
                            
                            if ($product !== false) {
                                $previousQuantity = (int)($product['quantity_in_stock'] ?? 0);
                                $newQuantity = $previousQuantity + $quantity;
                                
                                $updateWhere = ['id' => $productId];
                                if ($branchId) {
                                    $updateWhere['branch_id'] = $branchId;
                                }
                                
                                $db->update('products', ['quantity_in_stock' => $newQuantity], $updateWhere);
                                
                                $db->insert('stock_movements', [
                                    'product_id' => $productId,
                                    'branch_id' => $branchId,
                                    'movement_type' => 'Return',
                                    'quantity' => $quantity,
                                    'previous_quantity' => $previousQuantity,
                                    'new_quantity' => $newQuantity,
                                    'reference_id' => $invoiceId,
                                    'reference_type' => 'Invoice',
                                    'user_id' => $userId,
                                    'notes' => 'Invoice Status Reversal: ' . $invoice['invoice_number'],
                                    'created_at' => date('Y-m-d H:i:s')
                                ]);
                            }
                        }
                    }
                }
            }
        }
        
        $db->commitTransaction();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        throw $e;
    }
    
    // Log activity
    try {
        logActivity('invoice.status_changed', "Invoice {$invoice['invoice_number']} status changed to {$status}", [
            'invoice_id' => $invoiceId,
            'old_status' => $invoice['status'] ?? 'Unknown',
            'new_status' => $status
        ]);
    } catch (Exception $e) {
        // Log error but don't fail the request
        error_log("Failed to log activity: " . $e->getMessage());
    }
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Invoice status updated successfully'
    ]);
    exit;
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}


