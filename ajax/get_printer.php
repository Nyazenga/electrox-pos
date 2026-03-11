<?php
/**
 * Get a single printer by ID
 */

require_once dirname(__DIR__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();

$db = Database::getInstance();
$printerId = intval($_GET['printer_id'] ?? 0);

if (!$printerId) {
    echo json_encode([
        'success' => false,
        'message' => 'Printer ID is required'
    ]);
    exit;
}

// Verify printer belongs to user's branch
$branchId = $auth->getUser()['branch_id'] ?? 0;

try {
    $printer = $db->getRow(
        "SELECT * FROM printers WHERE id = :id AND branch_id = :branch_id",
        [':id' => $printerId, ':branch_id' => $branchId]
    );
    
    if (!$printer) {
        echo json_encode([
            'success' => false,
            'message' => 'Printer not found or you do not have permission to access it'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'printer' => $printer
    ]);
} catch (Exception $e) {
    error_log("Error fetching printer: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching printer: ' . $e->getMessage()
    ]);
}
