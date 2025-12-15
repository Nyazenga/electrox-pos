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
    $email = trim($_POST['email'] ?? '');
    
    if (!$receiptId) {
        throw new Exception('Invalid receipt ID');
    }
    
    if (empty($email)) {
        throw new Exception('Email address is required');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address');
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
    $companyEmail = getSetting('company_email', '');
    
    // Get receipt logo - prepare for embedding
    $receiptLogoPath = getSetting('pos_receipt_logo', '');
    $receiptLogoUrl = '';
    $logoEmbedded = false;
    $logoCid = 'receipt_logo_' . time();
    
    if ($receiptLogoPath) {
        $logoPath = ltrim($receiptLogoPath, '/');
        $fullPath = APP_PATH . '/' . $logoPath;
        
        // Check if file exists
        if (file_exists($fullPath)) {
            $receiptLogoUrl = 'cid:' . $logoCid; // Use CID for embedded image
            $logoEmbedded = true;
        }
    }
    
    // Build HTML receipt
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .receipt-container { max-width: 600px; margin: 0 auto; padding: 20px; background: #fff; }
        .receipt-header { text-align: center; border-bottom: 2px solid #1e3a8a; padding-bottom: 15px; margin-bottom: 15px; }
        .receipt-header h2 { margin: 0 0 8px 0; color: #1e3a8a; font-size: 20px; }
        .company-info { font-size: 12px; line-height: 1.4; }
        .receipt-info { margin: 12px 0; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        table th, table td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        table th { background: #f3f4f6; font-weight: bold; }
        table td:first-child { text-align: left; }
        table td:nth-child(2) { text-align: center; }
        table td:nth-child(3), table td:nth-child(4) { text-align: right; }
        .total-row { font-weight: bold; font-size: 14px; border-top: 2px solid #1e3a8a; }
        .receipt-footer { text-align: center; margin-top: 15px; padding-top: 12px; border-top: 2px solid #1e3a8a; font-size: 12px; }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">';
    
    if ($receiptLogoUrl) {
        // Use CID reference for embedded image
        $html .= '<img src="' . htmlspecialchars($receiptLogoUrl) . '" alt="Logo" style="max-width: 200px; max-height: 80px; margin-bottom: 15px;">';
    }
    
    $html .= '<h2>' . htmlspecialchars($companyName) . '</h2>
            <div class="company-info">';
    
    if ($companyAddress) {
        $html .= htmlspecialchars($companyAddress) . '<br>';
    }
    if ($companyPhone) {
        $html .= 'Phone: ' . htmlspecialchars($companyPhone) . '<br>';
    }
    if ($companyEmail) {
        $html .= htmlspecialchars($companyEmail);
    }
    
    $html .= '</div>
        </div>
        
        <div class="receipt-info">
            <div><strong>Receipt #:</strong> ' . htmlspecialchars($sale['receipt_number']) . '</div>
            <div><strong>Date:</strong> ' . formatDateTime($sale['sale_date']) . '</div>
            <div><strong>Cashier:</strong> ' . htmlspecialchars(($sale['cashier_first'] ?? '') . ' ' . ($sale['cashier_last'] ?? '')) . '</div>';
    
    if ($sale['first_name']) {
        $html .= '<div><strong>Customer:</strong> ' . htmlspecialchars($sale['first_name'] . ' ' . $sale['last_name']) . '</div>';
    }
    
    $html .= '</div>
        
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="text-align: center;">Qty</th>
                    <th style="text-align: right;">Price</th>
                    <th style="text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($items as $item) {
        $html .= '<tr>
                    <td>' . htmlspecialchars($item['product_name']) . '</td>
                    <td style="text-align: center;">' . $item['quantity'] . '</td>
                    <td style="text-align: right;">' . formatCurrency($item['unit_price']) . '</td>
                    <td style="text-align: right;">' . formatCurrency($item['total_price']) . '</td>
                </tr>';
    }
    
    $html .= '</tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align: right;"><strong>Subtotal:</strong></td>
                    <td style="text-align: right;"><strong>' . formatCurrency($sale['subtotal']) . '</strong></td>
                </tr>';
    
    if ($sale['discount_amount'] > 0) {
        $html .= '<tr>
                    <td colspan="3" style="text-align: right;"><strong>Discount:</strong></td>
                    <td style="text-align: right;"><strong>-' . formatCurrency($sale['discount_amount']) . '</strong></td>
                </tr>';
    }
    
    $html .= '<tr class="total-row">
                    <td colspan="3" style="text-align: right;"><strong>TOTAL:</strong></td>
                    <td style="text-align: right;"><strong>' . formatCurrency($sale['total_amount']) . '</strong></td>
                </tr>
                <tr>
                    <td colspan="4" style="padding-top: 8px;">
                        <strong>Payment:</strong><br>';
    
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
        
        $html .= '<div style="margin-left: 10px;">' . htmlspecialchars(ucfirst($payment['payment_method'])) . ': ' . $formattedAmount;
        if ($currencyCode && $currencyCode !== ($baseCurrency ? $baseCurrency['code'] : 'USD')) {
            $html .= ' <span style="font-size: 0.9em; color: #666;">(' . htmlspecialchars($currencyCode) . ')</span>';
        }
        $html .= '</div>';
    }
    
    $html .= '</td>
                </tr>';
    
    $change = $totalPaid - $sale['total_amount'];
    if ($change > 0) {
        $html .= '<tr>
                    <td colspan="3" style="text-align: right; padding-top: 8px;"><strong>Change:</strong></td>
                    <td style="text-align: right; padding-top: 8px;"><strong>' . formatCurrency($change) . '</strong></td>
                </tr>';
    }
    
    $html .= '</tfoot>
        </table>
        
        <div class="receipt-footer">
            <div style="margin-bottom: 5px;">Thank you for your business!</div>
            <div>' . SYSTEM_NAME . ' - ' . (SYSTEM_VERSION ?? '1.0.0') . '</div>
        </div>
    </div>
</body>
</html>';
    
    // Send email with embedded logo
    $mailer = new Mailer();
    $subject = 'Receipt #' . $sale['receipt_number'] . ' - ' . $companyName;
    
    // Embed logo if available
    if ($logoEmbedded && $receiptLogoPath) {
        $logoPath = ltrim($receiptLogoPath, '/');
        $fullPath = APP_PATH . '/' . $logoPath;
        
        if (file_exists($fullPath)) {
            try {
                $imageData = file_get_contents($fullPath);
                $imageInfo = getimagesize($fullPath);
                $mimeType = $imageInfo ? $imageInfo['mime'] : 'image/png';
                
                // Base64 encode the image data for PHPMailer
                $imageDataBase64 = base64_encode($imageData);
                
                // Use PHPMailer's addStringEmbeddedImage method via getMailer()
                // PHPMailer expects base64-encoded data by default
                $phpmailer = $mailer->getMailer();
                $phpmailer->addStringEmbeddedImage($imageDataBase64, $logoCid, 'logo', 'base64', $mimeType);
            } catch (Exception $e) {
                logError("Failed to embed logo in email: " . $e->getMessage());
            }
        }
    }
    
    $sent = $mailer->send($email, $subject, $html, true);
    
    if ($sent) {
        // Log activity
        try {
            logActivity($_SESSION['user_id'], 'receipt_sent_email', [
                'receipt_id' => $receiptId,
                'receipt_number' => $sale['receipt_number'],
                'email' => $email
            ]);
        } catch (Exception $e) {
            // Ignore logging errors
        }
        
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Receipt sent successfully to ' . htmlspecialchars($email)
        ]);
    } else {
        throw new Exception('Failed to send email. Please check your email configuration.');
    }
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    logError("Send receipt email error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

exit;

