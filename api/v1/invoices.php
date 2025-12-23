<?php
/**
 * @OA\Get(
 *     path="/invoices",
 *     tags={"Invoices"},
 *     summary="Get all invoices",
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(response=200, description="List of invoices")
 * )
 * @OA\Post(
 *     path="/invoices",
 *     tags={"Invoices"},
 *     summary="Create new invoice",
 *     security={{"bearerAuth": {}}},
 *     @OA\Response(response=201, description="Invoice created")
 * )
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$db = Database::getInstance();
$pagination = getPaginationParams();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$id = null;
if (isset($pathParts[count($pathParts) - 1]) && is_numeric($pathParts[count($pathParts) - 1])) {
    $id = intval($pathParts[count($pathParts) - 1]);
}

if ($method === 'GET') {
    requirePermission('invoices.view');
    
    if ($id) {
        $invoice = $db->getRow("SELECT i.*, c.first_name, c.last_name, b.branch_name
                               FROM invoices i
                               LEFT JOIN customers c ON i.customer_id = c.id
                               LEFT JOIN branches b ON i.branch_id = b.id
                               WHERE i.id = :id", [':id' => $id]);
        
        if (!$invoice) {
            sendError('Invoice not found', 404);
        }
        
        $items = $db->getRows("SELECT * FROM invoice_items WHERE invoice_id = :id", [':id' => $id]);
        $invoice['items'] = $items ?: [];
        
        sendSuccess($invoice);
    } else {
        $whereConditions = ["1=1"];
        $params = [];
        
        if (isset($_GET['status']) && $_GET['status'] !== 'all') {
            $whereConditions[] = "i.status = :status";
            $params[':status'] = $_GET['status'];
        }
        
        if ($user['branch_id']) {
            $whereConditions[] = "i.branch_id = :branch_id";
            $params[':branch_id'] = $user['branch_id'];
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $total = $db->getRow("SELECT COUNT(*) as count FROM invoices i WHERE $whereClause", $params);
        $totalCount = $total ? intval($total['count']) : 0;
        
        $invoices = $db->getRows("SELECT i.*, c.first_name, c.last_name, b.branch_name
                                  FROM invoices i
                                  LEFT JOIN customers c ON i.customer_id = c.id
                                  LEFT JOIN branches b ON i.branch_id = b.id
                                  WHERE $whereClause
                                  ORDER BY i.created_at DESC
                                  LIMIT :limit OFFSET :offset",
                                  array_merge($params, [
                                      ':limit' => $pagination['limit'],
                                      ':offset' => $pagination['offset']
                                  ]));
        
        if ($invoices === false) {
            $invoices = [];
        }
        
        $response = formatPaginatedResponse($invoices, $totalCount, $pagination['page'], $pagination['limit']);
        sendSuccess($response);
    }
} elseif ($method === 'POST') {
    requirePermission('invoices.create');
    
    $input = getRequestBody();
    
    // Validate required fields
    if (!isset($input['invoice_type'])) {
        sendError('Invoice type is required', 400);
    }
    
    if (!isset($input['items']) || empty($input['items'])) {
        sendError('Invoice items are required', 400);
    }
    
    // Generate invoice number
    $invoiceType = $input['invoice_type'];
    $prefix = [
        'Proforma' => 'PROF',
        'TaxInvoice' => 'TAX',
        'Quote' => 'QUO',
        'CreditNote' => 'CRN',
        'Receipt' => 'INV'
    ][$invoiceType] ?? 'INV';
    
    $datePrefix = date('Ymd');
    $maxAttempts = 10;
    $invoiceNumber = null;
    
    for ($i = 0; $i < $maxAttempts; $i++) {
        $sequence = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $candidate = $prefix . '-' . $datePrefix . '-' . $sequence;
        
        $exists = $db->getRow("SELECT id FROM invoices WHERE invoice_number = :num", [':num' => $candidate]);
        if (!$exists) {
            $invoiceNumber = $candidate;
            break;
        }
    }
    
    if (!$invoiceNumber) {
        sendError('Failed to generate unique invoice number', 500);
    }
    
    $db->beginTransaction();
    
    try {
        // Create invoice
        $invoiceData = [
            'invoice_number' => $invoiceNumber,
            'invoice_type' => $invoiceType,
            'customer_id' => $input['customer_id'] ?? null,
            'branch_id' => $user['branch_id'] ?? $input['branch_id'] ?? null,
            'user_id' => $user['id'],
            'subtotal' => floatval($input['subtotal'] ?? 0),
            'discount_amount' => floatval($input['discount_amount'] ?? 0),
            'tax_amount' => floatval($input['tax_amount'] ?? 0),
            'total_amount' => floatval($input['total_amount'] ?? 0),
            'balance_due' => floatval($input['total_amount'] ?? 0),
            'invoice_date' => $input['invoice_date'] ?? date('Y-m-d H:i:s'),
            'due_date' => $input['due_date'] ?? null,
            'status' => 'Draft',
            'notes' => $input['notes'] ?? null,
            'terms' => $input['terms'] ?? null
        ];
        
        $invoiceId = $db->insert('invoices', $invoiceData);
        
        if (!$invoiceId) {
            throw new Exception('Failed to create invoice: ' . $db->getLastError());
        }
        
        // Create invoice items
        foreach ($input['items'] as $item) {
            $itemData = [
                'invoice_id' => $invoiceId,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? '',
                'quantity' => intval($item['quantity'] ?? 1),
                'unit_price' => floatval($item['unit_price'] ?? 0),
                'line_total' => floatval($item['line_total'] ?? ($item['unit_price'] * $item['quantity']))
            ];
            
            $itemId = $db->insert('invoice_items', $itemData);
            if (!$itemId) {
                throw new Exception('Failed to create invoice item');
            }
        }
        
        $db->commitTransaction();
        
        $invoice = $db->getRow("SELECT * FROM invoices WHERE id = :id", [':id' => $invoiceId]);
        $items = $db->getRows("SELECT * FROM invoice_items WHERE invoice_id = :id", [':id' => $invoiceId]);
        $invoice['items'] = $items ?: [];
        
        sendSuccess($invoice, 'Invoice created successfully', 201);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollbackTransaction();
        }
        sendError('Failed to create invoice: ' . $e->getMessage(), 500);
    }
    
} elseif ($method === 'PUT') {
    // Check if it's a status update
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (strpos($path, '/status') !== false) {
        // Update invoice status
        requirePermission('invoices.edit');
        
        if (!$id) {
            sendError('Invoice ID is required', 400);
        }
        
        $input = getRequestBody();
        $status = $input['status'] ?? null;
        
        if (!$status) {
            sendError('Status is required', 400);
        }
        
        // Update status using existing logic
        $invoice = $db->getRow("SELECT * FROM invoices WHERE id = :id", [':id' => $id]);
        if (!$invoice) {
            sendError('Invoice not found', 404);
        }
        
        // Use the existing update_invoice_status.php logic by calling it via HTTP internally
        // For now, do a simple status update
        $result = $db->update('invoices', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $id]);
        
        if ($result === false) {
            sendError('Failed to update invoice status', 500);
        }
        
        // If status is Paid, trigger sale creation (simplified version)
        if ($status === 'Paid' && $invoice['status'] !== 'Paid') {
            // This would normally call the full update_invoice_status.php logic
            // For API, we'll do a simplified version
            sendSuccess(['invoice_id' => $id, 'status' => $status], 'Invoice status updated. Note: Sale creation from invoice payment requires full implementation.');
        } else {
            sendSuccess(['invoice_id' => $id, 'status' => $status], 'Invoice status updated successfully');
        }
    } else {
        sendError('Method not allowed', 405);
    }
    
} else {
    sendError('Method not allowed', 405);
}

