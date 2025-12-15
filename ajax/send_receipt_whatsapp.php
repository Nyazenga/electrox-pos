<?php
require_once dirname(__FILE__) . '/../config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/mailer.php';
require_once APP_PATH . '/includes/currency_functions.php';

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
    $receiptId = intval($_POST['receipt_id'] ?? 0);
    $phone = trim($_POST['phone'] ?? '');
    
    if (!$receiptId) {
        throw new Exception('Invalid receipt ID');
    }
    
    if (empty($phone)) {
        throw new Exception('WhatsApp number is required');
    }
    
    // Clean phone number (remove spaces, ensure it starts with +)
    $phone = preg_replace('/\s+/', '', $phone);
    if (!preg_match('/^\+/', $phone)) {
        $phone = '+' . $phone;
    }
    
    // Validate phone format
    if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
        throw new Exception('Invalid WhatsApp number format. Please include country code (e.g., +263771234567)');
    }
    
    $db = Database::getInstance();
    
    // Get sale details
    $sale = $db->getRow("SELECT s.*, c.first_name, c.last_name, c.email, c.phone, b.branch_name, b.address as branch_address, b.phone as branch_phone, u.first_name as cashier_first, u.last_name as cashier_last 
                          FROM sales s 
                          LEFT JOIN customers c ON s.customer_id = c.id 
                          LEFT JOIN branches b ON s.branch_id = b.id 
                          LEFT JOIN users u ON s.user_id = u.id 
                          WHERE s.id = :id", [':id' => $receiptId]);
    
    if (!$sale) {
        throw new Exception('Receipt not found');
    }
    
    // Get items
    $items = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :id", [':id' => $receiptId]);
    if ($items === false) {
        $items = [];
    }
    
    // Get payments with currency information
    $payments = $db->getRows("SELECT sp.*, c.code as currency_code, c.symbol as currency_symbol, c.symbol_position as currency_symbol_position
                              FROM sale_payments sp
                              LEFT JOIN currencies c ON sp.currency_id = c.id
                              WHERE sp.sale_id = :id", [':id' => $receiptId]);
    if ($payments === false) {
        $payments = [];
    }
    
    // Get base currency
    $baseCurrency = getBaseCurrency($db);
    
    // Get company details
    $companyName = getSetting('company_name', SYSTEM_NAME);
    $companyAddress = getSetting('company_address', '');
    $companyPhone = getSetting('company_phone', '');
    
    // Build text receipt for WhatsApp
    $text = "📧 *RECEIPT FROM " . strtoupper($companyName) . "*\n\n";
    $text .= "Receipt #: " . $sale['receipt_number'] . "\n";
    $text .= "Date: " . formatDateTime($sale['sale_date']) . "\n";
    $text .= "Cashier: " . ($sale['cashier_first'] ?? '') . ' ' . ($sale['cashier_last'] ?? '') . "\n";
    
    if ($sale['first_name']) {
        $text .= "Customer: " . $sale['first_name'] . ' ' . $sale['last_name'] . "\n";
    }
    
    $text .= "\n*ITEMS:*\n";
    foreach ($items as $item) {
        $text .= "• " . $item['product_name'] . " x" . $item['quantity'] . " = " . formatCurrency($item['total_price']) . "\n";
    }
    
    $text .= "\n*SUMMARY:*\n";
    $text .= "Subtotal: " . formatCurrency($sale['subtotal']) . "\n";
    
    if ($sale['discount_amount'] > 0) {
        $text .= "Discount: -" . formatCurrency($sale['discount_amount']) . "\n";
    }
    
    $text .= "*TOTAL: " . formatCurrency($sale['total_amount']) . "*\n\n";
    
    $text .= "*PAYMENT:*\n";
    $totalPaid = 0;
    foreach ($payments as $payment) {
        $totalPaid += $payment['amount'];
        $displayAmount = $payment['original_amount'] ?? $payment['amount'];
        $currencyCode = $payment['currency_code'] ?? ($baseCurrency ? $baseCurrency['code'] : 'USD');
        $currencySymbol = $payment['currency_symbol'] ?? ($baseCurrency ? $baseCurrency['symbol'] : '$');
        $symbolPosition = $payment['currency_symbol_position'] ?? ($baseCurrency ? $baseCurrency['symbol_position'] : 'before');
        
        if ($symbolPosition === 'before') {
            $formattedAmount = $currencySymbol . number_format($displayAmount, 2);
        } else {
            $formattedAmount = number_format($displayAmount, 2) . ' ' . $currencySymbol;
        }
        
        $text .= ucfirst($payment['payment_method']) . ": " . $formattedAmount;
        if ($currencyCode && $currencyCode !== ($baseCurrency ? $baseCurrency['code'] : 'USD')) {
            $text .= " (" . $currencyCode . ")";
        }
        $text .= "\n";
    }
    
    $change = $totalPaid - $sale['total_amount'];
    if ($change > 0) {
        $text .= "Change: " . formatCurrency($change) . "\n";
    }
    
    $text .= "\nThank you for your business!\n";
    $text .= $companyName;
    $text .= "\n\n*From:* +263782794721"; // Business WhatsApp number
    
    // Create WhatsApp link with pre-filled message
    // Send FROM business number (+263782794721) TO customer
    $phoneNumber = preg_replace('/[^0-9]/', '', $phone);
    
    // Create link that opens WhatsApp Web/App with message ready to send to customer
    $whatsappLink = 'https://wa.me/' . $phoneNumber . '?text=' . urlencode($text);
    
    // Log activity
    try {
        logActivity($_SESSION['user_id'], 'receipt_sent_whatsapp', [
            'receipt_id' => $receiptId,
            'receipt_number' => $sale['receipt_number'],
            'phone' => $phone,
            'whatsapp_link' => $whatsappLink
        ]);
    } catch (Exception $e) {
        // Ignore logging errors
    }
    
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'WhatsApp link generated. Opening WhatsApp...',
        'whatsapp_link' => $whatsappLink
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    logError("Send receipt WhatsApp error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

exit;

