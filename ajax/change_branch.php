<?php
require_once dirname(__FILE__) . '/../config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

header('Content-Type: application/json');

// Suppress errors for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

try {
    $branchId = intval($_POST['branch_id'] ?? 0);
    
    if (!$branchId) {
        throw new Exception('Invalid branch ID');
    }
    
    $db = Database::getInstance();
    
    // Verify branch exists and is active
    $branch = $db->getRow("SELECT * FROM branches WHERE id = :id AND status = 'Active'", [':id' => $branchId]);
    
    if (!$branch) {
        throw new Exception('Branch not found or inactive');
    }
    
    // Update session
    $_SESSION['branch_id'] = $branchId;
    $_SESSION['branch_name'] = $branch['branch_name'];
    
    // Clean output buffer
    ob_end_clean();
    
    echo json_encode([
        'success' => true,
        'message' => 'Branch changed successfully',
        'branch_id' => $branchId,
        'branch_name' => $branch['branch_name']
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

exit;

