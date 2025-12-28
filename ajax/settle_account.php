<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

initSession();

// Ensure clean output for JSON
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    $auth->requirePermission('sales.settle_account');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['sale_id']) || !isset($input['payment_amount'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $db = Database::getInstance();
    $primaryDb = Database::getPrimaryInstance();
    $userId = $_SESSION['user_id'] ?? null;
    $branchId = $_SESSION['branch_id'] ?? null;
    
    $saleId = intval($input['sale_id']);
    $paymentAmount = floatval($input['payment_amount']);
    $paymentMethod = sanitizeInput($input['payment_method'] ?? 'cash');
    $currencyId = isset($input['currency_id']) ? intval($input['currency_id']) : null;
    $comment = sanitizeInput($input['comment'] ?? '');
    
    if ($paymentAmount <= 0) {
        throw new Exception('Payment amount must be greater than 0');
    }
    
    // Get sale details
    $sale = $db->getRow("SELECT * FROM sales WHERE id = :id AND is_credit_sale = 1", [':id' => $saleId]);
    if (!$sale) {
        throw new Exception('Credit sale not found');
    }
    
    $currentBalance = floatval($sale['account_balance'] ?? 0);
    
    // Get currency info FIRST before validation
    if (!$currencyId) {
        $baseCurrency = getBaseCurrency($db);
        $currencyId = $baseCurrency ? $baseCurrency['id'] : null;
    }
    
    $currency = null;
    if ($currencyId) {
        $currencies = getActiveCurrencies($db);
        foreach ($currencies as $curr) {
            if ($curr['id'] == $currencyId) {
                $currency = $curr;
                break;
            }
        }
    }
    
    if (!$currency) {
        $baseCurrency = getBaseCurrency($db);
        $currency = $baseCurrency;
        $currencyId = $baseCurrency ? $baseCurrency['id'] : null;
    }
    
    $exchangeRate = $currency && !$currency['is_base'] ? floatval($currency['exchange_rate']) : 1.0;
    $originalAmount = $paymentAmount;
    $baseAmount = $currency && !$currency['is_base'] ? ($paymentAmount / $exchangeRate) : $paymentAmount;
    
    // Validate payment amount AFTER converting to base currency
    if ($baseAmount > $currentBalance) {
        throw new Exception('Payment amount cannot exceed current balance');
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Create account payment record
    $paymentData = [
        'sale_id' => $saleId,
        'customer_id' => $sale['customer_id'],
        'branch_id' => $sale['branch_id'] ?? $branchId,
        'payment_method' => $paymentMethod,
        'currency_id' => $currencyId,
        'amount' => $baseAmount,
        'payment_date' => date('Y-m-d H:i:s'),
        'notes' => $comment,
        'created_by' => $userId,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    // Insert into tenant database (not primary) since it references sales table
    $paymentId = $db->insert('account_payments', $paymentData);
    if (!$paymentId) {
        throw new Exception('Failed to create payment record: ' . $db->getLastError());
    }
    
    // Calculate new balance
    $newBalance = $currentBalance - $baseAmount;
    $paymentStatus = $newBalance <= 0 ? 'paid' : 'pending';
    $accountSettled = $newBalance <= 0 ? 1 : 0;
    
    // Update sale with new balance
    $updateData = [
        'account_balance' => max(0, $newBalance),
        'payment_status' => $paymentStatus,
        'account_settled' => $accountSettled
    ];
    
    if (!$db->update('sales', $updateData, ['id' => $saleId])) {
        throw new Exception('Failed to update account balance: ' . $db->getLastError());
    }
    
    // Commit transaction
    $db->commitTransaction();
    
    // Log activity
    try {
        logActivity($userId, 'account_settled', [
            'sale_id' => $saleId,
            'payment_amount' => $baseAmount,
            'remaining_balance' => $newBalance
        ]);
    } catch (Exception $e) {
        // Ignore logging errors
        error_log("Activity log error: " . $e->getMessage());
    }
    
    // Ensure clean JSON output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Account balance settled successfully',
        'new_balance' => $newBalance,
        'payment_id' => $paymentId
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        try {
            $db->rollbackTransaction();
        } catch (Exception $rollbackError) {
            error_log("Rollback error: " . $rollbackError->getMessage());
        }
    }
    
    $errorMessage = $e->getMessage();
    error_log("Settle Account Error: " . $errorMessage);
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Ensure clean JSON output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode(['success' => false, 'message' => $errorMessage]);
}
