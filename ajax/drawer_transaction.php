<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$auth->requirePermission('pos.access');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['shift_id']) || !isset($input['transaction_type']) || !isset($input['amount'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $db = Database::getInstance();
    
    $transactionData = [
        'shift_id' => $input['shift_id'],
        'transaction_type' => $input['transaction_type'],
        'amount' => $input['amount'],
        'reason' => $input['reason'] ?? 'Other',
        'notes' => $input['notes'] ?? '',
        'user_id' => $_SESSION['user_id'],
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $db->insert('drawer_transactions', $transactionData);
    
    // Update shift expected cash
    $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [':id' => $input['shift_id']]);
    
    if ($input['transaction_type'] === 'pay_in') {
        $newExpected = $shift['expected_cash'] + $input['amount'];
    } else {
        $newExpected = $shift['expected_cash'] - $input['amount'];
    }
    
    $db->update('shifts', [
        'expected_cash' => $newExpected
    ], ['id' => $input['shift_id']]);
    
    logActivity($_SESSION['user_id'], 'drawer_transaction', $transactionData);
    
    echo json_encode(['success' => true, 'message' => 'Transaction recorded successfully']);
    
} catch (Exception $e) {
    logError("Drawer transaction error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to record transaction: ' . $e->getMessage()]);
}

