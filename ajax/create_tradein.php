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

$auth->requirePermission('tradeins.create');

$input = json_decode(file_get_contents('php://input'), true);

try {
    $db = Database::getInstance();
    $branchId = $_SESSION['branch_id'] ?? null;
    
    // Generate unique trade-in number with retry logic
    $datePart = date('Ymd');
    $maxRetries = 10;
    $tradeInNumber = null;
    
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        // Get the last trade-in number for today
        $lastTradeIn = $db->getRow("SELECT trade_in_number FROM trade_ins WHERE trade_in_number LIKE :pattern ORDER BY id DESC LIMIT 1", [
            ':pattern' => 'TI-' . $datePart . '-%'
        ]);
        
        if ($lastTradeIn) {
            // Extract sequence number from last trade-in (format: TI-YYYYMMDD-XXX)
            $lastNumber = $lastTradeIn['trade_in_number'];
            $parts = explode('-', $lastNumber);
            $lastSeq = isset($parts[2]) ? intval($parts[2]) : 0;
            $seq = $lastSeq + 1 + $attempt; // Add attempt offset for retries
        } else {
            $seq = 1 + $attempt;
        }
        
        // Format with leading zeros (3 digits)
        $tradeInNumber = 'TI-' . $datePart . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
        
        // Check if this number already exists
        $exists = $db->getRow("SELECT id FROM trade_ins WHERE trade_in_number = :number", [':number' => $tradeInNumber]);
        if (!$exists) {
            break; // Found a unique number
        }
        
        // If all retries fail, use microtime + random as fallback
        if ($attempt === $maxRetries - 1) {
            $microtime = substr(str_replace('.', '', microtime(true)), -6);
            $random = rand(100, 999);
            $tradeInNumber = 'TI-' . $datePart . '-' . $microtime . $random;
        } else {
            // Small random delay to avoid race conditions
            usleep(rand(10000, 50000)); // 10-50ms
        }
    }
    
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
        'trade_in_number' => $tradeInNumber,
        'customer_id' => $input['customer_id'] ?: null,
        'branch_id' => $branchId,
        'assessed_by' => $_SESSION['user_id'],
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
        'new_product_id' => $input['new_product_id'] ?? null,
        'status' => 'Accepted', // Auto-accept from POS
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    // Ensure new_product_id is null if empty (don't remove it, just set to null)
    if (isset($tradeInData['new_product_id']) && ($tradeInData['new_product_id'] === '' || $tradeInData['new_product_id'] === '0')) {
        $tradeInData['new_product_id'] = null;
    }
    
    // Check if new_product_id column exists, if not, remove it from data
    $columns = $db->getRows("SHOW COLUMNS FROM trade_ins WHERE Field = 'new_product_id'");
    if (empty($columns) && isset($tradeInData['new_product_id'])) {
        unset($tradeInData['new_product_id']);
    }
    
    $tradeInId = $db->insert('trade_ins', $tradeInData);
    
    if (!$tradeInId || $tradeInId === false) {
        $error = $db->getLastError();
        error_log("Failed to insert trade-in. Error: " . $error);
        error_log("Trade-in data: " . json_encode($tradeInData));
        error_log("Last insert ID: " . $db->getPdo()->lastInsertId());
        throw new Exception('Failed to create trade-in record. ' . ($error ?: 'Database error. Please check if the trade_ins table has all required columns.'));
    }
    
    logActivity($_SESSION['user_id'], 'tradein_created', ['trade_in_id' => $tradeInId]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Trade-in created successfully',
        'trade_in_id' => (int)$tradeInId
    ]);
    
} catch (Exception $e) {
    logError("Create trade-in error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to create trade-in: ' . $e->getMessage()]);
}

