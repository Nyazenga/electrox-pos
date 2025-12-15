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

// Get base currency for display
$baseCurrency = getBaseCurrency($db);

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
    
    // Summary Section
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(100, 0, '', 0, 0);
    $pdf->Cell(55, 8, 'Subtotal:', 1, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(35, 8, number_format($sale['subtotal'], 2), 1, 1, 'R');
    
    if ($sale['discount_amount'] > 0) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(100, 0, '', 0, 0);
        $pdf->Cell(55, 8, 'Discount:', 1, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(35, 8, '-' . number_format($sale['discount_amount'], 2), 1, 1, 'R');
    }
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(100, 0, '', 0, 0);
    $pdf->Cell(55, 10, 'TOTAL:', 1, 0, 'L');
    $pdf->Cell(35, 10, number_format($sale['total_amount'], 2), 1, 1, 'R');
    
    $pdf->Ln(12);
    
    // Payment Information
    if (!empty($payments)) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'Payment:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        
        $totalPaid = 0;
        foreach ($payments as $payment) {
            $amount = floatval($payment['base_amount']);
            $totalPaid += $amount;
            
            $currencySymbol = $payment['currency_symbol'] ?? '$';
            $currencyCode = $payment['currency_code'] ?? 'USD';
            $symbolPosition = $payment['currency_symbol_position'] ?? 'before';
            
            $paymentMethod = ucfirst($payment['payment_method']);
            if ($symbolPosition === 'before') {
                $amountStr = $currencySymbol . ' ' . number_format($amount, 2);
            } else {
                $amountStr = number_format($amount, 2) . ' ' . $currencySymbol;
            }
            
            $pdf->Cell(0, 5, $paymentMethod . ': ' . $amountStr, 0, 1, 'L');
        }
        
        // Calculate change
        $change = $totalPaid - $sale['total_amount'];
        if ($change > 0) {
            $pdf->Ln(3);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, 'Change: ' . number_format($change, 2), 0, 1, 'L');
        }
    }
    
    $pdf->Ln(12);
    
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
        /* Screen view */
        .receipt-container {
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .content-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 30px;
        }
        
        .receipt-container {
            margin-top: 0;
        }
        
        /* Print styles for POS printer (80mm/3 inch width) */
        @media print {
            @page {
                size: 80mm auto;
                margin: 0;
            }
            
            body {
                margin: 0;
                padding: 0;
                font-size: 12px;
                background: white !important;
            }
            
            .no-print,
            .sidebar,
            .topbar,
            header,
            footer,
            .no-print * {
                display: none !important;
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
                visibility: visible !important;
            }
            
            .receipt-container * {
                visibility: visible !important;
                display: block !important;
            }
            
            .receipt-container table {
                width: 100% !important;
                border-collapse: collapse !important;
                table-layout: fixed !important;
            }
            
            .receipt-container table,
            .receipt-container table *,
            .receipt-container tr,
            .receipt-container td,
            .receipt-container th,
            .receipt-container thead,
            .receipt-container tbody,
            .receipt-container tfoot {
                display: table !important;
                visibility: visible !important;
            }
            
            .receipt-container tr {
                display: table-row !important;
            }
            
            .receipt-container td,
            .receipt-container th {
                display: table-cell !important;
                visibility: visible !important;
                padding: 6px 4px !important;
            }
            
            .receipt-container thead th:nth-child(1) {
                text-align: left !important;
            }
            
            .receipt-container thead th:nth-child(2) {
                text-align: center !important;
            }
            
            .receipt-container thead th:nth-child(3),
            .receipt-container thead th:nth-child(4) {
                text-align: right !important;
            }
            
            .receipt-container tbody td:nth-child(1) {
                text-align: left !important;
            }
            
            .receipt-container tbody td:nth-child(2) {
                text-align: center !important;
            }
            
            .receipt-container tbody td:nth-child(3),
            .receipt-container tbody td:nth-child(4) {
                text-align: right !important;
            }
            
            .receipt-container tfoot td {
                text-align: right !important;
            }
            
            .receipt-container tfoot td[colspan="3"] {
                text-align: right !important;
            }
            
            .receipt-container tfoot td:first-child,
            .receipt-container tfoot td:nth-child(2) {
                text-align: left !important;
            }
            
            .receipt-container tfoot td:nth-child(3) {
                text-align: right !important;
            }
            
            .receipt-header h2 {
                font-size: 18px;
            }
            
            .receipt-info {
                font-size: 10px;
            }
            
            table {
                font-size: 10px;
            }
            
            table th, table td {
                padding: 4px 2px;
            }
            
            .total-row {
                font-size: 12px;
            }
            
            .receipt-footer {
                font-size: 10px;
            }
        }
        
        body {
            font-family: 'Courier New', monospace;
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
        }
        
        @media print {
            .receipt-header {
                border-bottom: 1px solid #000;
                padding-bottom: 8px;
                margin-bottom: 8px;
            }
            
            .receipt-header img {
                max-width: 150px !important;
                max-height: 60px !important;
                margin-bottom: 8px !important;
            }
        }
        
        .receipt-header h2 {
            margin: 0 0 8px 0;
            color: #1e3a8a;
            font-size: 20px;
        }
        
        @media print {
            .receipt-header h2 {
                color: #000;
                font-size: 16px;
            }
        }
        
        .receipt-header .company-info {
            font-size: 10px;
            line-height: 1.4;
        }
        
        @media print {
            .receipt-header .company-info {
                font-size: 9px;
            }
        }
        
        .receipt-info {
            margin: 12px 0;
            font-size: 11px;
            line-height: 1.6;
        }
        
        @media print {
            .receipt-info {
                margin: 8px 0;
                font-size: 9px;
            }
        }
        
        .receipt-info div {
            margin-bottom: 4px;
        }
        
        .receipt-info strong {
            display: inline-block;
            min-width: 80px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            font-size: 11px;
        }
        
        @media print {
            table {
                margin: 8px 0;
                font-size: 9px;
            }
        }
        
        table th, table td {
            padding: 6px 4px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }
        
        table th {
            text-align: left;
        }
        
        table td:first-child {
            text-align: left;
        }
        
        @media print {
            table th, table td {
                padding: 3px 2px;
                border-bottom: 1px dashed #ccc;
                vertical-align: top;
            }
        }
        
        table th {
            background: #f3f4f6;
            font-weight: bold;
            text-align: left;
        }
        
        @media print {
            table th {
                background: transparent;
                border-bottom: 1px solid #000;
            }
        }
        
        table tbody td {
            font-size: 10px;
            vertical-align: middle;
        }
        
        @media print {
            table tbody td {
                font-size: 9px;
                vertical-align: middle;
            }
        }
        
        /* Ensure proper alignment for all columns */
        /* Ensure proper column alignment */
        table th:nth-child(1),
        table td:nth-child(1) {
            text-align: left !important;
            width: auto;
            padding-left: 4px;
        }
        
        table th:nth-child(2),
        table td:nth-child(2) {
            text-align: center !important;
            width: 50px;
            padding: 6px 4px;
        }
        
        table th:nth-child(3),
        table td:nth-child(3) {
            text-align: right !important;
            width: 80px;
            padding-right: 4px;
        }
        
        table th:nth-child(4),
        table td:nth-child(4) {
            text-align: right !important;
            width: 80px;
            padding-right: 4px;
        }
        
        /* Ensure tfoot rows align properly */
        table tfoot td {
            text-align: right !important;
            padding-right: 4px;
        }
        
        table tfoot td:first-child {
            text-align: left !important;
            padding-left: 4px;
        }
        
        table tfoot td[colspan="3"] {
            text-align: right !important;
            padding-right: 4px;
        }
        
        .total-row {
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid #1e3a8a;
        }
        
        @media print {
            .total-row {
                font-size: 11px;
                border-top: 1px solid #000;
            }
        }
        
        .receipt-footer {
            text-align: center;
            margin-top: 15px;
            padding-top: 12px;
            border-top: 2px solid #1e3a8a;
            font-size: 11px;
        }
        
        @media print {
            .receipt-footer {
                margin-top: 10px;
                padding-top: 8px;
                border-top: 1px solid #000;
                font-size: 9px;
            }
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .content-area {
                padding: 15px;
            }
            
            .receipt-container {
                max-width: 100%;
                padding: 20px 15px;
            }
        }
    </style>

<div class="content-area">
    <!-- Action Buttons - Top -->
    <div class="no-print mb-4" style="text-align: center; padding: 20px 0;">
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
    
    <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
        <colgroup>
            <col style="width: auto;">
            <col style="width: 50px;">
            <col style="width: 80px;">
            <col style="width: 80px;">
        </colgroup>
        <thead>
            <tr>
                <th style="text-align: left; padding: 6px 4px; border-bottom: 1px solid #ddd; width: auto;">Item</th>
                <th style="text-align: center; padding: 6px 4px; border-bottom: 1px solid #ddd; width: 50px; padding-left: 15px;">Qty</th>
                <th style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd; width: 80px;">Price</th>
                <th style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd; width: 80px;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td style="text-align: left; padding: 6px 4px; word-wrap: break-word; border-bottom: 1px solid #ddd;"><?= escapeHtml($item['product_name']) ?></td>
                    <td style="text-align: center; padding: 6px 4px; border-bottom: 1px solid #ddd;"><?= $item['quantity'] ?></td>
                    <td style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd;"><?= formatCurrency($item['unit_price']) ?></td>
                    <td style="text-align: right; padding: 6px 4px; border-bottom: 1px solid #ddd;"><?= formatCurrency($item['total_price']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td style="text-align: left; padding: 6px 4px;"><strong>Subtotal:</strong></td>
                <td style="text-align: center; padding: 6px 4px;"></td>
                <td style="text-align: right; padding: 6px 4px;"></td>
                <td style="text-align: right; padding: 6px 4px;"><strong><?= formatCurrency($sale['subtotal']) ?></strong></td>
            </tr>
            <?php if ($sale['discount_amount'] > 0): ?>
                <tr>
                    <td style="text-align: left; padding: 6px 4px;"><strong>Discount:</strong></td>
                    <td style="text-align: center; padding: 6px 4px;"></td>
                    <td style="text-align: right; padding: 6px 4px;"></td>
                    <td style="text-align: right; padding: 6px 4px;"><strong>-<?= formatCurrency($sale['discount_amount']) ?></strong></td>
                </tr>
            <?php endif; ?>
            <tr class="total-row">
                <td style="text-align: left; padding: 6px 4px;"><strong>TOTAL:</strong></td>
                <td style="text-align: center; padding: 6px 4px;"></td>
                <td style="text-align: right; padding: 6px 4px;"></td>
                <td style="text-align: right; padding: 6px 4px;"><strong><?= formatCurrency($sale['total_amount']) ?></strong></td>
            </tr>
            <tr>
                <td colspan="4" style="padding-top: 8px;">
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
            // Calculate change if amount paid exceeds total (use base_amount for calculation)
            $change = $totalPaid - $sale['total_amount'];
            if ($change > 0): 
            ?>
                <tr>
                    <td style="text-align: left; padding: 6px 4px;"></td>
                    <td style="text-align: center; padding: 6px 4px;"></td>
                    <td style="text-align: right; padding: 6px 4px; padding-top: 8px;"><strong>Change:</strong></td>
                    <td style="text-align: right; padding: 6px 4px; padding-top: 8px;"><strong><?= formatCurrency($change) ?></strong></td>
                </tr>
            <?php endif; ?>
        </tfoot>
    </table>
    
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

