<?php
/**
 * Delete Receipt (Soft Delete)
 * Restores stock and updates shift cash
 */

// Suppress ALL output and errors to ensure clean JSON
error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any accidental output
ob_start();

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

// Clear any output that might have been generated
ob_clean();

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('pos.access');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$saleId = intval($_POST['sale_id'] ?? 0);
$userId = $_SESSION['user_id'] ?? null;

if (!$saleId || !$userId) {
    echo json_encode(['success' => false, 'message' => 'Invalid sale ID or user not logged in']);
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

try {
    $db->beginTransaction();
    
    // Get sale details - check deleted_at only if column exists
    $saleQuery = "SELECT * FROM sales WHERE id = :id";
    if ($hasDeletedAtColumn) {
        $saleQuery .= " AND deleted_at IS NULL";
    }
    $sale = $db->getRow($saleQuery, [':id' => $saleId]);
    
    if (!$sale) {
        throw new Exception('Sale not found or already deleted');
    }
    
    // Get sale items
    $items = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :id", [':id' => $saleId]);
    if ($items === false) {
        $items = [];
    }
    
    // Restore stock for each item
    foreach ($items as $item) {
        if ($item['product_id']) {
            // Restore stock - add quantity back
            $stmt = $db->executeQuery("UPDATE products 
                         SET quantity_in_stock = quantity_in_stock + :qty 
                         WHERE id = :product_id", 
                         [':qty' => $item['quantity'], ':product_id' => $item['product_id']]);
            if ($stmt === false) {
                throw new Exception('Failed to restore stock for product ID: ' . $item['product_id']);
            }
        }
    }
    
    // Get sale payments to reverse shift cash
    $payments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $saleId]);
    if ($payments === false) {
        $payments = [];
    }
    
    // Reverse shift cash for cash payments
    foreach ($payments as $payment) {
        if ($payment['payment_method'] === 'cash' && $sale['shift_id']) {
            // Subtract from expected cash
            $stmt = $db->executeQuery("UPDATE shifts 
                         SET expected_cash = expected_cash - :amount 
                         WHERE id = :shift_id", 
                         [':amount' => $payment['amount'], ':shift_id' => $sale['shift_id']]);
            if ($stmt === false) {
                throw new Exception('Failed to reverse shift cash');
            }
        }
    }
    
    // Soft delete the sale (if columns exist)
    if ($hasDeletedAtColumn) {
        $stmt = $db->executeQuery("UPDATE sales 
                     SET deleted_at = NOW(), 
                         deleted_by = :user_id 
                     WHERE id = :id", 
                     [':user_id' => $userId, ':id' => $saleId]);
        if ($stmt === false) {
            throw new Exception('Failed to mark sale as deleted');
        }
    } else {
        // If columns don't exist, we need to add them first (outside transaction)
        // Commit current transaction first
        $db->commitTransaction();
        
        try {
            // Check if columns exist before adding
            $colCheck = $db->getRow("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                                    WHERE TABLE_SCHEMA = DATABASE() 
                                    AND TABLE_NAME = 'sales' 
                                    AND COLUMN_NAME = 'deleted_at'");
            
            if (!$colCheck || $colCheck['count'] == 0) {
                // Add columns (ALTER TABLE can't be in a transaction)
                $db->executeQuery("ALTER TABLE sales ADD COLUMN deleted_at DATETIME NULL AFTER updated_at");
                $db->executeQuery("ALTER TABLE sales ADD COLUMN deleted_by INT(11) NULL AFTER deleted_at");
            }
            
            // Check if index exists before adding
            $idxCheck = $db->getRow("SELECT COUNT(*) as count FROM information_schema.STATISTICS 
                                    WHERE TABLE_SCHEMA = DATABASE() 
                                    AND TABLE_NAME = 'sales' 
                                    AND INDEX_NAME = 'idx_deleted_at'");
            if (!$idxCheck || $idxCheck['count'] == 0) {
                $db->executeQuery("ALTER TABLE sales ADD KEY idx_deleted_at (deleted_at)");
            }
            
            // Start new transaction for the update
            $db->beginTransaction();
            
            // Now update the sale
            $stmt = $db->executeQuery("UPDATE sales 
                         SET deleted_at = NOW(), 
                             deleted_by = :user_id 
                         WHERE id = :id", 
                         [':user_id' => $userId, ':id' => $saleId]);
            if ($stmt === false) {
                throw new Exception('Failed to mark sale as deleted');
            }
        } catch (Exception $e) {
            // If we can't add columns, provide helpful error
            throw new Exception('Failed to add delete columns. Please run: database/add_delete_receipt_columns.sql. Error: ' . $e->getMessage());
        }
    }
    
    $db->commitTransaction();
    
    // Clear any output before sending JSON
    ob_clean();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Receipt deleted successfully. Stock has been restored.'
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
    
    error_log("Delete receipt error: " . $e->getMessage());
    
    // Clear any output before sending JSON
    ob_clean();
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to delete receipt: ' . $e->getMessage()
    ]);
    exit;
}

