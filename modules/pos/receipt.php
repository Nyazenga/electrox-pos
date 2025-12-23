<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    die('Invalid receipt ID');
}

// Check if we should use TCPDF (for PDF export)
$usePDF = isset($_GET['pdf']) && $_GET['pdf'] == '1';

$db = Database::getInstance();
$sale = $db->getRow("SELECT s.*, c.first_name, c.last_name, c.email, c.phone, b.branch_name, b.address as branch_address, b.phone as branch_phone, u.first_name as cashier_first, u.last_name as cashier_last 
                      FROM sales s 
                      LEFT JOIN customers c ON s.customer_id = c.id 
                      LEFT JOIN branches b ON s.branch_id = b.id 
                      LEFT JOIN users u ON s.user_id = u.id 
                      WHERE s.id = :id", [':id' => $id]);

// Initialize fiscal_details if not set
if (!isset($sale['fiscal_details'])) {
    $sale['fiscal_details'] = null;
}
if (!isset($sale['fiscalized'])) {
    $sale['fiscalized'] = 0;
}

// Check if sale has fiscal_details, if not check invoice
if (!$sale['fiscal_details'] && !empty($sale['invoice_id'])) {
    $invoice = $db->getRow("SELECT fiscalized, fiscal_details FROM invoices WHERE id = :id", [':id' => $sale['invoice_id']]);
    if ($invoice && $invoice['fiscalized']) {
        $sale['fiscalized'] = $invoice['fiscalized'];
        $sale['fiscal_details'] = $invoice['fiscal_details'];
    }
}

if (!$sale) {
    die('Receipt not found');
}

$items = $db->getRows("SELECT * FROM sale_items WHERE sale_id = :id", [':id' => $id]);
if ($items === false) {
    $items = [];
}

// Get payments - ALWAYS fetch directly from sale_payments first to ensure we get them
$payments = $db->getRows("SELECT * FROM sale_payments WHERE sale_id = :id", [':id' => $id]);
if ($payments === false) {
    $payments = [];
}

// Enrich payments with currency information from tenant database
if (!empty($payments)) {
    foreach ($payments as &$payment) {
        if (!empty($payment['currency_id'])) {
            $currency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $payment['currency_id']]);
            if ($currency) {
                $payment['currency_code'] = $currency['code'];
                $payment['currency_symbol'] = $currency['symbol'];
                $payment['currency_symbol_position'] = $currency['symbol_position'];
            }
        }
    }
    unset($payment);
}

// Get base currency for display
$baseCurrency = getBaseCurrency($db);

// Determine payment currency from payments (for display conversion)
$paymentCurrency = null;
$paymentCurrencyId = null;
$exchangeRate = 1.0;
if (!empty($payments)) {
    // Get currency from first payment
    $firstPayment = $payments[0];
    if (!empty($firstPayment['currency_id'])) {
        $paymentCurrencyId = $firstPayment['currency_id'];
        $paymentCurrency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $paymentCurrencyId]);
        if ($paymentCurrency && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
            // Get exchange rate from BASE currency to PAYMENT currency (for converting base amounts to payment currency)
            // If base is USD (rate=1.0) and payment is ZWL (rate=2.0), then 1 USD = 2 ZWL, so rate = 2.0
            $exchangeRate = getExchangeRate($baseCurrency['id'], $paymentCurrencyId, $db);
        }
    }
}

$companyName = getSetting('company_name', SYSTEM_NAME);
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');

// Get receipt logo
$receiptLogoPath = getSetting('pos_receipt_logo', '');
$receiptLogoUrl = '';
if ($receiptLogoPath) {
    $receiptLogoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
}

// Check if we should use TCPDF (for PDF export)
if ($usePDF) {
    // Use TCPDF for PDF generation
    require_once APP_PATH . '/vendor/autoload.php';
    
    // Create PDF (Portrait, mm, A4)
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('ELECTROX-POS');
    $pdf->SetAuthor($companyName);
    $pdf->SetTitle('Receipt ' . $sale['receipt_number']);
    $pdf->SetSubject('Receipt');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 20);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Get logo path for TCPDF
    $logoPath = '';
    $logoHeight = 0;
    if ($receiptLogoPath) {
        $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
        if (file_exists($logoFullPath)) {
            $logoPath = realpath($logoFullPath);
            $logoHeight = 25;
        }
    }
    
    // Start Y position
    $startY = 15;
    $pdf->SetY($startY);
    
    // Logo on right (if exists)
    $logoBottomY = $startY;
    if ($logoPath) {
        $logoWidth = 45;
        $logoX = 195 - 5 - $logoWidth;
        $logoY = $startY;
        try {
            $pdf->Image($logoPath, $logoX, $logoY, $logoWidth, $logoHeight, '', '', '', false, 300, '', false, false, 0);
            $logoBottomY = $logoY + $logoHeight;
        } catch (Exception $e) {
            error_log("Failed to add logo to PDF: " . $e->getMessage());
            $logoPath = '';
        }
    }
    
    // Company name on left
    $pdf->SetXY(15, $startY);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(95, 8, htmlspecialchars($companyName), 0, 1, 'L');
    
    // Company address on left
    $pdf->SetFont('helvetica', '', 9);
    $pdf->MultiCell(95, 5, htmlspecialchars($companyAddress), 0, 'L', false, 0);
    
    // Contact info
    $contactStartY = max($pdf->GetY(), $logoBottomY + 3);
    $pdf->SetXY(15, $contactStartY);
    if ($companyPhone) {
        $pdf->Cell(95, 5, 'Contact Number: ' . htmlspecialchars($companyPhone), 0, 1, 'L');
    }
    if ($companyEmail) {
        $pdf->Cell(95, 5, 'Email: ' . htmlspecialchars($companyEmail), 0, 1, 'L');
    }
    $leftSectionEndY = $pdf->GetY();
    
    // Receipt title on right
    $rightStartY = $logoBottomY + 3;
    $pdf->SetXY(110, $rightStartY);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 6, 'RECEIPT', 0, 1, 'R');
    $rightSectionEndY = $pdf->GetY();
    
    // Move to the lower of left or right section
    $nextY = max($leftSectionEndY, $rightSectionEndY);
    $pdf->SetY($nextY);
    
    // Horizontal line
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(8);
    
    // Receipt Meta Section
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(95, 5, '', 0, 0, 'L');
    $pdf->Cell(0, 5, 'Date: ' . date('d/m/Y H:i', strtotime($sale['sale_date'])), 0, 1, 'R');
    $pdf->Cell(95, 5, '', 0, 0, 'L');
    $pdf->Cell(0, 5, 'Receipt #: ' . htmlspecialchars($sale['receipt_number']), 0, 1, 'R');
    $cashierName = trim(($sale['cashier_first'] ?? '') . ' ' . ($sale['cashier_last'] ?? ''));
    if ($cashierName) {
        $pdf->Cell(95, 5, '', 0, 0, 'L');
        $pdf->Cell(0, 5, 'Cashier: ' . htmlspecialchars($cashierName), 0, 1, 'R');
    }
    $pdf->Ln(8);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(10);
    
    // Items Table Header
    $pdf->SetFillColor(30, 58, 138);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(100, 10, 'Description', 1, 0, 'L', true);
    $pdf->Cell(20, 10, 'Qty', 1, 0, 'C', true);
    $pdf->Cell(35, 10, 'Price', 1, 0, 'R', true);
    $pdf->Cell(35, 10, 'Total', 1, 1, 'R', true);
    
    // Items Table Rows
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);
    
    foreach ($items as $item) {
        $description = htmlspecialchars($item['product_name']);
        $quantity = $item['quantity'];
        $unitPrice = floatval($item['unit_price']);
        $totalPrice = floatval($item['total_price']);
        
        // Convert to payment currency if needed (for PDF display)
        if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
            $unitPrice = $unitPrice * $exchangeRate;
            $totalPrice = $totalPrice * $exchangeRate;
        }
        
        $startX = $pdf->GetX();
        $startY = $pdf->GetY();
        
        $lineHeight = 6;
        $minHeight = 8;
        
        $pdf->SetFont('helvetica', '', 9);
        $textWidth = $pdf->GetStringWidth($description);
        $cellWidth = 100;
        
        if ($textWidth <= $cellWidth) {
            $actualRowHeight = $minHeight;
            $pdf->Cell(100, $actualRowHeight, $description, 1, 0, 'L');
        } else {
            $testY = $pdf->GetY();
            $pdf->MultiCell(100, $lineHeight, $description, 0, 'L', false, 0);
            $measuredHeight = $pdf->GetY() - $testY;
            $actualRowHeight = max($minHeight, $measuredHeight);
            
            $pdf->SetXY($startX, $startY);
            $pdf->MultiCell(100, $lineHeight, $description, 1, 'L', false, 0);
            
            $descEndY = $pdf->GetY();
            if ($descEndY < $startY + $actualRowHeight) {
                $pdf->SetY($startY + $actualRowHeight);
            }
        }
        
        $pdf->SetXY($startX + 100, $startY);
        $pdf->Cell(20, $actualRowHeight, $quantity, 1, 0, 'C');
        $pdf->Cell(35, $actualRowHeight, number_format($unitPrice, 2), 1, 0, 'R');
        $pdf->Cell(35, $actualRowHeight, number_format($totalPrice, 2), 1, 1, 'R');
        
        $pdf->SetY($startY + $actualRowHeight);
    }
    
    $pdf->Ln(10);
    
    // Get fiscal receipt and taxes BEFORE summary section (for tax breakdown display)
    $primaryDb = Database::getPrimaryInstance();
    $fiscalReceipt = null;
    $fiscalReceiptTaxes = [];
    
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
         FROM fiscal_receipts fr
         LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
         LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
         WHERE fr.sale_id = :sale_id
         LIMIT 1",
        [':sale_id' => $id]
    );
    
    if ($fiscalReceipt) {
        $fiscalReceiptTaxes = $primaryDb->getRows(
            "SELECT tax_code, tax_percent, tax_id, tax_amount, sales_amount_with_tax 
             FROM fiscal_receipt_taxes 
             WHERE fiscal_receipt_id = :fiscal_receipt_id 
             ORDER BY tax_percent ASC, tax_code ASC",
            [':fiscal_receipt_id' => $fiscalReceipt['id']]
        );
    }
    
    // Summary Section - Convert amounts to payment currency if needed
    $pdfSubtotal = floatval($sale['subtotal']);
    $pdfDiscountAmount = floatval($sale['discount_amount'] ?? 0);
    $pdfTotalAmount = floatval($sale['total_amount']);
    
    if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
        $pdfSubtotal = $pdfSubtotal * $exchangeRate;
        $pdfDiscountAmount = $pdfDiscountAmount * $exchangeRate;
        $pdfTotalAmount = $pdfTotalAmount * $exchangeRate;
    }
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(100, 0, '', 0, 0);
    $pdf->Cell(55, 8, 'Subtotal:', 1, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(35, 8, number_format($pdfSubtotal, 2), 1, 1, 'R');
    
    if ($pdfDiscountAmount > 0) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(100, 0, '', 0, 0);
        $pdf->Cell(55, 8, 'Discount:', 1, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(35, 8, '-' . number_format($pdfDiscountAmount, 2), 1, 1, 'R');
    }
    
    // Tax Breakdown (if fiscalized)
    if ($fiscalReceipt && !empty($fiscalReceiptTaxes)) {
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
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(100, 0, '', 0, 0);
            
            // Format label based on tax type
            if ($group['taxCode'] === 'E') {
                $label = 'Total: Exempt from VAT';
            } elseif ($group['taxPercent'] === 0.0 || $group['taxPercent'] === 0 || $group['taxPercent'] === null) {
                $label = 'Total 0% VAT';
            } else {
                $label = 'Total ' . number_format($group['taxPercent'], 1) . '% VAT';
            }
            
            $pdf->Cell(55, 8, $label . ':', 1, 0, 'L');
            $pdf->SetFont('helvetica', 'B', 9);
            
            // Convert tax amount to payment currency if needed
            $pdfTaxAmount = floatval($group['totalAmount']);
            if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                $pdfTaxAmount = $pdfTaxAmount * $exchangeRate;
            }
            
            $pdf->Cell(35, 8, number_format($pdfTaxAmount, 2), 1, 1, 'R');
        }
    }
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(100, 0, '', 0, 0);
    $pdf->Cell(55, 10, 'TOTAL:', 1, 0, 'L');
    $pdf->Cell(35, 10, number_format($pdfTotalAmount, 2), 1, 1, 'R');
    
    $pdf->Ln(12);
    
    // Payment Information
    if (!empty($payments)) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'Payment:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        
        $totalPaid = 0;
        foreach ($payments as $payment) {
            // Use original_amount (payment currency) if available, otherwise convert base_amount
            $amount = isset($payment['original_amount']) ? floatval($payment['original_amount']) : floatval($payment['amount']);
            if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                // If amount is in base currency, convert to payment currency
                if (!isset($payment['original_amount'])) {
                    $amount = $amount * $exchangeRate;
                }
            }
            $totalPaid += $amount;
            
            $currencySymbol = $payment['currency_symbol'] ?? ($paymentCurrency ? $paymentCurrency['symbol'] : '$');
            $currencyCode = $payment['currency_code'] ?? ($paymentCurrency ? $paymentCurrency['code'] : 'USD');
            $symbolPosition = $payment['currency_symbol_position'] ?? ($paymentCurrency ? $paymentCurrency['symbol_position'] : 'before');
            
            $paymentMethod = ucfirst($payment['payment_method']);
            if ($symbolPosition === 'before') {
                $amountStr = $currencySymbol . ' ' . number_format($amount, 2);
            } else {
                $amountStr = number_format($amount, 2) . ' ' . $currencySymbol;
            }
            
            $pdf->Cell(0, 5, $paymentMethod . ': ' . $amountStr, 0, 1, 'L');
        }
        
        // Calculate change (use converted total amount)
        $change = $totalPaid - $pdfTotalAmount;
        if ($change > 0) {
            $pdf->Ln(3);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Change: ' . number_format($change, 2), 0, 1, 'L');
        }
    }
    
    $pdf->Ln(12);
    
    // Fiscal Information Section (if fiscalized)
    // Note: $fiscalReceipt was already fetched above for tax breakdown, reuse it here
    $fiscalDetails = null;
    
    if ($fiscalReceipt) {
        // Build fiscal details from fiscal receipt
        $fiscalDetails = [
            'receipt_global_no' => $fiscalReceipt['receipt_global_no'],
            'device_id' => $fiscalReceipt['device_id'],
            'verification_code' => $fiscalReceipt['receipt_verification_code'],
            'qr_code' => $fiscalReceipt['receipt_qr_data']
        ];
        $sale['fiscalized'] = 1;
    } elseif (!empty($sale['fiscal_details'])) {
        // Fallback: use fiscal_details from sale record
        $fiscalDetails = json_decode($sale['fiscal_details'], true);
        
        if ($fiscalDetails && isset($fiscalDetails['receipt_global_no'])) {
            $fiscalReceipt = $primaryDb->getRow(
                "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
                 FROM fiscal_receipts fr
                 LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
                 LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
                 WHERE fr.receipt_global_no = :receipt_global_no AND fr.device_id = :device_id
                 LIMIT 1",
                [
                    ':receipt_global_no' => $fiscalDetails['receipt_global_no'],
                    ':device_id' => $fiscalDetails['device_id'] ?? null
                ]
            );
        }
    }
    
    if ($fiscalDetails && $fiscalReceipt) {
        $pdf->Ln(10);
        
        // QR Code (CENTERED, BIGGER - according to documentation for A4)
        $qrCodeDisplayed = false;
        
        // First, try to use stored QR code image if available
        if (isset($fiscalReceipt['receipt_qr_code']) && !empty($fiscalReceipt['receipt_qr_code']) && strlen($fiscalReceipt['receipt_qr_code']) > 0) {
            try {
                // receipt_qr_code is stored as base64 encoded PNG image
                $qrImageData = base64_decode($fiscalReceipt['receipt_qr_code']);
                
                if ($qrImageData !== false && strlen($qrImageData) > 0) {
                    // It's a base64 encoded PNG, write to temp file and use it
                    $tempQrFile = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
                    file_put_contents($tempQrFile, $qrImageData);
                    
                    // Center and make bigger (30mm instead of 20mm)
                    // Page width: 210mm, left margin: 15mm, right margin: 15mm
                    // Content width: 210 - 15 - 15 = 180mm
                    // Center of content: 15 + (180 / 2) = 105mm
                    // QR left position: 105 - (30 / 2) = 90mm
                    $qrSize = 30; // mm
                    $qrX = 15 + ((210 - 15 - 15) / 2) - ($qrSize / 2); // Properly centered: 90mm
                    $qrY = $pdf->GetY();
                    $pdf->Image($tempQrFile, $qrX, $qrY, $qrSize, $qrSize, 'PNG', '', '', false, 300, '', false, false, 0);
                    $pdf->SetY($qrY + $qrSize + 5);
                    @unlink($tempQrFile);
                    $qrCodeDisplayed = true;
                }
            } catch (Exception $e) {
                error_log("QR code image error: " . $e->getMessage());
            }
        }
        
        // Fallback: Generate QR code on-the-fly from receipt_qr_data
        if (!$qrCodeDisplayed && isset($fiscalReceipt['receipt_qr_data']) && !empty($fiscalReceipt['receipt_qr_data'])) {
            try {
                // Build full QR URL from qr_data
                $qrUrl = $fiscalReceipt['qr_url'] ?? 'https://fdmstest.zimra.co.zw';
                $deviceId = $fiscalReceipt['device_id'] ?? '';
                $receiptDate = $fiscalReceipt['receipt_date'] ?? '';
                $receiptGlobalNo = $fiscalReceipt['receipt_global_no'] ?? '';
                
                if ($deviceId && $receiptDate && $receiptGlobalNo) {
                    $deviceIdFormatted = str_pad($deviceId, 10, '0', STR_PAD_LEFT);
                    $date = new DateTime($receiptDate);
                    $receiptDateFormatted = $date->format('dmy');
                    $receiptGlobalNoFormatted = str_pad($receiptGlobalNo, 10, '0', STR_PAD_LEFT);
                    $qrDataFormatted = substr($fiscalReceipt['receipt_qr_data'], 0, 16);
                    $qrCodeString = rtrim($qrUrl, '/') . '/' . $deviceIdFormatted . $receiptDateFormatted . $receiptGlobalNoFormatted . $qrDataFormatted;
                    
                    // Use TCPDF's built-in QR code support - CENTERED and BIGGER
                    $style = array(
                        'border' => false,
                        'padding' => 0,
                        'fgcolor' => array(0,0,0),
                        'bgcolor' => false,
                        'module_width' => 1,
                        'module_height' => 1
                    );
                    $qrSize = 30; // mm - bigger
                    // Page width: 210mm, left margin: 15mm, right margin: 15mm
                    // Content width: 210 - 15 - 15 = 180mm
                    // Center of content: 15 + (180 / 2) = 105mm
                    // QR left position: 105 - (30 / 2) = 90mm
                    $qrX = 15 + ((210 - 15 - 15) / 2) - ($qrSize / 2); // Properly centered: 90mm
                    $qrY = $pdf->GetY();
                    $pdf->write2DBarcode($qrCodeString, 'QRCODE,L', $qrX, $qrY, $qrSize, $qrSize, $style, 'N');
                    $pdf->SetY($qrY + $qrSize + 5);
                    $qrCodeDisplayed = true;
                }
            } catch (Exception $e) {
                error_log("QR code generation error: " . $e->getMessage());
            }
        }
        
        // Verification Code (BELOW QR CODE - according to documentation)
        if (isset($fiscalDetails['verification_code'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Verification code: ' . $fiscalDetails['verification_code'], 0, 1, 'C');
        }
        
        // Verification URL
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(0, 4, 'You can verify this receipt manually at', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'U', 8);
        $pdf->SetTextColor(30, 58, 138);
        $pdf->Cell(0, 4, 'https://receipt.zimra.org/', 0, 1, 'C', false, 'https://receipt.zimra.org/');
        $pdf->SetTextColor(0, 0, 0);
        
        $pdf->Ln(5);
    }
    
    // Footer
    $receiptFooterText = getSetting('pos_receipt_footer_text', 'Thank you for your business!');
    if ($receiptFooterText) {
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(8);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, htmlspecialchars($receiptFooterText), 0, 1, 'C');
    }
    
    // Output PDF
    $pdf->Output('Receipt_' . $sale['receipt_number'] . '.pdf', 'D');
    exit;
}

$pageTitle = 'Receipt #' . escapeHtml($sale['receipt_number']);
require_once APP_PATH . '/includes/header.php';
?>

<style>
/* Receipt container styles (similar to receipt.php) */
.receipt-container {
    max-width: 400px;
    margin: 0 auto;
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

/* Override main CSS to prevent double scrollbar */
body, html {
    overflow-y: auto !important;
    overflow-x: hidden !important;
    height: auto !important;
}

.content-area {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    padding: 30px !important;
    padding-top: 10px !important;
    justify-content: flex-start !important;
    min-height: calc(100vh - 100px) !important;
    position: relative !important;
    overflow: visible !important;
    overflow-x: hidden !important;
    overflow-y: visible !important;
    flex: none !important;
}

.receipt-container {
    max-width: 400px;
    margin: 0 auto;
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: visible !important;
    position: relative;
    width: 100%;
}

/* Action buttons - normal flow, no sticky */
.no-print {
    text-align: center;
    padding: 20px 0;
    margin-bottom: 20px;
}

.receipt-header {
    text-align: center;
    border-bottom: 2px solid #1e3a8a;
    padding-bottom: 15px;
    margin-bottom: 15px;
}

.receipt-header img {
    display: block;
    margin: 0 auto 15px;
    max-width: 200px;
    max-height: 80px;
    object-fit: contain;
}

.receipt-header h2 {
    margin: 0 0 8px 0;
    color: #1e3a8a;
    font-size: 20px;
}

.receipt-header .company-info {
    font-size: 10px;
    line-height: 1.4;
}

.receipt-info {
    margin: 12px 0;
    font-size: 11px;
    line-height: 1.6;
}

.receipt-info div {
    margin-bottom: 4px;
}

.receipt-info strong {
    display: inline-block;
    min-width: 80px;
}

.receipt-container table {
    width: 100%;
    border-collapse: collapse;
    margin: 12px 0;
    font-size: 11px;
}

.receipt-container table th, 
.receipt-container table td {
    padding: 6px 4px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.receipt-container table th {
    background: #f3f4f6;
    font-weight: bold;
}

.receipt-container .total-row {
    font-weight: bold;
    font-size: 14px;
    border-top: 2px solid #1e3a8a;
}

.receipt-footer {
    text-align: center;
    margin-top: 15px;
    padding-top: 12px;
    border-top: 2px solid #1e3a8a;
    font-size: 11px;
}

/* ========== PRINT STYLES ========== */
@media print {
    @page {
        size: 80mm auto;
        margin: 0;
    }
    
    .sidebar,
    .topbar,
    .no-print,
    .no-print * {
        display: none !important;
    }
    
    body {
        margin: 0;
        padding: 0;
        font-size: 12px;
        background: white !important;
    }
    
    .content-area {
        display: block !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    
    .receipt-container {
        max-width: 80mm !important;
        width: 80mm !important;
        margin: 0 auto !important;
        padding: 10mm 5mm !important;
        box-shadow: none !important;
        border: none !important;
        border-radius: 0 !important;
        display: block !important;
    }
    
    .receipt-container * {
        visibility: visible !important;
    }
    
    .receipt-header {
        border-bottom: 1px solid #000 !important;
        padding-bottom: 8px !important;
        margin-bottom: 8px !important;
        display: block !important;
    }
    
    .receipt-header img {
        max-width: 150px !important;
        max-height: 60px !important;
        margin-bottom: 8px !important;
    }
    
    .receipt-header h2 {
        color: #000 !important;
        font-size: 16px !important;
        display: block !important;
    }
    
    .receipt-header .company-info {
        font-size: 9px !important;
        display: block !important;
    }
    
    .receipt-info {
        margin: 8px 0 !important;
        font-size: 9px !important;
        display: block !important;
    }
    
    .receipt-info div {
        display: block !important;
    }
    
    .receipt-container table {
        margin: 8px 0 !important;
        font-size: 9px !important;
        display: table !important;
        width: 100% !important;
    }
    
    .receipt-container table thead,
    .receipt-container table tbody,
    .receipt-container table tfoot {
        display: table-row-group !important;
    }
    
    .receipt-container table tr {
        display: table-row !important;
    }
    
    .receipt-container table th, 
    .receipt-container table td {
        padding: 3px 2px !important;
        border-bottom: 1px dashed #ccc !important;
        display: table-cell !important;
    }
    
    .receipt-container table th {
        background: transparent !important;
        border-bottom: 1px solid #000 !important;
    }
    
    .receipt-container .total-row {
        font-size: 11px !important;
        border-top: 1px solid #000 !important;
    }
    
    .receipt-footer {
        margin-top: 10px !important;
        padding-top: 8px !important;
        border-top: 1px solid #000 !important;
        font-size: 9px !important;
        display: block !important;
    }
    
    .content-area {
        padding: 0 !important;
        margin: 0 !important;
    }
}
</style>

<div class="content-area">
    <!-- Action Buttons - Top -->
    <div class="no-print mb-4" style="text-align: center; padding: 20px 0; margin-bottom: 20px;">
        <div class="d-flex justify-content-center gap-2 flex-wrap">
            <a href="<?= BASE_URL ?>modules/pos/index.php?payment_success=1&receipt_id=<?= $id ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to POS
            </a>
            <button class="btn btn-primary" onclick="showEmailModal()">
                <i class="bi bi-envelope"></i> Send via Email
            </button>
            <button class="btn btn-success" onclick="showWhatsAppModal()">
                <i class="bi bi-whatsapp"></i> Send via WhatsApp
            </button>
            <button class="btn btn-info" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
            <a href="receipt.php?id=<?= $id ?>&pdf=1" class="btn btn-secondary">
                <i class="bi bi-file-earmark-pdf"></i> Export A4 PDF
            </a>
            <?php if ($sale['payment_status'] !== 'refunded'): ?>
                <button class="btn btn-warning" onclick="refundSale(<?= $id ?>)">
                    <i class="bi bi-arrow-counterclockwise"></i> Refund
                </button>
                <button class="btn btn-danger" onclick="deleteReceipt(<?= $id ?>)">
                    <i class="bi bi-trash"></i> Delete
                </button>
            <?php else: ?>
                <span class="badge bg-danger">Refunded</span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Receipt Container - Below Buttons -->
    <div class="receipt-container">
    
    <div class="receipt-header">
        <?php if ($receiptLogoUrl): ?>
            <div style="text-align: center; margin-bottom: 15px;">
                <img src="<?= htmlspecialchars($receiptLogoUrl) ?>" alt="Logo" style="max-width: 200px; max-height: 80px; object-fit: contain;" onerror="this.style.display='none';">
            </div>
        <?php endif; ?>
        <h2><?= escapeHtml($companyName) ?></h2>
        <div class="company-info">
            <?php if ($companyAddress): ?>
                <?= escapeHtml($companyAddress) ?><br>
            <?php endif; ?>
            <?php if ($companyPhone): ?>
                Phone: <?= escapeHtml($companyPhone) ?><br>
            <?php endif; ?>
            <?php if ($companyEmail): ?>
                <?= escapeHtml($companyEmail) ?>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="receipt-info">
        <div><strong>Receipt #:</strong> <?= escapeHtml($sale['receipt_number']) ?></div>
        <div><strong>Date:</strong> <?= formatDateTime($sale['sale_date']) ?></div>
        <div><strong>Cashier:</strong> <?= escapeHtml(($sale['cashier_first'] ?? '') . ' ' . ($sale['cashier_last'] ?? '')) ?></div>
        <?php if ($sale['first_name']): ?>
            <div><strong>Customer:</strong> <?= escapeHtml($sale['first_name'] . ' ' . $sale['last_name']) ?></div>
        <?php endif; ?>
    </div>
    
    <?php
    // Get fiscal receipt data BEFORE the table so taxes can be displayed in the tfoot
    $primaryDb = Database::getPrimaryInstance();
    $fiscalReceipt = null;
    $fiscalReceiptTaxes = [];
    
    $fiscalReceipt = $primaryDb->getRow(
        "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
         FROM fiscal_receipts fr
         LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
         LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
         WHERE fr.sale_id = :sale_id
         LIMIT 1",
        [':sale_id' => $id]
    );
    
    if ($fiscalReceipt) {
        $fiscalReceiptTaxes = $primaryDb->getRows(
            "SELECT tax_code, tax_percent, tax_id, tax_amount, sales_amount_with_tax 
             FROM fiscal_receipt_taxes 
             WHERE fiscal_receipt_id = :fiscal_receipt_id 
             ORDER BY tax_percent ASC, tax_code ASC",
            [':fiscal_receipt_id' => $fiscalReceipt['id']]
        );
    }
    ?>
    
    <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
        <colgroup>
            <col style="width: auto;">
            <col style="width: 50px;">
            <col style="width: 80px;">
            <col style="width: 80px;">
        </colgroup>
        <thead>
            <tr>
                <th style="text-align: left; padding: 6px 4px; border-bottom: 1px solid #ddd;">Item</th>
                <th style="text-align: center; padding: 6px 4px; border-bottom: 1px solid #ddd;">Qty</th>
                <th style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd;">Price</th>
                <th style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            // Convert items to payment currency if different from base
            foreach ($items as $item): 
                $unitPrice = floatval($item['unit_price']);
                $totalPrice = floatval($item['total_price']);
                
                // Convert to payment currency if needed (base currency -> payment currency)
                if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                    // Convert from base to payment currency (multiply by exchange rate)
                    $unitPrice = $unitPrice * $exchangeRate;
                    $totalPrice = $totalPrice * $exchangeRate;
                }
                
                // Format with payment currency
                $unitPriceFormatted = $paymentCurrency ? formatCurrencyAmount($unitPrice, $paymentCurrencyId, $db) : formatCurrency($unitPrice);
                $totalPriceFormatted = $paymentCurrency ? formatCurrencyAmount($totalPrice, $paymentCurrencyId, $db) : formatCurrency($totalPrice);
            ?>
                <tr>
                    <td style="text-align: left; padding: 6px 4px; word-wrap: break-word; border-bottom: 1px solid #ddd;"><?= escapeHtml($item['product_name']) ?></td>
                    <td style="text-align: center; padding: 6px 4px; border-bottom: 1px solid #ddd;"><?= $item['quantity'] ?></td>
                    <td style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd;"><?= $unitPriceFormatted ?></td>
                    <td style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd;"><?= $totalPriceFormatted ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php
            // Convert amounts to payment currency if needed
            $subtotal = floatval($sale['subtotal']);
            $discountAmount = floatval($sale['discount_amount'] ?? 0);
            $totalAmount = floatval($sale['total_amount']);
            
            if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                $subtotal = $subtotal * $exchangeRate;
                $discountAmount = $discountAmount * $exchangeRate;
                $totalAmount = $totalAmount * $exchangeRate;
            }
            
            $subtotalFormatted = $paymentCurrency ? formatCurrencyAmount($subtotal, $paymentCurrencyId, $db) : formatCurrency($subtotal);
            $discountFormatted = $paymentCurrency ? formatCurrencyAmount($discountAmount, $paymentCurrencyId, $db) : formatCurrency($discountAmount);
            $totalFormatted = $paymentCurrency ? formatCurrencyAmount($totalAmount, $paymentCurrencyId, $db) : formatCurrency($totalAmount);
            ?>
            <tr>
                <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong>Subtotal:</strong></td>
                <td style="text-align: right; padding: 6px 4px;"><strong><?= $subtotalFormatted ?></strong></td>
            </tr>
            <?php if ($discountAmount > 0): ?>
                <tr>
                    <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong>Discount:</strong></td>
                    <td style="text-align: right; padding: 6px 4px;"><strong>-<?= $discountFormatted ?></strong></td>
                </tr>
            <?php endif; ?>
            <?php
            // Tax Breakdown (if fiscalized) - get from fiscal receipt taxes
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
                foreach ($taxGroups as $group):
                    // Format label based on tax type
                    if ($group['taxCode'] === 'E') {
                        $label = 'Total: Exempt from VAT';
                    } elseif ($group['taxPercent'] === 0.0 || $group['taxPercent'] === 0 || $group['taxPercent'] === null) {
                        $label = 'Total 0% VAT';
                    } else {
                        $label = 'Total ' . number_format($group['taxPercent'], 1) . '% VAT';
                    }
                    
                    // Convert tax amount to payment currency if needed
                    $taxAmount = floatval($group['totalAmount']);
                    if ($paymentCurrency && $paymentCurrencyId && $baseCurrency && $paymentCurrencyId != $baseCurrency['id']) {
                        $taxAmount = $taxAmount * $exchangeRate;
                    }
                    $taxAmountFormatted = $paymentCurrency ? formatCurrencyAmount($taxAmount, $paymentCurrencyId, $db) : formatCurrency($taxAmount);
            ?>
                <tr>
                    <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong><?= escapeHtml($label) ?>:</strong></td>
                    <td style="text-align: right; padding: 6px 4px;"><strong><?= $taxAmountFormatted ?></strong></td>
                </tr>
            <?php
                endforeach;
            }
            ?>
            <tr class="total-row">
                <td colspan="3" style="text-align: right; padding: 6px 4px;"><strong>TOTAL:</strong></td>
                <td style="text-align: right; padding: 6px 4px;"><strong><?= $totalFormatted ?></strong></td>
            </tr>
            <tr>
                <td colspan="4" style="padding: 6px 4px; padding-top: 8px;">
                    <strong>Payment:</strong><br>
                    <?php 
                    $totalPaid = 0;
                    if (empty($payments)): 
                    ?>
                        <div style="margin-left: 10px; color: #999; font-style: italic;">No payment information available</div>
                    <?php else: 
                        foreach ($payments as $payment): 
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
                    ?>
                        <div style="margin-left: 10px;">
                            <?= escapeHtml(ucfirst($payment['payment_method'])) ?>: <?= $formattedAmount ?>
                            <?php if ($currencyCode && $currencyCode !== ($baseCurrency ? $baseCurrency['code'] : 'USD')): ?>
                                <span style="font-size: 0.9em; color: #666;">(<?= escapeHtml($currencyCode) ?>)</span>
                            <?php endif; ?>
                        </div>
                    <?php 
                        endforeach; 
                    endif; 
                    ?>
                </td>
            </tr>
            <?php 
            // Calculate change if amount paid exceeds total (convert to payment currency if needed)
            $change = $totalPaid - $totalAmount; // Use converted totalAmount
            if ($change > 0): 
                $changeFormatted = $paymentCurrency ? formatCurrencyAmount($change, $paymentCurrencyId, $db) : formatCurrency($change);
            ?>
                <tr>
                    <td colspan="3" style="text-align: right; padding: 6px 4px; padding-top: 8px;"><strong>Change:</strong></td>
                    <td style="text-align: right; padding: 6px 4px; padding-top: 8px;"><strong><?= $changeFormatted ?></strong></td>
                </tr>
            <?php endif; ?>
        </tfoot>
    </table>
    
    <?php
    // Fiscal Information Section (for HTML view)
    if (!$usePDF) {
        // Get fiscal receipt data (same logic as PDF view)
        $primaryDb = Database::getPrimaryInstance();
        $fiscalDetails = null;
        $fiscalReceipt = null;
        
        // First, try to get fiscal receipt by sale_id
        $fiscalReceipt = $primaryDb->getRow(
            "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
             FROM fiscal_receipts fr
             LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
             LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
             WHERE fr.sale_id = :sale_id
             LIMIT 1",
            [':sale_id' => $id]
        );
        
        if ($fiscalReceipt) {
            // Build fiscal details from fiscal receipt
            $fiscalDetails = [
                'receipt_global_no' => $fiscalReceipt['receipt_global_no'],
                'device_id' => $fiscalReceipt['device_id'],
                'verification_code' => $fiscalReceipt['receipt_verification_code'],
                'qr_code' => $fiscalReceipt['receipt_qr_data']
            ];
        } elseif (!empty($sale['fiscal_details'])) {
            // Fallback: use fiscal_details from sale record
            $fiscalDetails = json_decode($sale['fiscal_details'], true);
            
            if ($fiscalDetails && isset($fiscalDetails['receipt_global_no'])) {
                $fiscalReceipt = $primaryDb->getRow(
                    "SELECT fr.*, fd.device_serial_no, fd.device_id, fc.qr_url 
                     FROM fiscal_receipts fr
                     LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
                     LEFT JOIN fiscal_config fc ON fr.branch_id = fc.branch_id AND fr.device_id = fc.device_id
                     WHERE fr.receipt_global_no = :receipt_global_no AND fr.device_id = :device_id
                     LIMIT 1",
                    [
                        ':receipt_global_no' => $fiscalDetails['receipt_global_no'],
                        ':device_id' => $fiscalDetails['device_id'] ?? null
                    ]
                );
            }
        }
        
        // Display fiscal information if available
        if ($fiscalDetails && $fiscalReceipt):
    ?>
    <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd;">
        <?php
        // QR Code Display (FIRST - according to documentation)
        $qrCodeDisplayed = false;
        
        // First, try to use stored QR code image if available
        if (isset($fiscalReceipt['receipt_qr_code']) && !empty($fiscalReceipt['receipt_qr_code']) && strlen($fiscalReceipt['receipt_qr_code']) > 0) {
            try {
                $qrImageData = base64_decode($fiscalReceipt['receipt_qr_code']);
                if ($qrImageData !== false && strlen($qrImageData) > 0) {
                    $qrImageBase64 = base64_encode($qrImageData);
                    echo '<div style="text-align: center; margin: 10px 0;">';
                    echo '<img src="data:image/png;base64,' . htmlspecialchars($qrImageBase64) . '" alt="QR Code" style="max-width: 120px; height: auto; border: 1px solid #ddd;">';
                    echo '</div>';
                    $qrCodeDisplayed = true;
                }
            } catch (Exception $e) {
                error_log("QR code image error: " . $e->getMessage());
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
                $receiptDateFormatted = $date->format('dmy');
                $receiptGlobalNoFormatted = str_pad($receiptGlobalNo, 10, '0', STR_PAD_LEFT);
                $qrDataFormatted = substr($fiscalReceipt['receipt_qr_data'], 0, 16);
                $qrCodeString = rtrim($qrUrl, '/') . '/' . $deviceIdFormatted . $receiptDateFormatted . $receiptGlobalNoFormatted . $qrDataFormatted;
                
                // Use a QR code API service to generate the image
                $qrCodeApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($qrCodeString);
                echo '<div style="text-align: center; margin: 10px 0;">';
                echo '<img src="' . htmlspecialchars($qrCodeApiUrl) . '" alt="QR Code" style="max-width: 120px; height: auto; border: 1px solid #ddd;">';
                echo '</div>';
            }
        }
        
        // Verification Code (BELOW QR CODE - according to documentation)
        if (isset($fiscalDetails['verification_code'])): ?>
            <div style="text-align: center; margin: 8px 0; font-weight: bold; font-size: 10px;">
                Verification code: <?= escapeHtml($fiscalDetails['verification_code']) ?>
            </div>
        <?php endif; ?>
        
        <!-- Verification URL -->
        <div style="text-align: center; margin: 5px 0; font-size: 9px; color: #666;">
            You can verify this receipt manually at<br>
            <a href="https://receipt.zimra.org/" target="_blank" style="color: #1e3a8a; text-decoration: underline;">https://receipt.zimra.org/</a>
        </div>
    </div>
    <?php
        endif;
    }
    ?>
    
    <div class="receipt-footer">
        <div style="margin-bottom: 5px;">Thank you for your business!</div>
        <div>
            <?= SYSTEM_NAME ?> - <?= SYSTEM_VERSION ?? '1.0.0' ?>
        </div>
    </div>
    </div>
</div>

<!-- Email Modal -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailModalLabel">Send Receipt via Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="emailForm">
                    <input type="hidden" name="receipt_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label for="emailAddress" class="form-label">Email Address *</label>
                        <input type="email" class="form-control" id="emailAddress" name="email" 
                               value="<?= escapeHtml($sale['email'] ?? '') ?>" required>
                        <small class="text-muted">Enter the email address to send the receipt to</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="sendReceiptEmail()">
                    <i class="bi bi-envelope"></i> Send Email
                </button>
            </div>
        </div>
    </div>
</div>

<!-- WhatsApp Modal -->
<div class="modal fade" id="whatsappModal" tabindex="-1" aria-labelledby="whatsappModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="whatsappModalLabel">Send Receipt via WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="whatsappForm">
                    <input type="hidden" name="receipt_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label for="whatsappNumber" class="form-label">WhatsApp Number *</label>
                        <input type="text" class="form-control" id="whatsappNumber" name="phone" 
                               placeholder="e.g., +263771234567" 
                               value="<?= escapeHtml($sale['phone'] ?? '') ?>" required>
                        <small class="text-muted">Enter the WhatsApp number with country code (e.g., +263771234567)</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="sendReceiptWhatsApp()">
                    <i class="bi bi-whatsapp"></i> Send WhatsApp
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function showEmailModal() {
    const modal = new bootstrap.Modal(document.getElementById('emailModal'));
    modal.show();
}

function showWhatsAppModal() {
    const modal = new bootstrap.Modal(document.getElementById('whatsappModal'));
    modal.show();
}

function sendReceiptEmail() {
    const email = document.getElementById('emailAddress').value.trim();
    const receiptId = <?= $id ?>;
    
    if (!email) {
        Swal.fire('Error', 'Please enter an email address', 'error');
        return;
    }
    
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        Swal.fire('Error', 'Please enter a valid email address', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Sending...',
        text: 'Please wait while we send the receipt',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/send_receipt_email.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `receipt_id=${receiptId}&email=${encodeURIComponent(email)}`
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                }
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            Swal.fire('Success!', data.message, 'success').then(() => {
                bootstrap.Modal.getInstance(document.getElementById('emailModal')).hide();
            });
        } else {
            const errorMsg = data.message || (data.debug ? JSON.stringify(data.debug) : 'Failed to send email');
            Swal.fire('Error', errorMsg, 'error');
            if (data.debug) {
                console.error('Email send error details:', data.debug);
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An unexpected error occurred: ' + error.message, 'error');
    });
}

function sendReceiptWhatsApp() {
    const phone = document.getElementById('whatsappNumber').value.trim();
    const receiptId = <?= $id ?>;
    
    if (!phone) {
        Swal.fire('Error', 'Please enter a WhatsApp number', 'error');
        return;
    }
    
    // Basic phone validation
    const phoneRegex = /^\+?[1-9]\d{1,14}$/;
    if (!phoneRegex.test(phone.replace(/\s/g, ''))) {
        Swal.fire('Error', 'Please enter a valid WhatsApp number with country code', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Sending...',
        text: 'Please wait while we send the receipt',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/send_receipt_whatsapp.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `receipt_id=${receiptId}&phone=${encodeURIComponent(phone)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: 'Success!',
                text: 'Opening WhatsApp...',
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: 'Open WhatsApp',
                cancelButtonText: 'Close'
            }).then((result) => {
                if (result.isConfirmed && data.whatsapp_link) {
                    window.open(data.whatsapp_link, '_blank');
                }
                bootstrap.Modal.getInstance(document.getElementById('whatsappModal')).hide();
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to send WhatsApp message', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An unexpected error occurred', 'error');
    });
}

// Refund and Delete functions
function refundSale(saleId) {
    // Load sale data and show refund modal
    fetch('<?= BASE_URL ?>ajax/get_sale_for_refund.php?id=' + saleId, {
        method: 'GET',
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: 'Refund Sale',
                html: `
                    <p>Are you sure you want to refund this sale?</p>
                    <p><strong>Receipt:</strong> ${data.sale.receipt_number}</p>
                    <p><strong>Amount:</strong> $${parseFloat(data.sale.total_amount).toFixed(2)}</p>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Refund',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#f59e0b'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Redirect to refund page or process refund
                    window.location.href = '<?= BASE_URL ?>modules/pos/manage.php?id=' + saleId + '&action=refund';
                }
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to load sale data', 'error');
        }
    })
    .catch(error => {
        console.error('Error loading sale:', error);
        Swal.fire('Error', 'Failed to load sale data', 'error');
    });
}

function deleteReceipt(saleId) {
    Swal.fire({
        title: 'Delete Receipt?',
        html: `
            <p>Are you sure you want to delete this receipt?</p>
            <p class="text-danger"><strong>This action will:</strong></p>
            <ul class="text-start text-danger">
                <li>Restore stock for all items</li>
                <li>Reverse shift cash adjustments</li>
                <li>Mark the receipt as deleted</li>
            </ul>
            <p class="text-muted">This action cannot be undone.</p>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc3545'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting Receipt...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            const formData = new FormData();
            formData.append('sale_id', saleId);
            
            fetch('<?= BASE_URL ?>ajax/delete_receipt.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: data.message || 'Receipt deleted successfully',
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // Redirect to manage sales
                        window.location.href = '<?= BASE_URL ?>modules/pos/manage.php';
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to delete receipt', 'error');
                }
            })
            .catch(error => {
                console.error('Delete receipt error:', error);
                Swal.fire('Error', 'Failed to delete receipt: ' + error.message, 'error');
            });
        }
    });
}

// Auto-print on page load if print parameter is set
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const shouldPrint = urlParams.get('print');
    
    if (shouldPrint === '1') {
        setTimeout(() => {
            window.print();
        }, 500);
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

