<?php
/**
 * Delete Sale (Soft Delete)
 * - Restores stock quantities
 * - Restores specific items (product_specific_list) if applicable
 * - Reverses shift cash for cash payments
 * - Soft-deletes the sale record
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

// Check if deleted_at column exists
$hasDeletedAtColumn = false;
try {
    $colCheck = $db->getRow("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'sales' 
                            AND COLUMN_NAME = 'deleted_at'");
    $hasDeletedAtColumn = ($colCheck && $colCheck['count'] > 0);
} catch (Exception $e) {
    $hasDeletedAtColumn = false;
}

// Check if delete_reason column exists
$hasReasonColumn = false;
try {
    $colCheck = $db->getRow("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'sales' 
                            AND COLUMN_NAME = 'delete_reason'");
    $hasReasonColumn = ($colCheck && $colCheck['count'] > 0);
} catch (Exception $e) {
    $hasReasonColumn = false;
}

try {
    $db->beginTransaction();
    
    // Get sale details
    $saleQuery = "SELECT * FROM sales WHERE id = :id";
    if ($hasDeletedAtColumn) {
        $saleQuery .= " AND deleted_at IS NULL";
    }
    $sale = $db->getRow($saleQuery, [':id' => $saleId]);
    
    if (!$sale) {
        throw new Exception('Sale not found or already deleted');
    }
    
    // Check if sale has been fiscalized - warn but allow deletion
    $isFiscalized = false;
    try {
        $fiscalReceipt = $db->getRow("SELECT id FROM fiscal_receipt_log WHERE sale_id = :sale_id LIMIT 1", [':sale_id' => $saleId]);
        $isFiscalized = !empty($fiscalReceipt);
    } catch (Exception $e) {
        // fiscal_receipt_log may not exist
    }
    
    // Get sale items
    $items = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :id", [':id' => $saleId]);
    if ($items === false) $items = [];
    
    $branchId = $sale['branch_id'] ?? null;
    
    // Restore stock and specific items for each sale item
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
                    // Re-create the specific item entry
                    $restoreData = [
                        'product_id' => $item['product_id'],
                        'branch_id' => $branchId,
                        'status' => 'available',
                        'created_by' => $userId
                    ];
                    
                    // Restore all known fields
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
                'reference' => 'Sale #' . ($sale['receipt_number'] ?? $saleId) . ' deleted',
                'user_id' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("Warning: Could not create stock movement for delete: " . $e->getMessage());
        }
    }
    
    // Reverse shift cash for cash payments
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
    
    // Ensure deleted_at and related columns exist
    if (!$hasDeletedAtColumn) {
        $db->commitTransaction();
        try {
            $db->executeQuery("ALTER TABLE sales ADD COLUMN deleted_at DATETIME NULL");
            $db->executeQuery("ALTER TABLE sales ADD COLUMN deleted_by INT(11) NULL");
        } catch (Exception $e) {
            // Columns may already exist
        }
        $hasDeletedAtColumn = true;
        $db->beginTransaction();
    }
    
    if (!$hasReasonColumn) {
        try {
            $db->commitTransaction();
            $db->executeQuery("ALTER TABLE sales ADD COLUMN delete_reason TEXT NULL");
            $hasReasonColumn = true;
            $db->beginTransaction();
        } catch (Exception $e) {
            // Column may already exist
        }
    }
    
    // Soft-delete the sale
    $updateSql = "UPDATE sales SET deleted_at = NOW(), deleted_by = :user_id";
    $updateParams = [':user_id' => $userId, ':id' => $saleId];
    if ($hasReasonColumn) {
        $updateSql .= ", delete_reason = :reason";
        $updateParams[':reason'] = $reason;
    }
    $updateSql .= " WHERE id = :id";
    
    $stmt = $db->executeQuery($updateSql, $updateParams);
    if ($stmt === false) {
        throw new Exception('Failed to mark sale as deleted');
    }
    
    $db->commitTransaction();
    
    ob_clean();
    
    $message = 'Sale deleted successfully. Stock has been restored.';
    if ($isFiscalized) {
        $message .= ' Note: This sale was fiscalized with ZIMRA - the fiscal record remains.';
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
