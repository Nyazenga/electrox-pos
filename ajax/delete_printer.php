<?php
/**
 * Delete a printer configuration
 */

require_once dirname(__DIR__) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
$auth->requireLogin();

$db = Database::getInstance();

$input = json_decode(file_get_contents('php://input'), true);
$printerId = intval($input['printer_id'] ?? 0);

if (!$printerId) {
    echo json_encode([
        'success' => false,
        'message' => 'Printer ID is required'
    ]);
    exit;
}

// Verify printer belongs to user's branch
$branchId = $auth->getUser()['branch_id'] ?? 0;
$printer = $db->getRow(
    "SELECT * FROM printers WHERE id = :id AND branch_id = :branch_id",
    [':id' => $printerId, ':branch_id' => $branchId]
);

if (!$printer) {
    echo json_encode([
        'success' => false,
        'message' => 'Printer not found or you do not have permission to delete it'
    ]);
    exit;
}

try {
    $result = $db->executeQuery(
        "DELETE FROM printers WHERE id = :id",
        [':id' => $printerId]
    );
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Printer deleted successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete printer'
        ]);
    }
} catch (Exception $e) {
    error_log("Error deleting printer: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting printer: ' . $e->getMessage()
    ]);
}
