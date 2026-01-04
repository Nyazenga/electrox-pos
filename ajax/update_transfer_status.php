<?php
// Start output buffering BEFORE any includes
ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Helper function to write to log file
function writeTransferLog($message, $logFile) {
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents($logFile, $message, FILE_APPEND);
}

// Set custom error log file path (use absolute path from __DIR__)
$logFile = dirname(dirname(__FILE__)) . '/logs/transfer_status_error.log';
writeTransferLog("=== TRANSFER STATUS UPDATE REQUEST STARTED ===" . PHP_EOL, $logFile);
writeTransferLog("Timestamp: " . date('Y-m-d H:i:s') . PHP_EOL, $logFile);
writeTransferLog("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . PHP_EOL, $logFile);

// Suppress any output from includes
try {
    writeTransferLog("Loading config.php..." . PHP_EOL, $logFile);
    @require_once dirname(dirname(__FILE__)) . '/config.php';
    writeTransferLog("config.php loaded successfully" . PHP_EOL, $logFile);
    
    writeTransferLog("Loading db.php..." . PHP_EOL, $logFile);
    @require_once APP_PATH . '/includes/db.php';
    writeTransferLog("db.php loaded successfully" . PHP_EOL, $logFile);
    
    writeTransferLog("Loading auth.php..." . PHP_EOL, $logFile);
    @require_once APP_PATH . '/includes/auth.php';
    writeTransferLog("auth.php loaded successfully" . PHP_EOL, $logFile);
    
    writeTransferLog("Loading functions.php..." . PHP_EOL, $logFile);
    @require_once APP_PATH . '/includes/functions.php';
    writeTransferLog("functions.php loaded successfully" . PHP_EOL, $logFile);
} catch (Exception $e) {
    writeTransferLog("ERROR loading includes: " . $e->getMessage() . PHP_EOL, $logFile);
    writeTransferLog("Stack trace: " . $e->getTraceAsString() . PHP_EOL, $logFile);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Initialization error: ' . $e->getMessage()]);
    exit;
} catch (Error $e) {
    writeTransferLog("FATAL ERROR loading includes: " . $e->getMessage() . PHP_EOL, $logFile);
    writeTransferLog("Stack trace: " . $e->getTraceAsString() . PHP_EOL, $logFile);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
    exit;
}

// Clear any output that might have been generated
ob_clean();
header('Content-Type: application/json');
writeTransferLog("Headers set, starting authentication..." . PHP_EOL, $logFile);

try {
    initSession();
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
        $auth->requirePermission('transfers.change_status');
    } catch (Exception $permError) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Permission denied: ' . $permError->getMessage()]);
        exit;
    }
} catch (Exception $e) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Initialization error: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

writeTransferLog("Reading input data..." . PHP_EOL, $logFile);
$input = json_decode(file_get_contents('php://input'), true);
writeTransferLog("Input data: " . json_encode($input) . PHP_EOL, $logFile);
$transferId = intval($input['transfer_id'] ?? 0);
$status = trim($input['status'] ?? '');
writeTransferLog("Transfer ID: $transferId, Status: $status" . PHP_EOL, $logFile);

if (!$transferId || !in_array($status, ['Pending', 'Approved', 'InTransit', 'Received', 'Rejected', 'Completed'])) {
    writeTransferLog("Invalid input - Transfer ID: $transferId, Status: $status" . PHP_EOL, $logFile);
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

try {
    writeTransferLog("Getting Database instance..." . PHP_EOL, $logFile);
    $db = Database::getInstance();
    writeTransferLog("Database instance obtained" . PHP_EOL, $logFile);
    $userId = $_SESSION['user_id'] ?? null;
    writeTransferLog("User ID: " . ($userId ?? 'NULL') . PHP_EOL, $logFile);
    
    // Get current transfer status
    $transfer = $db->getRow("SELECT status, from_branch_id, to_branch_id, transfer_number FROM stock_transfers WHERE id = :id", [':id' => $transferId]);
    if (!$transfer) {
        throw new Exception('Transfer not found');
    }
    
    $oldStatus = $transfer['status'] ?? 'Pending';
    $fromBranchId = $transfer['from_branch_id'] ?? null;
    $toBranchId = $transfer['to_branch_id'] ?? null;
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        $updateData = ['status' => $status];
        if ($status == 'Approved') {
            $updateData['approved_by'] = $userId;
        } elseif ($status == 'Received' || $status == 'Completed') {
            $updateData['received_by'] = $userId;
            if ($status == 'Completed') {
                $updateData['status'] = 'Completed';
            }
        }
        
        $result = $db->update('stock_transfers', $updateData, ['id' => $transferId]);
        
        if ($result === false) {
            throw new Exception('Failed to update transfer status: ' . $db->getLastError());
        }
        
        // If status changed to "Approved" or "Completed", move stock
        if (($status === 'Approved' || $status === 'Completed') && $oldStatus !== 'Approved' && $oldStatus !== 'Completed') {
            // Get transfer items
            $transferItems = $db->getRows("SELECT * FROM transfer_items WHERE transfer_id = :id", [':id' => $transferId]);
            
            if ($transferItems !== false && !empty($transferItems)) {
                foreach ($transferItems as $item) {
                    $productId = intval($item['product_id'] ?? 0);
                    $quantity = intval($item['quantity'] ?? 0);
                    
                    if ($productId > 0 && $quantity > 0 && $fromBranchId && $toBranchId) {
                        // Check available stock in source branch
                        $fromProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", 
                            [':id' => $productId, ':branch_id' => $fromBranchId]);
                        
                        if (!$fromProduct || ($fromProduct['quantity_in_stock'] ?? 0) < $quantity) {
                            throw new Exception("Insufficient stock for product ID: {$productId} in source branch");
                        }
                        
                        // Deduct from source branch
                        $fromPreviousQuantity = (int)($fromProduct['quantity_in_stock'] ?? 0);
                        $fromNewQuantity = $fromPreviousQuantity - $quantity;
                        
                        $db->update('products', [
                            'quantity_in_stock' => $fromNewQuantity
                        ], ['id' => $productId, 'branch_id' => $fromBranchId]);
                        
                        $db->insert('stock_movements', [
                            'product_id' => $productId,
                            'branch_id' => $fromBranchId,
                            'movement_type' => 'Transfer',
                            'quantity' => -$quantity,
                            'previous_quantity' => $fromPreviousQuantity,
                            'new_quantity' => $fromNewQuantity,
                            'reference_id' => $transferId,
                            'reference_type' => 'Transfer',
                            'user_id' => $userId,
                            'notes' => 'Transfer Out: ' . $transfer['transfer_number'],
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        // Add to destination branch
                        $toProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", 
                            [':id' => $productId, ':branch_id' => $toBranchId]);
                        
                        if ($toProduct) {
                            // Product exists, update quantity
                            $toPreviousQuantity = (int)($toProduct['quantity_in_stock'] ?? 0);
                            $toNewQuantity = $toPreviousQuantity + $quantity;
                            
                            $db->update('products', [
                                'quantity_in_stock' => $toNewQuantity
                            ], ['id' => $productId, 'branch_id' => $toBranchId]);
                            
                            $db->insert('stock_movements', [
                                'product_id' => $productId,
                                'branch_id' => $toBranchId,
                                'movement_type' => 'Transfer',
                                'quantity' => $quantity,
                                'previous_quantity' => $toPreviousQuantity,
                                'new_quantity' => $toNewQuantity,
                                'reference_id' => $transferId,
                                'reference_type' => 'Transfer',
                                'user_id' => $userId,
                                'notes' => 'Transfer In: ' . $transfer['transfer_number'],
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        } else {
                            // Product doesn't exist in destination branch - create new product entry
                            $sourceProduct = $db->getRow("SELECT * FROM products WHERE id = :id AND branch_id = :branch_id", 
                                [':id' => $productId, ':branch_id' => $fromBranchId]);
                            
                            if ($sourceProduct) {
                                // Build new product data - only copy relevant fields
                                $newProductData = [
                                    'product_code' => '',
                                    'category_id' => $sourceProduct['category_id'] ?? null,
                                    'product_name' => $sourceProduct['product_name'] ?? null,
                                    'brand' => $sourceProduct['brand'] ?? null,
                                    'model' => $sourceProduct['model'] ?? null,
                                    'color' => $sourceProduct['color'] ?? null,
                                    'storage' => $sourceProduct['storage'] ?? null,
                                    'sim_configuration' => $sourceProduct['sim_configuration'] ?? null,
                                    'serial_number' => $sourceProduct['serial_number'] ?? null,
                                    'imei' => $sourceProduct['imei'] ?? null,
                                    'battery_health' => $sourceProduct['battery_health'] ?? null,
                                    'expiry_date' => $sourceProduct['expiry_date'] ?? null,
                                    'weight' => $sourceProduct['weight'] ?? null,
                                    'unit_of_measure' => $sourceProduct['unit_of_measure'] ?? null,
                                    'manufacturer' => $sourceProduct['manufacturer'] ?? null,
                                    'barcode' => $sourceProduct['barcode'] ?? null,
                                    'description' => $sourceProduct['description'] ?? null,
                                    'specifications' => $sourceProduct['specifications'] ?? null,
                                    'cost_price' => $sourceProduct['cost_price'] ?? 0,
                                    'selling_price' => $sourceProduct['selling_price'] ?? 0,
                                    'reorder_level' => $sourceProduct['reorder_level'] ?? 0,
                                    'branch_id' => $toBranchId,
                                    'tax_id' => $sourceProduct['tax_id'] ?? null,
                                    'quantity_in_stock' => $quantity,
                                    'status' => $sourceProduct['status'] ?? 'Active',
                                    'created_by' => $userId,
                                    'source' => 'transfer',
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'images' => $sourceProduct['images'] ?? null
                                ];
                                
                                // Generate new product code (must be unique)
                                // Verify function exists before calling
                                if (!function_exists('generateProductCode')) {
                                    error_log("ERROR: generateProductCode() function not found");
                                    throw new Exception("System error: Product code generation function not available");
                                }
                                
                                $maxAttempts = 50;
                                $attempt = 0;
                                do {
                                    $newProductCode = generateProductCode();
                                    $existing = $db->getRow("SELECT id FROM products WHERE product_code = :code", [':code' => $newProductCode]);
                                    $attempt++;
                                    if ($attempt >= $maxAttempts) {
                                        throw new Exception("Failed to generate unique product code after {$maxAttempts} attempts");
                                    }
                                } while ($existing);
                                $newProductData['product_code'] = $newProductCode;
                                
                                // Insert new product in destination branch
                                $newProductId = $db->insert('products', $newProductData);
                                
                                if ($newProductId) {
                                    // Create stock movement record
                                    $db->insert('stock_movements', [
                                        'product_id' => $newProductId,
                                        'branch_id' => $toBranchId,
                                        'movement_type' => 'Transfer',
                                        'quantity' => $quantity,
                                        'previous_quantity' => 0,
                                        'new_quantity' => $quantity,
                                        'reference_id' => $transferId,
                                        'reference_type' => 'Transfer',
                                        'user_id' => $userId,
                                        'notes' => 'Transfer In: ' . $transfer['transfer_number'],
                                        'created_at' => date('Y-m-d H:i:s')
                                    ]);
                                } else {
                                    throw new Exception("Failed to create product in destination branch: " . $db->getLastError());
                                }
                            } else {
                                throw new Exception("Source product not found for product ID: {$productId} in branch {$fromBranchId}");
                            }
                        }
                    }
                }
            }
        }
        
        // If status changed from "Approved"/"Completed" to something else, reverse stock movement
        if (($oldStatus === 'Approved' || $oldStatus === 'Completed') && $status !== 'Approved' && $status !== 'Completed') {
            // Get transfer items
            $transferItems = $db->getRows("SELECT * FROM transfer_items WHERE transfer_id = :id", [':id' => $transferId]);
            
            if ($transferItems !== false && !empty($transferItems)) {
                foreach ($transferItems as $item) {
                    $productId = intval($item['product_id'] ?? 0);
                    $quantity = intval($item['quantity'] ?? 0);
                    
                    if ($productId > 0 && $quantity > 0 && $fromBranchId && $toBranchId) {
                        // Restore to source branch
                        $fromProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", 
                            [':id' => $productId, ':branch_id' => $fromBranchId]);
                        
                        if ($fromProduct !== false) {
                            $fromPreviousQuantity = (int)($fromProduct['quantity_in_stock'] ?? 0);
                            $fromNewQuantity = $fromPreviousQuantity + $quantity;
                            
                            $db->update('products', [
                                'quantity_in_stock' => $fromNewQuantity
                            ], ['id' => $productId, 'branch_id' => $fromBranchId]);
                            
                            $db->insert('stock_movements', [
                                'product_id' => $productId,
                                'branch_id' => $fromBranchId,
                                'movement_type' => 'Transfer',
                                'quantity' => $quantity,
                                'previous_quantity' => $fromPreviousQuantity,
                                'new_quantity' => $fromNewQuantity,
                                'reference_id' => $transferId,
                                'reference_type' => 'Transfer',
                                'user_id' => $userId,
                                'notes' => 'Transfer Reversal Out: ' . $transfer['transfer_number'],
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                        
                        // Deduct from destination branch
                        $toProduct = $db->getRow("SELECT quantity_in_stock FROM products WHERE id = :id AND branch_id = :branch_id", 
                            [':id' => $productId, ':branch_id' => $toBranchId]);
                        
                        if ($toProduct !== false) {
                            $toPreviousQuantity = (int)($toProduct['quantity_in_stock'] ?? 0);
                            $toNewQuantity = max(0, $toPreviousQuantity - $quantity);
                            
                            $db->update('products', [
                                'quantity_in_stock' => $toNewQuantity
                            ], ['id' => $productId, 'branch_id' => $toBranchId]);
                            
                            $db->insert('stock_movements', [
                                'product_id' => $productId,
                                'branch_id' => $toBranchId,
                                'movement_type' => 'Transfer',
                                'quantity' => -$quantity,
                                'previous_quantity' => $toPreviousQuantity,
                                'new_quantity' => $toNewQuantity,
                                'reference_id' => $transferId,
                                'reference_type' => 'Transfer',
                                'user_id' => $userId,
                                'notes' => 'Transfer Reversal In: ' . $transfer['transfer_number'],
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            }
        }
        
        $db->commitTransaction();
        
        // Log activity (wrap in try-catch to prevent errors from breaking response)
        try {
            logActivity($userId, 'transfer_status_updated', ['transfer_id' => $transferId, 'status' => $status]);
        } catch (Exception $logError) {
            error_log("Activity log error: " . $logError->getMessage());
            // Don't fail the response if logging fails
        }
        
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Transfer status updated successfully']);
        exit;
        
    } catch (Exception $e) {
        try {
            if ($db->getPdo()->inTransaction()) {
                $db->rollbackTransaction();
            }
        } catch (Exception $rollbackError) {
            // Ignore rollback errors
        }
        throw $e;
    }
    
} catch (Exception $e) {
    if (isset($db)) {
        try {
            if ($db->getPdo()->inTransaction()) {
                $db->rollbackTransaction();
            }
        } catch (Exception $rollbackError) {
            // Ignore rollback errors
        }
    }
    $errorMessage = $e->getMessage();
    writeTransferLog("Update transfer status error: " . $errorMessage . PHP_EOL, $logFile);
    writeTransferLog("Stack trace: " . $e->getTraceAsString() . PHP_EOL, $logFile);
    writeTransferLog("=== TRANSFER STATUS UPDATE REQUEST FAILED ===" . PHP_EOL, $logFile);
    // Also log to default error log
    error_log("Update transfer status error: " . $errorMessage);
    error_log("Stack trace: " . $e->getTraceAsString());
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $errorMessage]);
    exit;
} catch (Error $e) {
    // Catch fatal errors (PHP 7+)
    if (isset($db)) {
        try {
            if ($db->getPdo()->inTransaction()) {
                $db->rollbackTransaction();
            }
        } catch (Exception $rollbackError) {
            // Ignore rollback errors
        }
    }
    $errorMessage = $e->getMessage();
    writeTransferLog("Update transfer status fatal error: " . $errorMessage . PHP_EOL, $logFile);
    writeTransferLog("Stack trace: " . $e->getTraceAsString() . PHP_EOL, $logFile);
    writeTransferLog("=== TRANSFER STATUS UPDATE REQUEST FAILED ===" . PHP_EOL, $logFile);
    // Also log to default error log
    error_log("Update transfer status fatal error: " . $errorMessage);
    error_log("Stack trace: " . $e->getTraceAsString());
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'System error: ' . $errorMessage]);
    exit;
}

