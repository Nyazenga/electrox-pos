<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('pos.shift_management');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    redirectTo('modules/pos/shift_management.php');
}

$db = Database::getInstance();
$shift = $db->getRow("SELECT s.*, b.branch_name, b.address as branch_address, u1.first_name as opened_first, u1.last_name as opened_last, u2.first_name as closed_first, u2.last_name as closed_last 
                       FROM shifts s 
                       LEFT JOIN branches b ON s.branch_id = b.id 
                       LEFT JOIN users u1 ON s.opened_by = u1.id 
                       LEFT JOIN users u2 ON s.closed_by = u2.id 
                       WHERE s.id = :id", [':id' => $id]);

if (!$shift) {
    redirectTo('modules/pos/shift_management.php');
}

// Calculate statistics (same as shift_report.php)
$cashSales = $db->getRow("SELECT COALESCE(SUM(DISTINCT s.total_amount), 0) as total 
                          FROM sales s 
                          INNER JOIN sale_payments sp ON s.id = sp.sale_id 
                          WHERE s.shift_id = :shift_id 
                            AND LOWER(sp.payment_method) = 'cash'", 
                          [':shift_id' => $id]);
if ($cashSales === false) {
    $cashSales = ['total' => 0];
}

$cashReceived = $db->getRow("SELECT COALESCE(SUM(COALESCE(sp.base_amount, sp.amount)), 0) as total 
                              FROM sale_payments sp 
                              INNER JOIN sales s ON sp.sale_id = s.id 
                              WHERE s.shift_id = :shift_id AND LOWER(sp.payment_method) = 'cash'", 
                              [':shift_id' => $id]);
if ($cashReceived === false) {
    $cashReceived = ['total' => 0];
}

$totalSales = $db->getRow("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total, 
                           COALESCE(SUM(discount_amount), 0) as discount, COALESCE(SUM(tax_amount), 0) as tax 
                           FROM sales WHERE shift_id = :shift_id", 
                           [':shift_id' => $id]);
if ($totalSales === false) {
    $totalSales = ['count' => 0, 'total' => 0, 'discount' => 0, 'tax' => 0];
}

$payIns = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM drawer_transactions 
                       WHERE shift_id = :shift_id AND transaction_type = 'pay_in'", 
                       [':shift_id' => $id]);
if ($payIns === false) {
    $payIns = ['total' => 0];
}

$payOuts = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total FROM drawer_transactions 
                       WHERE shift_id = :shift_id AND transaction_type = 'pay_out'", 
                       [':shift_id' => $id]);
if ($payOuts === false) {
    $payOuts = ['total' => 0];
}

$borrowedCash = 0;
$changeTransactions = $db->getRows("SELECT * FROM drawer_transactions 
                                    WHERE shift_id = :shift_id 
                                    AND transaction_type = 'pay_out' 
                                    AND reason = 'Change Given'
                                    AND notes LIKE '%Borrowed%'", 
                                    [':shift_id' => $id]);
if ($changeTransactions !== false) {
    foreach ($changeTransactions as $trans) {
        if (preg_match('/Borrowed \$([\d,]+\.?\d*)/', $trans['notes'], $matches)) {
            $borrowedCash += floatval(str_replace(',', '', $matches[1]));
        }
    }
}

$outstandingBorrowed = max(0, $borrowedCash - $payIns['total']);

$baseCurrency = getBaseCurrency($db);
$baseCurrencyId = $baseCurrency ? $baseCurrency['id'] : 1;

$paymentTypes = $db->getRows("SELECT sp.payment_method, 
                                      COALESCE(sp.currency_id, :base_currency_id) as currency_id,
                                      COALESCE(SUM(sp.base_amount), SUM(sp.amount), 0) as total_base,
                                      COALESCE(SUM(sp.original_amount), SUM(sp.amount), 0) as total_original
                               FROM sale_payments sp 
                               INNER JOIN sales s ON sp.sale_id = s.id 
                               WHERE s.shift_id = :shift_id 
                               GROUP BY sp.payment_method, sp.currency_id", 
                               [':shift_id' => $id, ':base_currency_id' => $baseCurrencyId]);
if ($paymentTypes === false) {
    $paymentTypes = [];
}

$currencies = getActiveCurrencies($db);
$currencyBreakdown = [];
foreach ($currencies as $currency) {
    $currencySales = $db->getRow("SELECT 
                                      COALESCE(SUM(sp.base_amount), SUM(sp.amount), 0) as total_base,
                                      COALESCE(SUM(sp.original_amount), SUM(sp.amount), 0) as total_original,
                                      COUNT(DISTINCT sp.sale_id) as transaction_count
                                   FROM sale_payments sp 
                                   INNER JOIN sales s ON sp.sale_id = s.id 
                                   WHERE s.shift_id = :shift_id 
                                     AND COALESCE(sp.currency_id, :base_currency_id) = :currency_id", 
                                   [':shift_id' => $id, 
                                    ':currency_id' => $currency['id'],
                                    ':base_currency_id' => $baseCurrencyId]);
    if ($currencySales && ($currencySales['total_base'] > 0 || $currencySales['total_original'] > 0)) {
        $currencyBreakdown[$currency['id']] = [
            'currency' => $currency,
            'total_base' => floatval($currencySales['total_base']),
            'total_original' => floatval($currencySales['total_original']),
            'transaction_count' => intval($currencySales['transaction_count'])
        ];
    }
}

$paymentMethodCurrencyBreakdown = $db->getRows("SELECT 
                                                    sp.payment_method,
                                                    sp.currency_id,
                                                    COALESCE(SUM(sp.base_amount), SUM(sp.amount), 0) as total_base,
                                                    COALESCE(SUM(sp.original_amount), SUM(sp.amount), 0) as total_original
                                                 FROM sale_payments sp 
                                                 INNER JOIN sales s ON sp.sale_id = s.id 
                                                 WHERE s.shift_id = :shift_id 
                                                 GROUP BY sp.payment_method, COALESCE(sp.currency_id, :base_currency_id)
                                                 ORDER BY sp.payment_method, COALESCE(sp.currency_id, :base_currency_id)", 
                                                 [':shift_id' => $id, ':base_currency_id' => $baseCurrencyId]);
if ($paymentMethodCurrencyBreakdown === false) {
    $paymentMethodCurrencyBreakdown = [];
}

foreach ($paymentMethodCurrencyBreakdown as &$breakdown) {
    if (empty($breakdown['currency_id']) || $breakdown['currency_id'] == 0) {
        $breakdown['currency_id'] = $baseCurrencyId;
    }
}
unset($breakdown);

// Get company settings
$companyName = getSetting('company_name', SYSTEM_NAME);
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');
$companyTagline = getSetting('company_tagline', 'Transforming Your Tomorrow');

// Get receipt logo
// Get receipt logo - check pos_receipt_logo first, then fallback to invoice_logo, then company_logo
$receiptLogoPath = getSetting('pos_receipt_logo', '');
// If no receipt logo setting found, try invoice_logo, then company_logo
if (empty($receiptLogoPath)) {
    $receiptLogoPath = getSetting('invoice_logo', getSetting('company_logo', ''));
}
// If still no logo setting found, try to find the most recent receipt_logo or invoice_logo file
if (empty($receiptLogoPath)) {
    $logoDir = APP_PATH . '/assets/images/';
    $logoFiles = array_merge(
        glob($logoDir . 'receipt_logo_*.png'),
        glob($logoDir . 'receipt_logo_*.jpg'),
        glob($logoDir . 'receipt_logo_*.jpeg'),
        glob($logoDir . 'invoice_logo_*.png'),
        glob($logoDir . 'invoice_logo_*.jpg'),
        glob($logoDir . 'invoice_logo_*.jpeg')
    );
    if (!empty($logoFiles)) {
        // Sort by modification time, most recent first
        usort($logoFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $mostRecent = $logoFiles[0];
        $receiptLogoPath = 'assets/images/' . basename($mostRecent);
        // If we found a file but no database setting, save it to the database for persistence
        if (!empty($receiptLogoPath)) {
            setSetting('pos_receipt_logo', $receiptLogoPath);
        }
    }
}
// Normalize logo path - ensure it's relative to APP_PATH
if ($receiptLogoPath && !empty($receiptLogoPath)) {
    $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
    // If file doesn't exist at the stored path, try without leading slash
    if (!file_exists($logoFullPath) && strpos($receiptLogoPath, '/') !== 0) {
        $logoFullPath = APP_PATH . '/' . $receiptLogoPath;
    }
    // Only use logo if file actually exists
    if (!file_exists($logoFullPath)) {
        $receiptLogoPath = '';
    }
}
$showLogo = !empty($receiptLogoPath);

// Use TCPDF for PDF generation
require_once APP_PATH . '/vendor/autoload.php';

// Create PDF (Portrait, mm, A4)
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('ELECTROX-POS');
$pdf->SetAuthor($companyName);
$pdf->SetTitle('Shift Report - ' . date('Y-m-d', strtotime($shift['opened_at'])));
$pdf->SetSubject('Shift Report');

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
if ($showLogo && $receiptLogoPath) {
    $logoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
    if (file_exists($logoFullPath)) {
        $logoPath = realpath($logoFullPath);
        $logoHeight = 25;
    }
}

// Start Y position for header
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

// Report title on right
$rightStartY = $logoBottomY + 3;
$pdf->SetXY(110, $rightStartY);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 6, 'SHIFT STATUS REPORT', 0, 1, 'R');
if ($companyTagline) {
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 4, htmlspecialchars($companyTagline), 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);
}
$rightSectionEndY = $pdf->GetY();

// Move to the lower of left or right section
$nextY = max($leftSectionEndY, $rightSectionEndY);
$pdf->SetY($nextY);

// Horizontal line
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(8);

// Report Meta Section
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(95, 5, 'Shop name: ' . htmlspecialchars($shift['branch_name'] ?? 'N/A'), 0, 0, 'L');
$pdf->Cell(0, 5, 'Terminal name: POS 01', 0, 1, 'R');
$pdf->Cell(95, 5, 'Shift opened by: ' . htmlspecialchars(trim(($shift['opened_first'] ?? '') . ' ' . ($shift['opened_last'] ?? ''))), 0, 0, 'L');
$pdf->Cell(0, 5, 'Date: ' . date('d/m/Y H:i', strtotime($shift['opened_at'])), 0, 1, 'R');
if ($shift['closed_at']) {
    $pdf->Cell(95, 5, 'Shift closed by: ' . htmlspecialchars(trim(($shift['closed_first'] ?? '') . ' ' . ($shift['closed_last'] ?? ''))), 0, 0, 'L');
    $pdf->Cell(0, 5, 'Closed: ' . date('d/m/Y H:i', strtotime($shift['closed_at'])), 0, 1, 'R');
}
$pdf->Ln(8);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(10);

// Cash Drawer Section
$pdf->SetFillColor(233, 236, 239);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 8, 'CASH DRAWER', 1, 1, 'L', true);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(248, 249, 250);

$pdf->Cell(95, 8, 'Starting cash:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency($shift['starting_cash']), 1, 1, 'R');

$pdf->Cell(95, 8, 'Cash sales:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency($cashSales['total']), 1, 1, 'R');

if ($cashReceived['total'] != $cashSales['total']) {
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(95, 6, '  Cash received:', 1, 0, 'L', true);
    $pdf->Cell(0, 6, formatCurrency($cashReceived['total']), 1, 1, 'R');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(0, 0, 0);
}

$pdf->Cell(95, 8, 'Advance payment:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency(0), 1, 1, 'R');

$pdf->Cell(95, 8, 'Cash credit settlements:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency(0), 1, 1, 'R');

$pdf->Cell(95, 8, 'Cash refund:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency(0), 1, 1, 'R');

$pdf->Cell(95, 8, 'Paid Out:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency($payOuts['total']), 1, 1, 'R');

if ($outstandingBorrowed > 0) {
    $pdf->SetFillColor(255, 243, 205);
    $pdf->SetTextColor(133, 100, 4);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(95, 8, '⚠ Outstanding Borrowed Cash:', 1, 0, 'L', true);
    $pdf->Cell(0, 8, formatCurrency($outstandingBorrowed), 1, 1, 'R');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(133, 100, 4);
    $pdf->Cell(0, 6, 'This amount was borrowed from outside the drawer to give change and needs to be repaid via "Pay In" transaction.', 1, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 9);
}

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(248, 249, 250);
$pdf->Cell(95, 8, 'Expected cash amount:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency($shift['expected_cash']), 1, 1, 'R');

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(95, 8, 'Gross sales:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency($totalSales['total']), 1, 1, 'R');

$pdf->Cell(95, 8, 'Refunds:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency(0), 1, 1, 'R');

$pdf->Cell(95, 8, 'Discounts:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency($totalSales['discount']), 1, 1, 'R');

$pdf->Cell(95, 8, 'Net sales:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency($totalSales['total'] - $totalSales['discount']), 1, 1, 'R');

$pdf->Cell(95, 8, 'Taxes:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency($totalSales['tax']), 1, 1, 'R');

$pdf->Cell(95, 8, 'Total tendered:', 1, 0, 'L', true);
$pdf->Cell(0, 8, formatCurrency($totalSales['total']), 1, 1, 'R');

$pdf->Ln(10);

// Payment Type Wise Sale
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(233, 236, 239);
$pdf->Cell(0, 8, 'PAYMENT TYPE WISE SALE', 1, 1, 'L', true);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(248, 249, 250);

$paymentTotals = [];
foreach ($paymentTypes as $payment) {
    $method = $payment['payment_method'];
    if (!isset($paymentTotals[$method])) {
        $paymentTotals[$method] = 0;
    }
    $paymentTotals[$method] += floatval($payment['total_base'] ?? $payment['total']);
}

foreach ($paymentTotals as $method => $total) {
    $pdf->Cell(95, 8, ucfirst($method) . ':', 1, 0, 'L', true);
    $pdf->Cell(0, 8, formatCurrency($total), 1, 1, 'R');
}

$pdf->Ln(10);

// Currency Breakdown
if (!empty($currencyBreakdown)) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(233, 236, 239);
    $pdf->Cell(0, 8, 'CURRENCY BREAKDOWN', 1, 1, 'L', true);
    
    $pdf->SetFillColor(30, 58, 138);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(50, 8, 'Currency', 1, 0, 'L', true);
    $pdf->Cell(30, 8, 'Transactions', 1, 0, 'C', true);
    $pdf->Cell(50, 8, 'Amount (Original)', 1, 0, 'R', true);
    $pdf->Cell(55, 8, 'Amount (Base)', 1, 1, 'R', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetFillColor(255, 255, 255);
    
    foreach ($currencyBreakdown as $breakdown) {
        $currency = $breakdown['currency'];
        $pdf->Cell(50, 7, htmlspecialchars($currency['code'] . ' - ' . $currency['name']), 1, 0, 'L');
        $pdf->Cell(30, 7, number_format($breakdown['transaction_count']), 1, 0, 'C');
        $pdf->Cell(50, 7, formatCurrencyAmount($breakdown['total_original'], $currency['id'], $db), 1, 0, 'R');
        $pdf->Cell(55, 7, formatCurrencyAmount($breakdown['total_base'], $baseCurrencyId, $db), 1, 1, 'R');
    }
    
    $pdf->Ln(10);
}

// Payment Method & Currency Split
if (!empty($paymentMethodCurrencyBreakdown)) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(233, 236, 239);
    $pdf->Cell(0, 8, 'PAYMENT METHOD & CURRENCY SPLIT', 1, 1, 'L', true);
    
    $pdf->SetFillColor(30, 58, 138);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(50, 8, 'Payment Method', 1, 0, 'L', true);
    $pdf->Cell(30, 8, 'Currency', 1, 0, 'C', true);
    $pdf->Cell(50, 8, 'Amount (Original)', 1, 0, 'R', true);
    $pdf->Cell(55, 8, 'Amount (Base)', 1, 1, 'R', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetFillColor(255, 255, 255);
    
    foreach ($paymentMethodCurrencyBreakdown as $breakdown) {
        $currencyId = $breakdown['currency_id'] ?? null;
        if (!$currencyId || $currencyId == 0) {
            $currencyId = $baseCurrencyId;
        }
        
        $currency = getCurrency($currencyId, null);
        if (!$currency && $baseCurrency) {
            $currency = $baseCurrency;
            $currencyId = $baseCurrencyId;
        }
        
        $currencyCode = $currency ? $currency['code'] : ($baseCurrency ? $baseCurrency['code'] : 'USD');
        
        $pdf->Cell(50, 7, htmlspecialchars(ucfirst($breakdown['payment_method'])), 1, 0, 'L');
        $pdf->Cell(30, 7, htmlspecialchars($currencyCode), 1, 0, 'C');
        $pdf->Cell(50, 7, formatCurrencyAmount($breakdown['total_original'] ?? 0, $currencyId, $db), 1, 0, 'R');
        $pdf->Cell(55, 7, formatCurrencyAmount($breakdown['total_base'] ?? 0, $baseCurrencyId, $db), 1, 1, 'R');
    }
    
    $pdf->Ln(10);
}

// Footer
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, 'Generated on ' . date('d/m/Y H:i:s'), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

// Output PDF
$filename = 'Shift_Report_' . date('Ymd_His', strtotime($shift['opened_at'])) . '.pdf';
$pdf->Output($filename, 'I');
exit;


