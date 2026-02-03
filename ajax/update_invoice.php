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
        'terms' => $input['terms'] ?? null,
        'terms_id' => isset($input['terms_id']) && $input['terms_id'] ? intval($input['terms_id']) : null,
        'banking_details_included' => isset($input['banking_details_included']) ? intval($input['banking_details_included']) : 1
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
    
    // Check if description column exists (once, outside the loop)
    $hasDescriptionColumn = false;
    try {
        $columnCheck = $db->getRow("SHOW COLUMNS FROM invoice_items WHERE Field = 'description'");
        $hasDescriptionColumn = !empty($columnCheck);
    } catch (Exception $e) {
        // Column check failed, assume it doesn't exist
        $hasDescriptionColumn = false;
    }
    
    // Delete existing items ONLY if new items are provided
    // This prevents accidental deletion of items if update is called without items array
    if (!empty($input['items']) && is_array($input['items']) && count($input['items']) > 0) {
        $db->executeQuery("DELETE FROM invoice_items WHERE invoice_id = :id", [':id' => $invoiceId]);
        
        // Insert new items
        $insertedCount = 0;
        $insertErrors = [];
        
        foreach ($input['items'] as $index => $item) {
            try {
                $description = $item['description'] ?? '';
                if (!empty($item['product_id'])) {
                    $product = $db->getRow("SELECT brand, model, product_name FROM products WHERE id = :id", [':id' => $item['product_id']]);
                    if ($product) {
                        $description = $product['product_name'] ?? trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? ''));
                    }
                }
                
                $itemData = [
                    'invoice_id' => $invoiceId,
                    'product_id' => !empty($item['product_id']) ? intval($item['product_id']) : null,
                    'quantity' => intval($item['quantity'] ?? 1),
                    'unit_price' => floatval($item['unit_price'] ?? 0),
                    'discount_percentage' => floatval($item['discount_percentage'] ?? 0),
                    'discount_amount' => (intval($item['quantity'] ?? 1) * floatval($item['unit_price'] ?? 0)) * (floatval($item['discount_percentage'] ?? 0) / 100),
                    'line_total' => floatval($item['line_total'] ?? 0),
                    'cost_price' => 0,
                    'profit_margin' => 0
                ];
                
                // Add description if column exists
                if ($hasDescriptionColumn && !empty($description)) {
                    $itemData['description'] = $description;
                }
                
                $insertResult = $db->insert('invoice_items', $itemData);
                if ($insertResult) {
                    $insertedCount++;
                } else {
                    $error = $db->getLastError();
                    $insertErrors[] = "Item " . ($index + 1) . ": " . ($error ?: 'Insert failed');
                    error_log("Failed to insert invoice item $index for invoice $invoiceId: " . ($error ?: 'Unknown error'));
                }
            } catch (Exception $e) {
                $insertErrors[] = "Item " . ($index + 1) . ": " . $e->getMessage();
                error_log("Exception inserting invoice item $index: " . $e->getMessage());
            }
        }
        
        // If no items were inserted, rollback and throw error
        if ($insertedCount === 0) {
            $db->rollbackTransaction();
            $errorMsg = !empty($insertErrors) ? implode('; ', $insertErrors) : 'No items were saved. Please check the item data.';
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to save invoice items: ' . $errorMsg]);
            ob_end_flush();
            exit;
        }
        
        // If some items failed, log warning but continue
        if (!empty($insertErrors)) {
            error_log("Some invoice items failed to save for invoice $invoiceId: " . implode('; ', $insertErrors));
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


