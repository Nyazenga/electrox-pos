<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$auth->requirePermission('drawer.report');

$shiftId = intval($_GET['shift_id'] ?? 0);

if (!$shiftId) {
    echo json_encode(['success' => false, 'message' => 'Shift ID is required']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Verify shift exists (don't restrict to current user - allow managers/admins to view)
    $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [
        ':id' => $shiftId
    ]);
    
    if (!$shift) {
        throw new Exception('Shift not found');
    }
    
    // Get drawer transactions
    $transactions = $db->getRows("SELECT dt.*, u.first_name, u.last_name FROM drawer_transactions dt 
                                  LEFT JOIN users u ON dt.user_id = u.id 
                                  WHERE dt.shift_id = :shift_id ORDER BY dt.created_at ASC", [
        ':shift_id' => $shiftId
    ]);
    
    if ($transactions === false) {
        $transactions = [];
    }
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions
    ]);
    
} catch (Exception $e) {
    logError("Get drawer report error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load drawer report: ' . $e->getMessage()]);
}

