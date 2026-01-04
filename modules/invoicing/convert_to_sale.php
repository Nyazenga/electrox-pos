<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('invoicing.create', 'pos.create_sale');

$invoiceId = intval($_GET['id'] ?? 0);
if (!$invoiceId) {
    redirectTo('modules/invoicing/index.php');
}

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get invoice details
$invoice = $db->getRow("SELECT * FROM invoices WHERE id = :id AND invoice_type = 'Proforma'", [':id' => $invoiceId]);

if (!$invoice) {
    redirectTo('modules/invoicing/index.php');
}

// Check if invoice is already paid
if ($invoice['status'] === 'Paid') {
    $_SESSION['error_message'] = 'This invoice has already been paid and converted to a sale.';
    redirectTo('modules/invoicing/index.php');
}

// Use invoice's branch_id, not session branch_id
$invoiceBranchId = $invoice['branch_id'] ?? null;
if (!$invoiceBranchId) {
    $_SESSION['error_message'] = 'Invoice does not have a branch assigned. Cannot convert to sale.';
    redirectTo('modules/invoicing/index.php');
}

// Update session branch_id to match invoice's branch for fiscalization
$_SESSION['branch_id'] = $invoiceBranchId;

// Get invoice items
$invoiceItems = $db->getRows("SELECT * FROM invoice_items WHERE invoice_id = :id", [':id' => $invoiceId]);
if ($invoiceItems === false) {
    $invoiceItems = [];
}

if (empty($invoiceItems)) {
    $_SESSION['error_message'] = 'This invoice has no items. Cannot convert to sale.';
    redirectTo('modules/invoicing/index.php');
}

// Get customer
$customer = null;
if ($invoice['customer_id']) {
    $customer = $db->getRow("SELECT * FROM customers WHERE id = :id", [':id' => $invoice['customer_id']]);
}

// Get products and validate stock IN THE INVOICE'S BRANCH
$products = [];
$stockIssues = [];
foreach ($invoiceItems as $item) {
    if ($item['product_id']) {
        // Validate product exists in the invoice's branch
        $product = $db->getRow("SELECT p.*, pc.tax_id as category_tax_id 
                                 FROM products p 
                                 LEFT JOIN product_categories pc ON p.category_id = pc.id 
                                 WHERE p.id = :id AND p.branch_id = :branch_id", 
                                 [':id' => $item['product_id'], ':branch_id' => $invoiceBranchId]);
        if (!$product) {
            // Product not found in invoice's branch
            $productName = 'Product ID ' . $item['product_id'];
            $stockIssues[] = $productName . ' is not available in the invoice branch (Branch ID: ' . $invoiceBranchId . ')';
            continue;
        }
        
        $products[$item['product_id']] = $product;
        
        // Validate stock availability
        if ($product['quantity_in_stock'] < $item['quantity']) {
            $productName = $product['product_name'] ?? trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? ''));
            $stockIssues[] = $productName . ' (Stock: ' . $product['quantity_in_stock'] . ', Required: ' . $item['quantity'] . ')';
        }
    }
}

// Prepare cart data for POS payment page
// Only include items with product_id (manual items cannot be converted to sales)
$cart = [];
$manualItems = [];
foreach ($invoiceItems as $item) {
    if (empty($item['product_id'])) {
        // Manual item - cannot be converted (sale_items requires product_id)
        $manualItems[] = $item['description'] ?? 'Manual Item';
        continue;
    }
    
    $product = $products[$item['product_id']] ?? null;
    if (!$product) {
        // Product not found - skip
        $manualItems[] = 'Product ID ' . $item['product_id'];
        continue;
    }
    
    $productName = $product['product_name'] ?? trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? ''));
    
    // Get tax information from product
    $productTaxId = $product['tax_id'] ?? null;
    $categoryTaxId = $product['category_tax_id'] ?? null;
    $finalTaxId = $productTaxId ?: $categoryTaxId;
    
    // Get tax percent from fiscal config if available (use invoice's branch)
    $taxPercent = 0;
    if ($finalTaxId && $invoiceBranchId) {
        $fiscalConfig = $primaryDb->getRow(
            "SELECT applicable_taxes FROM fiscal_config WHERE branch_id = :branch_id LIMIT 1",
            [':branch_id' => $invoiceBranchId]
        );
        if ($fiscalConfig && !empty($fiscalConfig['applicable_taxes'])) {
            $applicableTaxes = json_decode($fiscalConfig['applicable_taxes'], true);
            if (is_array($applicableTaxes)) {
                foreach ($applicableTaxes as $tax) {
                    if (isset($tax['taxID']) && intval($tax['taxID']) == intval($finalTaxId)) {
                        $taxPercent = isset($tax['taxPercent']) ? floatval($tax['taxPercent']) : 0;
                        break;
                    }
                }
            }
        }
    }
    
    $cart[] = [
        'id' => intval($item['product_id']), // process_sale.php expects 'id' not 'product_id'
        'name' => $productName,
        'price' => floatval($item['unit_price']),
        'quantity' => intval($item['quantity']),
        'tax_percent' => $taxPercent,
        'tax_id' => $finalTaxId
    ];
}

// Check if cart is empty (all products don't exist or were excluded)
if (empty($cart)) {
    $missingProducts = [];
    foreach ($invoiceItems as $item) {
        if ($item['product_id']) {
            $product = $products[$item['product_id']] ?? null;
            if (!$product) {
                $missingProducts[] = 'Product ID ' . $item['product_id'];
            }
        }
    }
    
    if (!empty($missingProducts)) {
        $_SESSION['error_message'] = 'Cannot convert to sale: The following products no longer exist in the invoice branch: ' . implode(', ', array_unique($missingProducts)) . '. Please update the invoice or ensure the products are available in the branch.';
        redirectTo('modules/invoicing/index.php');
    } else {
        $_SESSION['error_message'] = 'Cannot convert to sale: No valid products found to convert. All items were excluded.';
        redirectTo('modules/invoicing/index.php');
    }
}

// Warn if manual items were excluded
if (!empty($manualItems)) {
    $_SESSION['warning_message'] = 'Some manual items (without products) were excluded from conversion: ' . implode(', ', $manualItems) . '. Only items with products can be converted to sales.';
}

// Calculate discount
$discount = [
    'type' => 'value',
    'amount' => floatval($invoice['discount_amount'])
];

// Store invoice conversion data in session
$_SESSION['invoice_to_sale'] = [
    'invoice_id' => $invoiceId,
    'invoice_number' => $invoice['invoice_number'],
    'original_invoice' => $invoice
];

// Store cart, customer, and discount in session for payment page
$_SESSION['pos_cart'] = $cart;
if ($customer) {
    $_SESSION['pos_customer'] = [
        'id' => $customer['id'],
        'name' => trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
        'email' => $customer['email'] ?? '',
        'phone' => $customer['phone'] ?? ''
    ];
} else {
    $_SESSION['pos_customer'] = null;
}
$_SESSION['pos_discount'] = $discount;
$_SESSION['pos_delivery_cost'] = 0;

// Show error if products are not available in invoice's branch
if (!empty($stockIssues)) {
    $errorMessages = [];
    $warningMessages = [];
    
    foreach ($stockIssues as $issue) {
        if (strpos($issue, 'is not available in the invoice branch') !== false) {
            $errorMessages[] = $issue;
        } else {
            $warningMessages[] = $issue;
        }
    }
    
    if (!empty($errorMessages)) {
        $_SESSION['error_message'] = 'Cannot convert to sale: ' . implode(', ', $errorMessages);
        redirectTo('modules/invoicing/index.php');
    }
    
    if (!empty($warningMessages)) {
        $_SESSION['warning_message'] = 'Some products have insufficient stock: ' . implode(', ', $warningMessages) . '. You can still proceed, but stock will go negative.';
    }
}

// Ensure session branch_id matches invoice branch for correct fiscalization
// This ensures the correct device ID is used based on the invoice's branch
$_SESSION['branch_id'] = $invoiceBranchId;

// Redirect to payment page
redirectTo('modules/pos/payment.php?from_invoice=1');

