<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('sales.laybyes.complete');

$laybyeId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$laybyeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Laybye ID is required']);
    exit;
}

try {
    $db = Database::getInstance();
    $primaryDb = Database::getPrimaryInstance();
    $branchId = $_SESSION['branch_id'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;
    
    // Get laybye
    $laybye = $primaryDb->getRow("SELECT * FROM laybyes WHERE id = :id", [':id' => $laybyeId]);
    if (!$laybye) {
        throw new Exception('Laybye not found');
    }
    
    if ($laybye['status'] === 'completed') {
        throw new Exception('Laybye is already completed');
    }
    
    if ($laybye['status'] === 'cancelled') {
        throw new Exception('Cannot complete a cancelled laybye');
    }
    
    $amountRemaining = floatval($laybye['amount_remaining']);
    if ($amountRemaining > 0.01) { // Allow small rounding differences
        throw new Exception('Laybye must be fully paid before completion');
    }
    
    // Use laybye's branch_id, not session branch_id
    $laybyeBranchId = $laybye['branch_id'] ?? null;
    if (!$laybyeBranchId) {
        throw new Exception('Laybye does not have a branch assigned. Cannot complete.');
    }
    
    // Update session branch_id to match laybye's branch for fiscalization
    $_SESSION['branch_id'] = $laybyeBranchId;
    
    // Get laybye items
    $laybyeItems = $primaryDb->getRows("SELECT * FROM laybye_items WHERE laybye_id = :id", [':id' => $laybyeId]);
    if (empty($laybyeItems)) {
        throw new Exception('No items found for this laybye');
    }
    
    // Get customer
    $customer = null;
    if ($laybye['customer_id']) {
        $customer = $primaryDb->getRow("SELECT * FROM customers WHERE id = :id", [':id' => $laybye['customer_id']]);
    }
    
    // Build cart from laybye items - similar to invoice conversion
    $cart = [];
    $stockIssues = [];
    
    foreach ($laybyeItems as $laybyeItem) {
        $productId = intval($laybyeItem['product_id']);
        if (!$productId) {
            continue; // Skip items without product
        }
        
        // Get product from the laybye's branch
        $product = $db->getRow("SELECT * FROM products WHERE id = :id AND branch_id = :branch_id", [
            ':id' => $productId,
            ':branch_id' => $laybyeBranchId
        ]);
        
        if (!$product) {
            $stockIssues[] = "Product '{$laybyeItem['product_name']}' (ID: $productId) is not available in the laybye branch";
            continue;
        }
        
        $quantity = intval($laybyeItem['quantity']);
        $unitPrice = floatval($laybyeItem['unit_price']);
        $requiresSpecificList = productRequiresSpecificList($product, $db);
        
        // Check stock availability (for non-specific list products)
        if (!$requiresSpecificList) {
            $availableStock = intval($product['quantity_in_stock'] ?? 0);
            if ($availableStock < $quantity) {
                $stockIssues[] = "Product '{$laybyeItem['product_name']}' has insufficient stock (Available: $availableStock, Required: $quantity)";
            }
        }
        
        // Add to cart
        $cartItem = [
            'id' => $productId,
            'name' => $laybyeItem['product_name'],
            'price' => $unitPrice,
            'quantity' => $quantity,
            'product_id' => $productId,
            'requires_specific_list' => $requiresSpecificList
        ];
        
        $cart[] = $cartItem;
    }
    
    // Check if cart is empty
    if (empty($cart)) {
        throw new Exception('Cannot complete laybye: No valid products found to convert. All items were excluded.');
    }
    
    // Store laybye conversion data in session
    $_SESSION['laybye_to_sale'] = [
        'laybye_id' => $laybyeId,
        'laybye_number' => $laybye['laybye_number'],
        'original_laybye' => $laybye
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
    $_SESSION['pos_discount'] = ['type' => 'value', 'amount' => 0];
    $_SESSION['pos_delivery_cost'] = 0;
    
    // Show warning if products have stock issues (but allow to proceed)
    if (!empty($stockIssues)) {
        $_SESSION['warning_message'] = 'Some products have issues: ' . implode(', ', $stockIssues) . '. You can still proceed, but stock may go negative.';
    }
    
    // Ensure session branch_id matches laybye branch for correct fiscalization
    $_SESSION['branch_id'] = $laybyeBranchId;
    
    // Return success with redirect URL
    echo json_encode([
        'success' => true,
        'message' => 'Laybye ready for completion. Redirecting to POS...',
        'redirect_url' => BASE_URL . 'modules/pos/payment.php?from_laybye=1&laybye_id=' . $laybyeId
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
