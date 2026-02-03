<?php
// Suppress errors and set JSON header early
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

header('Content-Type: application/json; charset=utf-8');

try {
    require_once dirname(dirname(__FILE__)) . '/config.php';
    require_once APP_PATH . '/includes/db.php';
    require_once APP_PATH . '/includes/auth.php';
    require_once APP_PATH . '/includes/functions.php';
    require_once APP_PATH . '/includes/currency_functions.php';
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load required files: ' . $e->getMessage()
    ]);
    exit;
}

// Clear any output buffer
ob_clean();

try {
    $auth = Auth::getInstance();
    $auth->requireLogin();
    $auth->requirePermission('sales.laybyes.create');
} catch (Exception $e) {
    ob_clean();
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication error: ' . $e->getMessage()
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON input: ' . json_last_error_msg() . '. Raw input length: ' . strlen($rawInput)
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    $primaryDb = Database::getPrimaryInstance();
    // Get branch_id from input (form) or session
    $branchId = !empty($input['branch_id']) ? intval($input['branch_id']) : ($_SESSION['branch_id'] ?? null);
    $userId = $_SESSION['user_id'] ?? null;
    
    if (!$branchId || !$userId) {
        throw new Exception('Branch ID and User ID are required');
    }
    
    // Validate input
    if (empty($input['customer_id'])) {
        throw new Exception('Customer is required');
    }
    
    if (empty($input['items']) || !is_array($input['items']) || count($input['items']) === 0) {
        throw new Exception('At least one item is required');
    }
    
    // Validate customer exists
    $customer = $primaryDb->getRow("SELECT * FROM customers WHERE id = :id", [':id' => intval($input['customer_id'])]);
    if (!$customer) {
        throw new Exception('Customer not found');
    }
    
    // Generate laybye number (format: LB-BRANCH-DATE-SEQ)
    $datePart = date('ymd');
    $branchPrefix = $branchId ?? 0;
    $maxRetries = 20;
    $laybyeNumber = null;
    
    for ($retry = 0; $retry < $maxRetries; $retry++) {
        $pattern = 'LB-' . $branchPrefix . '-' . $datePart . '-%';
        $maxLaybye = $primaryDb->getRow("SELECT laybye_number FROM laybyes WHERE laybye_number LIKE :pattern ORDER BY laybye_number DESC LIMIT 1", [
            ':pattern' => $pattern
        ]);
        
        $seq = 1;
        if ($maxLaybye && isset($maxLaybye['laybye_number'])) {
            $laybyeNum = $maxLaybye['laybye_number'];
            $prefix = 'LB-' . $branchPrefix . '-' . $datePart . '-';
            
            if (strpos($laybyeNum, $prefix) === 0) {
                $seqPart = substr($laybyeNum, strlen($prefix));
                if (preg_match('/^(\d+)/', $seqPart, $matches)) {
                    $seq = intval($matches[1]) + 1;
                }
            }
        }
        
        $seq += $retry;
        $seqPadded = str_pad($seq, 4, '0', STR_PAD_LEFT);
        $laybyeNumber = 'LB-' . $branchPrefix . '-' . $datePart . '-' . $seqPadded;
        
        $existing = $primaryDb->getRow("SELECT id FROM laybyes WHERE laybye_number = :laybye_number", [
            ':laybye_number' => $laybyeNumber
        ]);
        
        if (!$existing) {
            break;
        }
    }
    
    if (!$laybyeNumber) {
        $randomSuffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 2));
        $seqPadded = str_pad(9999, 4, '0', STR_PAD_LEFT);
        $laybyeNumber = 'LB-' . $branchPrefix . '-' . $datePart . '-' . $seqPadded . '-' . $randomSuffix;
    }
    
    // Calculate totals
    $totalAmount = 0;
    $items = [];
    
    foreach ($input['items'] as $item) {
        $productId = intval($item['product_id']);
        $quantity = intval($item['quantity']);
        $unitPrice = floatval($item['unit_price']);
        
        if ($quantity <= 0 || $unitPrice <= 0) {
            throw new Exception('Invalid quantity or price for product');
        }
        
        // Get product details
        $product = $db->getRow("SELECT * FROM products WHERE id = :id", [':id' => $productId]);
        if (!$product) {
            throw new Exception("Product not found: {$productId}");
        }
        
        $totalPrice = $unitPrice * $quantity;
        $totalAmount += $totalPrice;
        
        // Get product name
        $productName = $product['product_name'] ?? '';
        if (empty($productName)) {
            $brand = $product['brand'] ?? '';
            $model = $product['model'] ?? '';
            $productName = trim($brand . ' ' . $model);
        }
        if (empty($productName)) {
            $productName = 'Product #' . $productId;
        }
        
        $items[] = [
            'product_id' => $productId,
            'product_name' => $productName,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice
        ];
    }
    
    // Get payment schedule
    $paymentScheduleType = $input['payment_schedule_type'] ?? 'monthly';
    $paymentScheduleData = null;
    $nextPaymentDate = null;
    
    if ($paymentScheduleType === 'monthly') {
        // Calculate monthly payment amount
        $months = intval($input['payment_months'] ?? 3);
        if ($months <= 0) {
            $months = 3;
        }
        
        $monthlyAmount = $totalAmount / $months;
        $nextPaymentDate = date('Y-m-d', strtotime('+1 month'));
        
        $paymentScheduleData = json_encode([
            'type' => 'monthly',
            'months' => $months,
            'monthly_amount' => $monthlyAmount,
            'start_date' => date('Y-m-d')
        ]);
        
        // Create payment schedule entries
        $scheduleEntries = [];
        for ($i = 1; $i <= $months; $i++) {
            $scheduleDate = date('Y-m-d', strtotime("+{$i} month"));
            $scheduleEntries[] = [
                'scheduled_date' => $scheduleDate,
                'scheduled_amount' => $i === $months ? ($totalAmount - ($monthlyAmount * ($months - 1))) : $monthlyAmount
            ];
        }
    } else {
        // Custom schedule
        $customSchedule = $input['custom_schedule'] ?? [];
        if (empty($customSchedule)) {
            throw new Exception('Custom payment schedule is required');
        }
        
        $paymentScheduleData = json_encode([
            'type' => 'custom',
            'schedule' => $customSchedule
        ]);
        
        if (!empty($customSchedule[0]['date'])) {
            $nextPaymentDate = $customSchedule[0]['date'];
        }
    }
    
    // Start transaction
    $primaryDb->beginTransaction();
    
    try {
        // Create laybye
        $laybyeData = [
            'laybye_number' => $laybyeNumber,
            'customer_id' => intval($input['customer_id']),
            'branch_id' => $branchId,
            'user_id' => $userId,
            'total_amount' => $totalAmount,
            'amount_paid' => 0,
            'amount_remaining' => $totalAmount,
            'status' => 'pending',
            'payment_schedule_type' => $paymentScheduleType,
            'payment_schedule_data' => $paymentScheduleData,
            'next_payment_date' => $nextPaymentDate,
            'notes' => !empty($input['notes']) ? trim($input['notes']) : null
        ];
        
        $laybyeId = $primaryDb->insert('laybyes', $laybyeData);
        if (!$laybyeId) {
            throw new Exception('Failed to create laybye: ' . $primaryDb->getLastError());
        }
        
        // Create laybye items
        foreach ($items as $item) {
            $itemData = [
                'laybye_id' => $laybyeId,
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price']
            ];
            
            $itemId = $primaryDb->insert('laybye_items', $itemData);
            if (!$itemId) {
                throw new Exception('Failed to create laybye item');
            }
        }
        
        // Create payment schedule entries if monthly
        if ($paymentScheduleType === 'monthly' && !empty($scheduleEntries)) {
            foreach ($scheduleEntries as $entry) {
                $scheduleData = [
                    'laybye_id' => $laybyeId,
                    'scheduled_date' => $entry['scheduled_date'],
                    'scheduled_amount' => $entry['scheduled_amount'],
                    'paid_amount' => 0,
                    'is_paid' => 0
                ];
                
                $scheduleId = $primaryDb->insert('laybye_payment_schedule', $scheduleData);
                if (!$scheduleId) {
                    error_log("Failed to create payment schedule entry: " . $primaryDb->getLastError());
                }
            }
        }
        
        // Create payment schedule entries for custom schedule
        if ($paymentScheduleType === 'custom' && !empty($input['custom_schedule'])) {
            foreach ($input['custom_schedule'] as $entry) {
                if (!empty($entry['date']) && !empty($entry['amount'])) {
                    $scheduleData = [
                        'laybye_id' => $laybyeId,
                        'scheduled_date' => $entry['date'],
                        'scheduled_amount' => floatval($entry['amount']),
                        'paid_amount' => 0,
                        'is_paid' => 0
                    ];
                    
                    $scheduleId = $primaryDb->insert('laybye_payment_schedule', $scheduleData);
                    if (!$scheduleId) {
                        error_log("Failed to create custom payment schedule entry: " . $primaryDb->getLastError());
                    }
                }
            }
        }
        
        $primaryDb->commitTransaction();
        
        ob_clean(); // Clear any output before JSON
        echo json_encode([
            'success' => true,
            'message' => 'Laybye created successfully',
            'laybye_id' => $laybyeId,
            'laybye_number' => $laybyeNumber
        ]);
        exit;
        
    } catch (Exception $e) {
        $primaryDb->rollbackTransaction();
        throw $e;
    }
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("CREATE LAYBYE ERROR: " . $e->getMessage());
    error_log("CREATE LAYBYE STACK: " . $e->getTraceAsString());
    
    // Clear any output before JSON
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_type' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
    exit;
} catch (Error $e) {
    // Catch PHP 7+ fatal errors
    error_log("CREATE LAYBYE FATAL ERROR: " . $e->getMessage());
    error_log("CREATE LAYBYE STACK: " . $e->getTraceAsString());
    
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fatal error: ' . $e->getMessage(),
        'error_type' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
    exit;
}
