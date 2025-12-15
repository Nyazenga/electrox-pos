<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();
$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.edit');

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$currencyId = intval($input['currency_id'] ?? 0);

if (!$currencyId) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid currency ID']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Check if currency exists and is not base
    $currency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $currencyId]);
    if (!$currency) {
        throw new Exception('Currency not found');
    }
    
    if ($currency['is_base']) {
        throw new Exception('Cannot delete base currency');
    }
    
    // Check if currency is used in any transactions
    $usedInSales = $db->getRow("SELECT COUNT(*) as count FROM sale_payments WHERE currency_id = :id", [':id' => $currencyId]);
    if ($usedInSales && $usedInSales['count'] > 0) {
        throw new Exception('Cannot delete currency that has been used in transactions');
    }
    
    // Delete currency
    $result = $db->delete('currencies', ['id' => $currencyId]);
    
    if ($result !== false) {
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Currency deleted successfully']);
    } else {
        throw new Exception('Failed to delete currency: ' . $db->getLastError());
    }
    
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

