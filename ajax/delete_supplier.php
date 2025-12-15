<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();
$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('suppliers.delete');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$supplierId = intval($input['supplier_id'] ?? 0);

if (!$supplierId) {
    echo json_encode(['success' => false, 'message' => 'Invalid supplier ID']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Check if supplier is used in any GRN
    $grnCount = $db->getRow("SELECT COUNT(*) as count FROM goods_received_notes WHERE supplier_id = :id", [':id' => $supplierId]);
    if (($grnCount['count'] ?? 0) > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete supplier: Supplier is used in GRN records']);
        exit;
    }
    
    $result = $db->delete('suppliers', ['id' => $supplierId]);
    
    if ($result) {
        logActivity($_SESSION['user_id'], 'supplier_deleted', ['supplier_id' => $supplierId]);
        echo json_encode(['success' => true, 'message' => 'Supplier deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete supplier: ' . $db->getLastError()]);
    }
} catch (Exception $e) {
    logError("Delete supplier error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while deleting supplier']);
}


