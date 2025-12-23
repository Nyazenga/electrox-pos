<?php
require_once dirname(__FILE__) . '/../config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/mailer.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

initSession();

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

header('Content-Type: application/json');

// Suppress errors for clean JSON output (but log them)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
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
    
    // Get payments - ALWAYS fetch directly from sale_payments first to ensure we get them
    $payments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $receiptId]);
    if ($payments === false) {
        $payments = [];
    }
    
    // Enrich payments with currency information from main database
    if (!empty($payments)) {
        $mainDb = Database::getMainInstance();
        foreach ($payments as &$payment) {
            if (!empty($payment['currency_id'])) {
                $currency = $mainDb->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $payment['currency_id']]);
                if ($currency) {
                    $payment['currency_code'] = $currency['code'];
                    $payment['currency_symbol'] = $currency['symbol'];
                    $payment['currency_symbol_position'] = $currency['symbol_position'];
                }
            }
        }
        unset($payment);
    }
    
    // Get base currency
    $baseCurrency = getBaseCurrency($db);
    
    // Get fiscal receipt and taxes for tax breakdown and QR code
    $primaryDb = Database::getPrimaryInstance();
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
         FROM fiscal_receipts fr
         LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
         LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
         WHERE fr.sale_id = :sale_id
         LIMIT 1",
        [':sale_id' => $receiptId]
    );
    
    $fiscalReceiptTaxes = [];
    if ($fiscalReceipt) {
        $fiscalReceiptTaxes = $primaryDb->getRows(
            "SELECT tax_code, tax_percent, tax_id, tax_amount, sales_amount_with_tax 
             FROM fiscal_receipt_taxes 
             WHERE fiscal_receipt_id = :fiscal_receipt_id 
             ORDER BY tax_percent ASC, tax_code ASC",
            [':fiscal_receipt_id' => $fiscalReceipt['id']]
        );
    }
    
    // Get company details
    $companyName = getSetting('company_name', SYSTEM_NAME);
    $companyAddress = getSetting('company_address', '');
    $companyPhone = getSetting('company_phone', '');
    $companyEmail = getSetting('company_email', '');
    
    // Get receipt logo - prepare for embedding
    $receiptLogoPath = getSetting('pos_receipt_logo', '');
    $receiptLogoUrl = '';
    $logoCid = 'receipt_logo_' . time();
    $logoFullPath = '';
    $logoMimeType = 'image/png';
    
    if ($receiptLogoPath) {
        $logoPath = ltrim($receiptLogoPath, '/');
        $fullPath = APP_PATH . '/' . $logoPath;
        
        // Check if file exists
        if (file_exists($fullPath)) {
            try {
                $logoFullPath = $fullPath;
                $imageInfo = @getimagesize($fullPath);
                
                if ($imageInfo && isset($imageInfo['mime'])) {
                    $logoMimeType = $imageInfo['mime'];
                } else {
                    // Fallback: detect from file extension
                    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                    switch ($ext) {
                        case 'jpg':
                        case 'jpeg':
                            $logoMimeType = 'image/jpeg';
                            break;
                        case 'gif':
                            $logoMimeType = 'image/gif';
                            break;
                        case 'png':
                            $logoMimeType = 'image/png';
                            break;
                        case 'webp':
                            $logoMimeType = 'image/webp';
                            break;
                    }
                }
                
                // Use CID reference for embedded image
                $receiptLogoUrl = 'cid:' . $logoCid;
            } catch (Exception $e) {
                logError("Failed to load logo for email: " . $e->getMessage());
            }
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
        // Use CID reference for embedded image - centered
        $html .= '<div style="text-align: center; margin-bottom: 15px;">
                    <img src="' . htmlspecialchars($receiptLogoUrl) . '" alt="Logo" style="max-width: 200px; max-height: 80px; display: block; margin: 0 auto;">
                  </div>';
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
    
    // Tax Breakdown (if fiscalized)
    if (!empty($fiscalReceiptTaxes)) {
        // Group taxes by taxPercent and taxCode for display
        $taxGroups = [];
        foreach ($fiscalReceiptTaxes as $tax) {
            $taxPercent = isset($tax['tax_percent']) && $tax['tax_percent'] !== null ? floatval($tax['tax_percent']) : null;
            $taxCode = $tax['tax_code'] ?? '';
            $taxAmount = floatval($tax['tax_amount'] ?? 0);
            
            // Create key for grouping: exempt by code, others by percent
            if ($taxCode === 'E') {
                $key = 'exempt';
            } elseif ($taxPercent === 0.0 || $taxPercent === 0) {
                $key = '0';
            } else {
                $key = strval($taxPercent);
            }
            
            if (!isset($taxGroups[$key])) {
                $taxGroups[$key] = [
                    'taxPercent' => $taxPercent,
                    'taxCode' => $taxCode,
                    'totalAmount' => 0
                ];
            }
            $taxGroups[$key]['totalAmount'] += $taxAmount;
        }
        
        // Sort: exempt first, then 0%, then by percent ascending
        uksort($taxGroups, function($a, $b) {
            if ($a === 'exempt') return -1;
            if ($b === 'exempt') return 1;
            if ($a === '0') return -1;
            if ($b === '0') return 1;
            return floatval($a) <=> floatval($b);
        });
        
        // Display tax breakdowns
        foreach ($taxGroups as $group) {
            // Format label based on tax type
            if ($group['taxCode'] === 'E') {
                $label = 'Total: Exempt from VAT';
            } elseif ($group['taxPercent'] === 0.0 || $group['taxPercent'] === 0 || $group['taxPercent'] === null) {
                $label = 'Total 0% VAT';
            } else {
                $label = 'Total ' . number_format($group['taxPercent'], 1) . '% VAT';
            }
            
            $html .= '<tr>
                        <td colspan="3" style="text-align: right;"><strong>' . htmlspecialchars($label) . ':</strong></td>
                        <td style="text-align: right;"><strong>' . formatCurrency($group['totalAmount']) . '</strong></td>
                    </tr>';
        }
    }
    
    $html .= '<tr class="total-row">
                    <td colspan="3" style="text-align: right;"><strong>TOTAL:</strong></td>
                    <td style="text-align: right;"><strong>' . formatCurrency($sale['total_amount']) . '</strong></td>
                </tr>
                <tr>
                    <td colspan="4" style="padding-top: 8px;">
                        <strong>Payment:</strong><br>';
    
    $totalPaid = 0;
    if (!empty($payments)) {
        foreach ($payments as $payment) {
            // Use base_amount if available, otherwise use amount
            $paymentAmount = isset($payment['base_amount']) ? floatval($payment['base_amount']) : floatval($payment['amount']);
            $totalPaid += $paymentAmount;
            
            // Display original amount and currency if different from base
            $displayAmount = isset($payment['original_amount']) ? floatval($payment['original_amount']) : floatval($payment['amount']);
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
    } else {
        $html .= '<div style="margin-left: 10px; color: #999; font-style: italic;">No payment information available</div>';
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
        </table>';
    
    // Fiscal Information Section (QR Code and Verification) - if fiscalized
    if ($fiscalReceipt) {
        $html .= '<div style="margin-top: 20px; padding-top: 15px; border-top: 2px solid #1e3a8a; text-align: center;">';
        
        // QR Code
        $qrCodeDisplayed = false;
        if (isset($fiscalReceipt['receipt_qr_code']) && !empty($fiscalReceipt['receipt_qr_code']) && strlen($fiscalReceipt['receipt_qr_code']) > 0) {
            try {
                $qrImageData = base64_decode($fiscalReceipt['receipt_qr_code']);
                if ($qrImageData !== false && strlen($qrImageData) > 0) {
                    $qrImageBase64 = base64_encode($qrImageData);
                    $html .= '<div style="margin: 15px 0;">
                                <img src="data:image/png;base64,' . $qrImageBase64 . '" alt="QR Code" style="max-width: 150px; height: auto; border: 1px solid #ddd;">
                              </div>';
                    $qrCodeDisplayed = true;
                }
            } catch (Exception $e) {
                error_log("QR code image error in email: " . $e->getMessage());
            }
        }
        
        // Fallback: Generate QR code URL for display
        if (!$qrCodeDisplayed && isset($fiscalReceipt['receipt_qr_data']) && !empty($fiscalReceipt['receipt_qr_data'])) {
            $qrUrl = $fiscalReceipt['qr_url'] ?? 'https://fdmstest.zimra.co.zw';
            $deviceId = $fiscalReceipt['device_id'] ?? '';
            $receiptDate = $fiscalReceipt['receipt_date'] ?? '';
            $receiptGlobalNo = $fiscalReceipt['receipt_global_no'] ?? '';
            
            if ($deviceId && $receiptDate && $receiptGlobalNo) {
                $deviceIdFormatted = str_pad($deviceId, 10, '0', STR_PAD_LEFT);
                $date = new DateTime($receiptDate);
                $receiptDateFormatted = $date->format('dmY');
                $receiptGlobalNoFormatted = str_pad($receiptGlobalNo, 10, '0', STR_PAD_LEFT);
                $qrDataFormatted = substr($fiscalReceipt['receipt_qr_data'], 0, 16);
                $qrCodeString = rtrim($qrUrl, '/') . '/' . $deviceIdFormatted . $receiptDateFormatted . $receiptGlobalNoFormatted . $qrDataFormatted;
                
                // Use QR code API service to generate the image
                $qrCodeApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($qrCodeString);
                $html .= '<div style="margin: 15px 0;">
                            <img src="' . htmlspecialchars($qrCodeApiUrl) . '" alt="QR Code" style="max-width: 150px; height: auto; border: 1px solid #ddd;">
                          </div>';
            }
        }
        
        // Verification Code
        if (isset($fiscalReceipt['receipt_verification_code'])) {
            $html .= '<div style="margin: 10px 0; font-weight: bold; font-size: 11px;">
                        Verification code: ' . htmlspecialchars($fiscalReceipt['receipt_verification_code']) . '
                      </div>';
        }
        
        // Verification URL
        $html .= '<div style="margin: 8px 0; font-size: 10px; color: #666;">
                    You can verify this receipt manually at<br>
                    <a href="https://receipt.zimra.org/" target="_blank" style="color: #1e3a8a; text-decoration: underline;">https://receipt.zimra.org/</a>
                  </div>';
        
        $html .= '</div>';
    }
    
    $html .= '<div class="receipt-footer">
            <div style="margin-bottom: 5px;">Thank you for your business!</div>
            <div>' . SYSTEM_NAME . ' - ' . (SYSTEM_VERSION ?? '1.0.0') . '</div>
        </div>
    </div>
</body>
</html>';
    
    // Send email with embedded logo
    $mailer = new Mailer();
    $subject = 'Receipt #' . $sale['receipt_number'] . ' - ' . $companyName;
    
    // Embed logo as attachment if available - MUST be done before send() is called
    if ($receiptLogoUrl && $logoFullPath && file_exists($logoFullPath)) {
        try {
            $phpmailer = $mailer->getMailer();
            // Use addEmbeddedImage with file path - most reliable method
            // Parameters: path, CID, name, encoding, MIME type
            // The CID must match exactly what's in the HTML (cid:receipt_logo_...)
            $result = $phpmailer->addEmbeddedImage($logoFullPath, $logoCid, 'logo', PHPMailer::ENCODING_BASE64, $logoMimeType);
            if (!$result) {
                logError("Failed to add embedded image - PHPMailer returned false. CID: $logoCid, Path: $logoFullPath");
            } else {
                logError("Successfully added embedded image. CID: $logoCid, Path: $logoFullPath, MIME: $logoMimeType");
            }
        } catch (Exception $e) {
            logError("Failed to embed logo in email: " . $e->getMessage() . " | CID: $logoCid | Path: $logoFullPath");
            // Continue without logo if embedding fails
        }
    } else {
        if (!$receiptLogoUrl) {
            logError("No receipt logo URL set - logoPath: " . ($receiptLogoPath ?? 'NULL'));
        }
        if (!$logoFullPath || !file_exists($logoFullPath)) {
            logError("Logo file not found - FullPath: " . ($logoFullPath ?? 'NULL') . " | Exists: " . (file_exists($logoFullPath ?? '') ? 'YES' : 'NO'));
        }
    }
    
    try {
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
            // Get error info from PHPMailer
            $errorInfo = 'Unknown error';
            try {
                // Try to get error from mailer instance
                if (method_exists($mailer, 'getMailer')) {
                    $mailerInstance = $mailer->getMailer();
                    if ($mailerInstance && isset($mailerInstance->ErrorInfo)) {
                        $errorInfo = $mailerInstance->ErrorInfo;
                    }
                }
            } catch (Exception $e) {
                $errorInfo = 'Failed to get error details: ' . $e->getMessage();
            }
            throw new Exception('Failed to send email: ' . $errorInfo);
        }
    } catch (Exception $mailError) {
        logError("Email send error: " . $mailError->getMessage());
        throw $mailError;
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

