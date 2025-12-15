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

if (!$input || !isset($input['shift_id']) || !isset($input['actual_cash'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $db = Database::getInstance();
    $db->beginTransaction();
    
    $shift = $db->getRow("SELECT * FROM shifts WHERE id = :id AND status = 'open'", [':id' => $input['shift_id']]);
    
    if (!$shift) {
        throw new Exception('Shift not found or already closed');
    }
    
    $actualCash = $input['actual_cash'];
    $difference = $actualCash - $shift['expected_cash'];
    
    $db->update('shifts', [
        'closed_at' => date('Y-m-d H:i:s'),
        'closed_by' => $_SESSION['user_id'],
        'actual_cash' => $actualCash,
        'difference' => $difference,
        'status' => 'closed',
        'notes' => $input['notes'] ?? null
    ], ['id' => $input['shift_id']]);
    
    $db->commitTransaction();
    
    logActivity($_SESSION['user_id'], 'shift_end', ['shift_id' => $input['shift_id']]);
    
    $reportUrl = null;
    if ($input['print_report'] ?? false) {
        $reportUrl = BASE_URL . 'modules/pos/shift_report.php?id=' . $input['shift_id'];
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Shift ended successfully',
        'report_url' => $reportUrl
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollbackTransaction();
    }
    logError("Shift end error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to end shift: ' . $e->getMessage()]);
}

