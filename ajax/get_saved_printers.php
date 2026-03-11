<?php
/**
 * Get saved printers from database for current branch
 */

require_once dirname(__DIR__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();

$db = Database::getInstance();
$branchId = $auth->getUser()['branch_id'] ?? 0;

if (!$branchId) {
    echo json_encode([
        'success' => false,
        'message' => 'Branch ID not found',
        'printers' => []
    ]);
    exit;
}

try {
    $printers = $db->getRows(
        "SELECT * FROM printers WHERE branch_id = :branch_id ORDER BY created_at DESC",
        [':branch_id' => $branchId]
    );
    
    echo json_encode([
        'success' => true,
        'printers' => $printers ?: []
    ]);
} catch (Exception $e) {
    error_log("Error fetching printers: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching printers: ' . $e->getMessage(),
        'printers' => []
    ]);
}
