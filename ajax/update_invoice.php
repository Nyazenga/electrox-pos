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
    if (!$auth->hasPermission('invoicing.edit')) {
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
    
    // Only allow editing of PENDING and OVERDUE invoices
    if ($invoice['status'] === 'Paid') {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Paid invoices cannot be edited']);
        ob_end_flush();
        exit;
    }
    
    $db->beginTransaction();
    
    // Validate due date
    $dueDate = $input['due_date'] ?? null;
    if ($dueDate) {
        $dueDateObj = new DateTime($dueDate);
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        $dueDateObj->setTime(0, 0, 0);
        
        if ($dueDateObj < $today) {
            $db->rollbackTransaction();
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Due date cannot be earlier than today\'s date']);
            ob_end_flush();
            exit;
        }
    }
    
    // Update invoice
    $invoiceData = [
        'customer_id' => $input['customer_id'] ?: null,
        'branch_id' => $input['branch_id'] ?: ($_SESSION['branch_id'] ?? null),
        'subtotal' => $input['subtotal'] ?? 0,
        'discount_amount' => $input['discount_amount'] ?? 0,
        'tax_amount' => $input['tax_amount'] ?? 0,
        'total_amount' => $input['total_amount'] ?? 0,
        'balance_due' => $input['total_amount'] ?? 0,
        'invoice_date' => $input['invoice_date'] ?? date('Y-m-d H:i:s'),
        'due_date' => $dueDate,
        'notes' => $input['notes'] ?? null,
        'terms' => $input['terms'] ?? null
    ];
    
    // Update status based on due date if not paid
    if ($invoice['status'] !== 'Paid') {
        if ($dueDate) {
            $dueDateObj = new DateTime($dueDate);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            $dueDateObj->setTime(0, 0, 0);
            
            if ($dueDateObj < $today) {
                $invoiceData['status'] = 'Overdue';
            } else {
                $invoiceData['status'] = 'Pending';
            }
        } else {
            $invoiceData['status'] = 'Pending';
        }
    }
    
    $db->update('invoices', $invoiceData, ['id' => $invoiceId]);
    
    // Delete existing items
    $db->executeQuery("DELETE FROM invoice_items WHERE invoice_id = :id", [':id' => $invoiceId]);
    
    // Insert new items
    if (!empty($input['items']) && is_array($input['items'])) {
        foreach ($input['items'] as $item) {
            $description = $item['description'] ?? '';
            if ($item['product_id']) {
                $product = $db->getRow("SELECT brand, model, product_name FROM products WHERE id = :id", [':id' => $item['product_id']]);
                if ($product) {
                    $description = $product['product_name'] ?? trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? ''));
                }
            }
            
            $itemData = [
                'invoice_id' => $invoiceId,
                'product_id' => $item['product_id'] ?: null,
                'quantity' => $item['quantity'] ?? 1,
                'unit_price' => $item['unit_price'] ?? 0,
                'discount_percentage' => $item['discount_percentage'] ?? 0,
                'discount_amount' => ($item['quantity'] * $item['unit_price']) * ($item['discount_percentage'] / 100),
                'line_total' => $item['line_total'] ?? 0,
                'cost_price' => 0,
                'profit_margin' => 0
            ];
            
            // Add description if column exists
            try {
                $db->insert('invoice_items', array_merge($itemData, ['description' => $description]));
            } catch (Exception $e) {
                // If description column doesn't exist, insert without it
                $db->insert('invoice_items', $itemData);
            }
        }
    }
    
    $db->commitTransaction();
    
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Invoice updated successfully', 'invoice_id' => $invoiceId]);
    ob_end_flush();
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollbackTransaction();
    }
    ob_clean();
    error_log("Update invoice error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to update invoice: ' . $e->getMessage()]);
    ob_end_flush();
}

