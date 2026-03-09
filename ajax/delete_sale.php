<?php
/**
 * Delete Sale (Hard Delete)
 * - Restores stock quantities
 * - Restores specific items (product_specific_list) if applicable
 * - Reverses shift cash for cash payments
 * - Permanently removes the sale and all related records from the database
 * Requires: sales.delete permission
 */

error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

ob_start();

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

ob_clean();
header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('sales.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$saleId = intval($input['sale_id'] ?? $_POST['sale_id'] ?? 0);
$reason = trim($input['reason'] ?? $_POST['reason'] ?? '');
$userId = $_SESSION['user_id'] ?? null;

if (!$saleId || !$userId) {
    echo json_encode(['success' => false, 'message' => 'Invalid sale ID or user not logged in']);
    exit;
}

if (empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a reason for deletion']);
    exit;
}

$db = Database::getInstance();

try {
    $db->beginTransaction();
    
    // Get sale details
    $sale = $db->getRow("SELECT * FROM sales WHERE id = :id", [':id' => $saleId]);
    
    if (!$sale) {
        throw new Exception('Sale not found');
    }
    
    // Check if sale has been fiscalized - warn but allow deletion
    $isFiscalized = false;
    try {
        $fiscalReceipt = $db->getRow("SELECT id FROM fiscal_receipts WHERE sale_id = :sale_id LIMIT 1", [':sale_id' => $saleId]);
        $isFiscalized = !empty($fiscalReceipt);
    } catch (Exception $e) {
        // fiscal_receipts table may not exist
    }
    
    // Get sale items before deletion
    $items = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :id", [':id' => $saleId]);
    if ($items === false) $items = [];
    
    $branchId = $sale['branch_id'] ?? null;
    
    // 1. Restore stock and specific items for each sale item
    foreach ($items as $item) {
        if (!$item['product_id']) continue;
        
        // Check if this product requires specific list
        $product = $db->getRow("SELECT p.*, pc.is_specific, pc.name as category_name 
                                FROM products p 
                                LEFT JOIN product_categories pc ON p.category_id = pc.id 
                                WHERE p.id = :id", [':id' => $item['product_id']]);
        
        $isSpecificProduct = $product && !empty($product['is_specific']);
        
        if ($isSpecificProduct && !empty($item['specific_item_data'])) {
            // Restore specific items from the JSON data saved at sale time
            $specificItems = json_decode($item['specific_item_data'], true);
            if (is_array($specificItems)) {
                foreach ($specificItems as $specificData) {
                    $restoreData = [
                        'product_id' => $item['product_id'],
                        'branch_id' => $branchId,
                        'status' => 'available',
                        'created_by' => $userId
                    ];
                    
                    $knownFields = ['color', 'storage', 'serial_number', 'imei', 'sim_configuration', 
                                   'battery_health', 'manufacturer', 'warranty_months', 'warranty_terms',
                                   'condition', 'trade_in_eligible', 'cost_price', 'selling_price', 'wholesale_price'];
                    foreach ($knownFields as $field) {
                        if (isset($specificData[$field])) {
                            $restoreData[$field] = $specificData[$field];
                        }
                    }
                    
                    try {
                        $db->insert('product_specific_list', $restoreData);
                    } catch (Exception $e) {
                        error_log("Warning: Could not restore specific item for product {$item['product_id']}: " . $e->getMessage());
                    }
                }
                
                // Update product quantity to match available specific items count
                $count = getProductSpecificListCount($item['product_id'], $branchId, 'available', $db);
                $db->update('products', ['quantity_in_stock' => $count], ['id' => $item['product_id']]);
            }
        } else {
            // Normal product: restore stock quantity
            $db->executeQuery("UPDATE products 
                             SET quantity_in_stock = quantity_in_stock + :qty 
                             WHERE id = :product_id", 
                             [':qty' => $item['quantity'], ':product_id' => $item['product_id']]);
        }
        
        // Add stock movement record for the reversal
        try {
            $currentQty = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id", [':id' => $item['product_id']]);
            $db->insert('stock_movements', [
                'product_id' => $item['product_id'],
                'branch_id' => $branchId,
                'movement_type' => 'Sale Deleted',
                'quantity' => $item['quantity'],
                'previous_quantity' => ($currentQty['quantity_in_stock'] ?? 0) - $item['quantity'],
                'new_quantity' => $currentQty['quantity_in_stock'] ?? 0,
                'reference' => 'Sale #' . ($sale['receipt_number'] ?? $saleId) . ' deleted - Reason: ' . $reason,
                'user_id' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("Warning: Could not create stock movement for delete: " . $e->getMessage());
        }
    }
    
    // 2. Reverse shift cash for cash payments
    $payments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $saleId]);
    if ($payments === false) $payments = [];
    
    foreach ($payments as $payment) {
        if ($payment['payment_method'] === 'cash' && $sale['shift_id']) {
            $db->executeQuery("UPDATE shifts 
                             SET expected_cash = expected_cash - :amount 
                             WHERE id = :shift_id", 
                             [':amount' => $payment['amount'], ':shift_id' => $sale['shift_id']]);
        }
    }
    
    // 3. Delete all related records (order matters for foreign key constraints)
    
    // Delete credit notes linked to this sale
    try {
        $db->executeQuery("DELETE FROM credit_notes WHERE sale_id = :sale_id", [':sale_id' => $saleId]);
    } catch (Exception $e) {
        error_log("Warning: Could not delete credit_notes: " . $e->getMessage());
    }
    
    // Delete refunds linked to this sale
    try {
        $db->executeQuery("DELETE FROM refunds WHERE sale_id = :sale_id", [':sale_id' => $saleId]);
    } catch (Exception $e) {
        error_log("Warning: Could not delete refunds: " . $e->getMessage());
    }
    
    // Delete account payments linked to this sale
    try {
        $db->executeQuery("DELETE FROM account_payments WHERE sale_id = :sale_id", [':sale_id' => $saleId]);
    } catch (Exception $e) {
        error_log("Warning: Could not delete account_payments: " . $e->getMessage());
    }
    
    // Nullify sale_id on laybyes (ON DELETE SET NULL)
    try {
        $db->executeQuery("UPDATE laybyes SET sale_id = NULL WHERE sale_id = :sale_id", [':sale_id' => $saleId]);
    } catch (Exception $e) {
        error_log("Warning: Could not update laybyes: " . $e->getMessage());
    }
    
    // Delete fiscal receipts linked to this sale
    try {
        $db->executeQuery("DELETE FROM fiscal_receipts WHERE sale_id = :sale_id", [':sale_id' => $saleId]);
    } catch (Exception $e) {
        error_log("Warning: Could not delete fiscal_receipts: " . $e->getMessage());
    }
    
    // Delete sale payments
    $db->executeQuery("DELETE FROM sale_payments WHERE sale_id = :sale_id", [':sale_id' => $saleId]);
    
    // Delete sale items
    $db->executeQuery("DELETE FROM sale_items WHERE sale_id = :sale_id", [':sale_id' => $saleId]);
    
    // 4. Delete the sale record itself
    $db->executeQuery("DELETE FROM sales WHERE id = :id", [':id' => $saleId]);
    
    $db->commitTransaction();
    
    ob_clean();
    
    $message = 'Sale permanently deleted. Stock has been restored.';
    if ($isFiscalized) {
        $message .= ' Note: This sale was fiscalized with ZIMRA - the fiscal record has also been removed locally.';
    }
    
    echo json_encode([
        'success' => true, 
        'message' => $message
    ]);
    exit;
    
} catch (Exception $e) {
    try {
        if (isset($db) && $db) {
            $db->rollbackTransaction();
        }
    } catch (Exception $rollbackError) {
        // Ignore rollback errors
    }
    
    error_log("Delete sale error: " . $e->getMessage());
    
    ob_clean();
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to delete sale: ' . $e->getMessage()
    ]);
    exit;
}
