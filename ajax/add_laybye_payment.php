<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

header('Content-Type: application/json');

try {
    $auth = Auth::getInstance();
    $auth->requireLogin();
    $auth->requirePermission('sales.laybyes.add_payment');
} catch (Exception $e) {
    ob_clean();
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication error: ' . $e->getMessage()
    ]);
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance();
    $primaryDb = Database::getPrimaryInstance();
    $userId = $_SESSION['user_id'] ?? null;
    
    if (empty($input['laybye_id'])) {
        throw new Exception('Laybye ID is required');
    }
    
    $laybyeId = intval($input['laybye_id']);
    $paymentAmount = floatval($input['payment_amount'] ?? 0);
    $paymentMethod = sanitizeInput($input['payment_method'] ?? 'cash');
    $currencyId = isset($input['currency_id']) ? intval($input['currency_id']) : null;
    $notes = sanitizeInput($input['notes'] ?? '');
    
    if ($paymentAmount <= 0) {
        throw new Exception('Payment amount must be greater than 0');
    }
    
    // Get laybye
    $laybye = $primaryDb->getRow("SELECT * FROM laybyes WHERE id = :id", [':id' => $laybyeId]);
    if (!$laybye) {
        throw new Exception('Laybye not found');
    }
    
    if ($laybye['status'] === 'completed' || $laybye['status'] === 'cancelled') {
        throw new Exception('Cannot add payment to a completed or cancelled laybye');
    }
    
    $amountRemaining = floatval($laybye['amount_remaining']);
    
    // Get currency info
    if (!$currencyId) {
        $baseCurrency = getBaseCurrency($db);
        $currencyId = $baseCurrency ? $baseCurrency['id'] : null;
    }
    
    $currency = null;
    if ($currencyId) {
        $currencies = getActiveCurrencies($db);
        foreach ($currencies as $curr) {
            if ($curr['id'] == $currencyId) {
                $currency = $curr;
                break;
            }
        }
    }
    
    if (!$currency) {
        $baseCurrency = getBaseCurrency($db);
        $currency = $baseCurrency;
        $currencyId = $baseCurrency ? $baseCurrency['id'] : null;
    }
    
    $exchangeRate = $currency && !$currency['is_base'] ? floatval($currency['exchange_rate']) : 1.0;
    $baseAmount = $currency && !$currency['is_base'] ? ($paymentAmount / $exchangeRate) : $paymentAmount;
    
    // Validate payment amount
    if ($baseAmount > $amountRemaining) {
        throw new Exception('Payment amount cannot exceed remaining balance');
    }
    
    // Start transaction
    $primaryDb->beginTransaction();
    
    try {
        // Create payment record
        $paymentData = [
            'laybye_id' => $laybyeId,
            'payment_amount' => $paymentAmount,
            'payment_method' => $paymentMethod,
            'currency_id' => $currencyId,
            'exchange_rate' => $exchangeRate,
            'base_amount' => $baseAmount,
            'user_id' => $userId,
            'notes' => $notes
        ];
        
        $paymentId = $primaryDb->insert('laybye_payments', $paymentData);
        if (!$paymentId) {
            throw new Exception('Failed to create payment record');
        }
        
        // Update laybye
        $newAmountPaid = floatval($laybye['amount_paid']) + $baseAmount;
        $newAmountRemaining = $amountRemaining - $baseAmount;
        $newStatus = $newAmountRemaining <= 0.01 ? 'paid' : ($laybye['status'] === 'pending' ? 'in_progress' : $laybye['status']);
        
        // Update payment schedule if monthly
        if ($laybye['payment_schedule_type'] === 'monthly') {
            $scheduleData = json_decode($laybye['payment_schedule_data'], true);
            if ($scheduleData && $scheduleData['type'] === 'monthly') {
                // Find all unpaid schedule entries
                $scheduleEntries = $primaryDb->getRows(
                    "SELECT * FROM laybye_payment_schedule WHERE laybye_id = :laybye_id AND is_paid = 0 ORDER BY scheduled_date ASC",
                    [':laybye_id' => $laybyeId]
                );
                
                if (!empty($scheduleEntries)) {
                    $remainingToAllocate = $baseAmount;
                    $tolerance = 0.01; // Allow small rounding differences
                    
                    // Calculate total remaining across all unpaid entries
                    $totalRemaining = 0;
                    foreach ($scheduleEntries as $entry) {
                        $entryScheduledAmount = floatval($entry['scheduled_amount']);
                        $entryPaidAmount = floatval($entry['paid_amount']);
                        $totalRemaining += ($entryScheduledAmount - $entryPaidAmount);
                    }
                    
                    // If payment covers all remaining installments, mark all as paid
                    if ($baseAmount >= ($totalRemaining - $tolerance)) {
                        foreach ($scheduleEntries as $entry) {
                            $entryScheduledAmount = floatval($entry['scheduled_amount']);
                            $primaryDb->update('laybye_payment_schedule', [
                                'paid_amount' => $entryScheduledAmount,
                                'is_paid' => 1,
                                'paid_at' => date('Y-m-d H:i:s')
                            ], ['id' => $entry['id']]);
                        }
                    } else {
                        // Allocate payment across entries
                        foreach ($scheduleEntries as $entry) {
                            if ($remainingToAllocate <= 0) {
                                break;
                            }
                            
                            $entryScheduledAmount = floatval($entry['scheduled_amount']);
                            $entryPaidAmount = floatval($entry['paid_amount']);
                            $entryRemaining = $entryScheduledAmount - $entryPaidAmount;
                            
                            if ($entryRemaining > 0) {
                                $toPay = min($remainingToAllocate, $entryRemaining);
                                $newPaidAmount = $entryPaidAmount + $toPay;
                                
                                // Check if fully paid (with tolerance for floating point precision)
                                $isFullyPaid = (abs($newPaidAmount - $entryScheduledAmount) < $tolerance) || 
                                              ($newPaidAmount >= $entryScheduledAmount);
                                
                                $primaryDb->update('laybye_payment_schedule', [
                                    'paid_amount' => $newPaidAmount,
                                    'is_paid' => $isFullyPaid ? 1 : 0,
                                    'paid_at' => $isFullyPaid ? date('Y-m-d H:i:s') : null
                                ], ['id' => $entry['id']]);
                                
                                $remainingToAllocate -= $toPay;
                            }
                        }
                    }
                }
                
                // Update next payment date
                $nextUnpaid = $primaryDb->getRow(
                    "SELECT scheduled_date FROM laybye_payment_schedule WHERE laybye_id = :laybye_id AND is_paid = 0 ORDER BY scheduled_date ASC LIMIT 1",
                    [':laybye_id' => $laybyeId]
                );
                
                $nextPaymentDate = $nextUnpaid ? $nextUnpaid['scheduled_date'] : null;
            }
        }
        
        $updateData = [
            'amount_paid' => $newAmountPaid,
            'amount_remaining' => $newAmountRemaining,
            'status' => $newStatus
        ];
        
        if (isset($nextPaymentDate)) {
            $updateData['next_payment_date'] = $nextPaymentDate;
        }
        
        $primaryDb->update('laybyes', $updateData, ['id' => $laybyeId]);
        
        $primaryDb->commitTransaction();
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Payment added successfully',
            'amount_paid' => $newAmountPaid,
            'amount_remaining' => $newAmountRemaining,
            'status' => $newStatus
        ]);
        
    } catch (Exception $e) {
        $primaryDb->rollbackTransaction();
        throw $e;
    }
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_type' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fatal error: ' . $e->getMessage(),
        'error_type' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}
