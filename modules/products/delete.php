<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.delete');

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

$productId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$productId) {
    $_SESSION['error_message'] = 'Invalid product ID.';
    header('Location: ' . BASE_URL . 'modules/products/index.php');
    exit;
}

// Get product details
$product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $productId]);

if (!$product) {
    $_SESSION['error_message'] = 'Product not found.';
    header('Location: ' . BASE_URL . 'modules/products/index.php');
    exit;
}

// Check if product has been sold (has sale_items)
$saleCount = $db->getRow("SELECT COUNT(*) as count FROM sale_items WHERE product_id = :id", [':id' => $productId]);
$hasSales = ($saleCount && intval($saleCount['count']) > 0);

// Check if product is in any invoices
$invoiceCount = $db->getRow("SELECT COUNT(*) as count FROM invoice_items WHERE product_id = :id", [':id' => $productId]);
$hasInvoices = ($invoiceCount && intval($invoiceCount['count']) > 0);

// Check if product is in stock transfers
$transferCount = $primaryDb->getRow("SELECT COUNT(*) as count FROM stock_movements WHERE product_id = :id", [':id' => $productId]);
$hasTransfers = ($transferCount && intval($transferCount['count']) > 0);

// Check if product is in refund_items
$refundCount = $db->getRow("SELECT COUNT(*) as count FROM refund_items WHERE product_id = :id", [':id' => $productId]);
$hasRefunds = ($refundCount && intval($refundCount['count']) > 0);

// If product has any sales, invoices, transfers, or refunds, we should DEACTIVATE instead of DELETE
// This preserves data integrity for reports and historical records
if ($hasSales || $hasInvoices || $hasTransfers || $hasRefunds) {
    // Deactivate the product instead of deleting
    try {
        $result = $db->update('products', [
            'status' => 'Inactive',
            'quantity_in_stock' => 0,
            'updated_by' => $_SESSION['user_id'] ?? null,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $productId]);
        
        if ($result) {
            $_SESSION['success_message'] = 'Product has been deactivated (not deleted) because it has historical sales, invoices, or transfers. This preserves data integrity for reports.';
        } else {
            $_SESSION['error_message'] = 'Failed to deactivate product: ' . $db->getLastError();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error deactivating product: ' . $e->getMessage();
    }
} else {
    // Product has no dependencies - safe to delete
    try {
        // Delete from product_favorites if exists
        $db->delete('product_favorites', ['product_id' => $productId]);
        
        // Delete the product
        $result = $db->delete('products', ['id' => $productId]);
        
        if ($result) {
            $_SESSION['success_message'] = 'Product has been deleted successfully.';
        } else {
            $_SESSION['error_message'] = 'Failed to delete product: ' . $db->getLastError();
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error deleting product: ' . $e->getMessage();
    }
}

// Redirect back to products index
header('Location: ' . BASE_URL . 'modules/products/index.php');
exit;

