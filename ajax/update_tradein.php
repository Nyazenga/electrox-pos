<?php
// Start output buffering and suppress errors BEFORE any includes
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

// Suppress any output from includes
@require_once dirname(dirname(__FILE__)) . '/config.php';
@require_once APP_PATH . '/includes/db.php';
@require_once APP_PATH . '/includes/auth.php';
@require_once APP_PATH . '/includes/functions.php';

// Clear any output that might have been generated
ob_clean();
header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    $auth->requirePermission('tradeins.edit');
} catch (Exception $permError) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Permission denied: ' . $permError->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$tradeInId = intval($input['trade_in_id'] ?? 0);

if (!$tradeInId) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid trade-in ID']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Check if trade-in exists and is not processed
    $tradeInCheck = $db->getRow("SELECT * FROM trade_ins WHERE id = :id", [':id' => $tradeInId]);
    if (!$tradeInCheck) {
        throw new Exception("Trade-in with ID $tradeInId not found");
    }
    
    if ($tradeInCheck['status'] === 'Processed') {
        throw new Exception('Cannot edit a processed trade-in');
    }
    
    $branchId = $_SESSION['branch_id'] ?? null;
    
    // Store additional product details in valuation_notes as JSON
    $productDetails = [
        'device_category' => $input['device_category'] ?? null,
        'device_color' => $input['device_color'] ?? null,
        'device_storage' => $input['device_storage'] ?? null,
        'serial_number' => $input['serial_number'] ?? null,
        'imei' => $input['imei'] ?? null,
        'sim_configuration' => $input['sim_configuration'] ?? null,
        'cost_price' => $input['cost_price'] ?? 0,
        'selling_price' => $input['selling_price'] ?? 0,
        'description' => $input['description'] ?? null,
        'specifications' => $input['specifications'] ?? null,
        'battery_health' => $input['battery_health'] ?? null,
        'cosmetic_issues' => $input['cosmetic_issues'] ?? null,
        'functional_issues' => $input['functional_issues'] ?? null,
        'accessories_included' => $input['accessories_included'] ?? null,
        'date_of_first_use' => $input['date_of_first_use'] ?? null
    ];
    
    $existingNotes = $input['valuation_notes'] ?? '';
    $valuationNotes = $existingNotes . (empty($existingNotes) ? '' : "\n\n") . 'PRODUCT_DETAILS_JSON:' . json_encode($productDetails);
    
    $tradeInData = [
        'customer_id' => $input['customer_id'] ?: null,
        'device_category' => $input['device_category'] ?? null,
        'device_brand' => $input['device_brand'],
        'device_model' => $input['device_model'],
        'device_color' => $input['device_color'] ?? null,
        'device_storage' => $input['device_storage'] ?? null,
        'device_condition' => $input['device_condition'],
        'battery_health' => $input['battery_health'] ?? null,
        'cosmetic_issues' => $input['cosmetic_issues'] ?? null,
        'functional_issues' => $input['functional_issues'] ?? null,
        'accessories_included' => $input['accessories_included'] ?? null,
        'date_of_first_use' => $input['date_of_first_use'] ?? null,
        'manual_valuation' => $input['manual_valuation'] ?? 0,
        'final_valuation' => $input['final_valuation'] ?? 0,
        'valuation_notes' => $valuationNotes,
        'new_product_id' => $input['new_product_id'] ?? null
    ];
    
    // Ensure new_product_id is null if empty
    if (isset($tradeInData['new_product_id']) && ($tradeInData['new_product_id'] === '' || $tradeInData['new_product_id'] === '0')) {
        $tradeInData['new_product_id'] = null;
    }
    
    // Check if new_product_id column exists, if not, remove it from data
    $columns = $db->getRows("SHOW COLUMNS FROM trade_ins WHERE Field = 'new_product_id'");
    if (empty($columns) && isset($tradeInData['new_product_id'])) {
        unset($tradeInData['new_product_id']);
    }
    
    $updated = $db->update('trade_ins', $tradeInData, ['id' => $tradeInId]);
    
    if (!$updated) {
        $error = $db->getLastError();
        error_log("Failed to update trade-in. Error: " . $error);
        throw new Exception('Failed to update trade-in record. ' . ($error ?: 'Database error'));
    }
    
    logActivity($_SESSION['user_id'], 'tradein_updated', ['trade_in_id' => $tradeInId]);
    
    // Ensure clean output
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    
    echo json_encode([
        'success' => true, 
        'message' => 'Trade-in updated successfully',
        'trade_in_id' => $tradeInId
    ]);
    exit;
    
} catch (Exception $e) {
    logError("Update trade-in error: " . $e->getMessage());
    
    // Ensure clean output
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    
    echo json_encode(['success' => false, 'message' => 'Failed to update trade-in: ' . $e->getMessage()]);
    exit;
}

