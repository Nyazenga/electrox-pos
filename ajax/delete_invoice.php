<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

ob_start();

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

ob_clean();

try {
    initSession();
    
    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        ob_end_flush();
        exit;
    }
    
    $auth = Auth::getInstance();
    // Check for both permission formats for backward compatibility
    if (!$auth->hasPermission('invoicing.delete') && !$auth->hasPermission('invoices.delete')) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
        ob_end_flush();
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['invoice_id'])) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid request data']);
        ob_end_flush();
        exit;
    }
    
    $db = Database::getInstance();
    $invoiceId = intval($input['invoice_id']);
    
    // Get invoice
    $invoice = $db->getRow("SELECT * FROM invoices WHERE id = :id", [':id' => $invoiceId]);
    if (!$invoice) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        ob_end_flush();
        exit;
    }
    
    // Check if invoice is paid or has been converted to sale
    if ($invoice['status'] === 'Paid') {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Cannot delete paid invoices']);
        ob_end_flush();
        exit;
    }
    
    // Check if invoice has been converted to sale (has linked sale)
    $saleCount = $db->getRow("SELECT COUNT(*) as count FROM sales WHERE invoice_id = :id", [':id' => $invoiceId]);
    if ($saleCount && intval($saleCount['count']) > 0) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Cannot delete invoice that has been converted to a sale']);
        ob_end_flush();
        exit;
    }
    
    // Check if invoice has payments
    $paymentCount = $db->getRow("SELECT COUNT(*) as count FROM payments WHERE invoice_id = :id", [':id' => $invoiceId]);
    if ($paymentCount && intval($paymentCount['count']) > 0) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Cannot delete invoice that has payments']);
        ob_end_flush();
        exit;
    }
    
    $db->beginTransaction();
    
    try {
        // Delete invoice items first (foreign key constraint)
        $db->executeQuery("DELETE FROM invoice_items WHERE invoice_id = :id", [':id' => $invoiceId]);
        
        // Delete invoice
        $result = $db->delete('invoices', ['id' => $invoiceId]);
        
        if ($result) {
            $db->commitTransaction();
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Invoice deleted successfully']);
            ob_end_flush();
        } else {
            $db->rollbackTransaction();
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to delete invoice']);
            ob_end_flush();
        }
    } catch (Exception $e) {
        $db->rollbackTransaction();
        throw $e;
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollbackTransaction();
    }
    ob_clean();
    error_log("Delete invoice error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to delete invoice: ' . $e->getMessage()]);
    ob_end_flush();
}
