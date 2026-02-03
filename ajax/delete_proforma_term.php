<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$auth->requirePermission('invoicing.customize');

$input = json_decode(file_get_contents('php://input'), true);
$termId = intval($input['id'] ?? 0);

if (!$termId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid term ID']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Check if term is used in any invoices
    $count = $db->getCount("SELECT COUNT(*) FROM invoices WHERE terms_id = :id", [':id' => $termId]);
    
    if ($count && $count > 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot delete: This terms & conditions is being used by ' . $count . ' invoice(s).'
        ]);
        exit;
    }
    
    $result = $db->delete('proforma_terms', ['id' => $termId]);
    
    if ($result !== false && $result > 0) {
        echo json_encode(['success' => true, 'message' => 'Terms & conditions deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete terms & conditions']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
