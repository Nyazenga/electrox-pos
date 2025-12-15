<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('invoices.view');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    redirectTo('modules/invoicing/index.php');
}

$db = Database::getInstance();
$invoice = $db->getRow("SELECT i.*, c.first_name, c.last_name, c.company_name, c.email, c.phone, c.address, c.tin as customer_tin, c.vat_number as customer_vat, b.branch_name, b.address as branch_address, b.phone as branch_phone, u.first_name as sales_rep_first, u.last_name as sales_rep_last FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id LEFT JOIN branches b ON i.branch_id = b.id LEFT JOIN users u ON i.user_id = u.id WHERE i.id = :id", [':id' => $id]);

if (!$invoice) {
    redirectTo('modules/invoicing/index.php');
}

$invoiceItems = $db->getRows("SELECT ii.*, p.brand, p.model FROM invoice_items ii LEFT JOIN products p ON ii.product_id = p.id WHERE ii.invoice_id = :id ORDER BY ii.id", [':id' => $id]);
if ($invoiceItems === false) $invoiceItems = [];

// Get company settings
$companyName = getSetting('company_name', SYSTEM_NAME);
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');
$companyTIN = getSetting('company_tin', '');
$companyVAT = getSetting('company_vat_number', '');
$bankName = getSetting('company_bank_name', '');
$bankAccount = getSetting('company_bank_account', '');
$bankBranch = getSetting('company_bank_branch', '');
$taxRate = floatval(getSetting('default_tax_rate', 15));
$companyTagline = getSetting('company_tagline', 'Transforming Your Tomorrow');

// Get invoice customizations
$invoiceLogo = getSetting('invoice_logo', getSetting('company_logo', ''));
$invoicePrimaryColor = getSetting('invoice_primary_color', '#1e3a8a');
// Normalize logo path - ensure it's relative to APP_PATH
if ($invoiceLogo && !empty($invoiceLogo)) {
    $logoFullPath = APP_PATH . '/' . ltrim($invoiceLogo, '/');
    // If file doesn't exist at the stored path, try without leading slash
    if (!file_exists($logoFullPath) && strpos($invoiceLogo, '/') !== 0) {
        $logoFullPath = APP_PATH . '/' . $invoiceLogo;
    }
    // Only use logo if file actually exists
    if (!file_exists($logoFullPath)) {
        $invoiceLogo = '';
    }
}
$showLogo = getSetting('invoice_show_logo', '1') == '1' && !empty($invoiceLogo);
$showTaxId = getSetting('invoice_show_tax_id', '1') == '1';
$defaultTerms = getSetting('invoice_default_terms', '');
$invoiceFooterText = getSetting('invoice_footer_text', 'Thank you for your business!');

// Calculate VAT breakdown for items
$totalExclVAT = 0;
$totalVAT = 0;
$totalInclVAT = 0;

// Initialize variables
$clientName = $invoice['company_name'] ?? trim(($invoice['first_name'] ?? '') . ' ' . ($invoice['last_name'] ?? 'Walk-in Customer'));
$salesRep = trim(($invoice['sales_rep_first'] ?? '') . ' ' . ($invoice['sales_rep_last'] ?? ''));
$termsText = $invoice['terms'] ?: $defaultTerms;

foreach ($invoiceItems as &$item) {
    $unitPrice = floatval($item['unit_price'] ?? 0);
    $quantity = intval($item['quantity'] ?? 1);
    $discountPct = floatval($item['discount_percentage'] ?? 0);
    
    // Calculate excluding VAT first
    $lineSubtotal = $unitPrice * $quantity;
    $lineDiscount = $lineSubtotal * ($discountPct / 100);
    $lineNet = $lineSubtotal - $lineDiscount;
    
    // Calculate VAT on the net amount
    $lineVAT = $lineNet * ($taxRate / 100);
    $lineTotalInclVAT = $lineNet + $lineVAT;
    
    // Unit price excluding VAT
    $item['unit_price_excl_vat'] = $lineNet / $quantity;
    $item['line_total_excl_vat'] = $lineNet;
    $item['line_vat'] = $lineVAT;
    $item['line_total_incl_vat'] = $lineTotalInclVAT;
    
    $totalExclVAT += $lineNet;
    $totalVAT += $lineVAT;
    $totalInclVAT += $lineTotalInclVAT;
}
unset($item);

// Check if we should use TCPDF (for print) or HTML (for screen)
$usePDF = isset($_GET['pdf']) || (isset($_GET['print']) && $_GET['print'] == '1');

if ($usePDF) {
    // Use TCPDF for PDF generation
    require_once APP_PATH . '/vendor/autoload.php';
    
    // Create PDF (Portrait, mm, A4)
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('ELECTROX-POS');
    $pdf->SetAuthor($companyName);
    $pdf->SetTitle('Invoice ' . $invoice['invoice_number']);
    $pdf->SetSubject('Invoice');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins - proper spacing
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 20);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Get logo path for TCPDF Image() method
    $logoPath = '';
    $logoHeight = 0;
    if ($showLogo && $invoiceLogo) {
        $logoFullPath = APP_PATH . '/' . ltrim($invoiceLogo, '/');
        if (file_exists($logoFullPath)) {
            $logoPath = realpath($logoFullPath);
            $logoHeight = 25; // Fixed logo height in mm
        }
    }
    
    // Start Y position for header
    $startY = 15;
    $pdf->SetY($startY);
    
    // Logo on right (if exists) - positioned at top right within margins
    $logoBottomY = $startY;
    if ($logoPath) {
        $logoWidth = 45; // Slightly smaller width
        $logoX = 195 - 5 - $logoWidth; // Right margin (5mm) - logo width (moved further right)
        $logoY = $startY;
        try {
            $pdf->Image($logoPath, $logoX, $logoY, $logoWidth, $logoHeight, '', '', '', false, 300, '', false, false, 0);
            $logoBottomY = $logoY + $logoHeight;
        } catch (Exception $e) {
            error_log("Failed to add logo to PDF: " . $e->getMessage());
            $logoPath = ''; // Clear logo path if failed
        }
    }
    
    // Company name on left
    $pdf->SetXY(15, $startY);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(95, 8, htmlspecialchars($companyName), 0, 1, 'L');
    
    // Company address on left
    $pdf->SetFont('helvetica', '', 9);
    $pdf->MultiCell(95, 5, htmlspecialchars($companyAddress), 0, 'L', false, 0);
    
    // Contact info - position below logo area to avoid overlap
    $contactStartY = max($pdf->GetY(), $logoBottomY + 3);
    $pdf->SetXY(15, $contactStartY);
    if ($companyPhone) {
        $pdf->Cell(95, 5, 'Contact Number: ' . htmlspecialchars($companyPhone), 0, 1, 'L');
    }
    if ($companyEmail) {
        $pdf->Cell(95, 5, 'Email: ' . htmlspecialchars($companyEmail), 0, 1, 'L');
    }
    $leftSectionEndY = $pdf->GetY();
    
    // Invoice type and tagline on right - below logo
    $rightStartY = $logoBottomY + 3;
    $pdf->SetXY(110, $rightStartY);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 6, strtoupper($invoice['invoice_type']) . ' INVOICE (USD)', 0, 1, 'R');
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
    
    // Header HTML for compatibility (not used in PDF but kept for structure)
    $headerHtml = '';
    
    // Invoice Meta Section
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(95, 5, '', 0, 0, 'L'); // Spacer
    $pdf->Cell(0, 5, 'Date: ' . date('d/m/Y', strtotime($invoice['invoice_date'])), 0, 1, 'R');
    $pdf->Cell(95, 5, '', 0, 0, 'L'); // Spacer
    $pdf->Cell(0, 5, 'Invoice Ref #: ' . htmlspecialchars($invoice['invoice_number']), 0, 1, 'R');
    if ($showTaxId && $companyTIN) {
        $pdf->Cell(95, 5, '', 0, 0, 'L'); // Spacer
        $pdf->Cell(0, 5, 'TIN #: ' . htmlspecialchars($companyTIN), 0, 1, 'R');
    }
    if ($showTaxId && $companyVAT) {
        $pdf->Cell(95, 5, '', 0, 0, 'L'); // Spacer
        $pdf->Cell(0, 5, 'VAT No.: ' . htmlspecialchars($companyVAT), 0, 1, 'R');
    }
    $pdf->Ln(8);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(10);
    
    // Meta HTML for compatibility
    $metaHtml = '';
    
    // Client Details Section
    $pdf->SetFillColor(233, 236, 239);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'CLIENT DETAILS', 1, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(248, 249, 250);
    
    // Client Name
    $pdf->Cell(95, 8, 'Client Name: ' . htmlspecialchars($clientName), 1, 0, 'L', true);
    $pdf->Cell(0, 8, '', 1, 1, 'L', true);
    
    // Address (if available)
    if (!empty($invoice['address'])) {
        $addressLines = explode("\n", $invoice['address']);
        foreach ($addressLines as $line) {
            $line = trim($line);
            if ($line) {
                $pdf->Cell(0, 8, 'Address: ' . htmlspecialchars($line), 1, 1, 'L', true);
            }
        }
    }
    
    // Phone and Email in one row
    $contactInfo = '';
    if (!empty($invoice['phone'])) {
        $contactInfo .= 'Phone: ' . htmlspecialchars($invoice['phone']);
    }
    if (!empty($invoice['email'])) {
        if ($contactInfo) $contactInfo .= ' | ';
        $contactInfo .= 'Email: ' . htmlspecialchars($invoice['email']);
    }
    if ($contactInfo) {
        $pdf->Cell(0, 8, $contactInfo, 1, 1, 'L', true);
    }
    
    // Tax Information
    if ($invoice['customer_tin'] || $invoice['customer_vat']) {
        $clientTaxInfo = '';
        if ($invoice['customer_tin']) {
            $clientTaxInfo .= 'Client TIN #: ' . htmlspecialchars($invoice['customer_tin']);
        }
        if ($invoice['customer_vat']) {
            if ($clientTaxInfo) $clientTaxInfo .= ' | ';
            $clientTaxInfo .= 'Client VAT No.: ' . htmlspecialchars($invoice['customer_vat']);
        }
        $pdf->Cell(0, 8, $clientTaxInfo, 1, 1, 'L', true);
    }
    
    // Sales Rep
    if ($salesRep) {
        $pdf->Cell(0, 8, 'Sales Rep: ' . htmlspecialchars($salesRep), 1, 1, 'L', true);
    }
    $pdf->Ln(8);
    
    // Client HTML for compatibility
    $clientHtml = '';
    
    // Items Table Header
    $pdf->SetFillColor(30, 58, 138); // Dark blue
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(63, 10, 'Description', 1, 0, 'L', true);
    $pdf->Cell(18, 10, 'Quantity', 1, 0, 'C', true);
    $pdf->Cell(27, 10, 'Unit Price', 1, 0, 'R', true);
    $pdf->Cell(22, 10, 'VAT', 1, 0, 'R', true);
    $pdf->Cell(25, 10, 'Total (Incl)', 1, 0, 'R', true);
    $pdf->Cell(25, 10, 'Total (Excl)', 1, 1, 'R', true);
    
    // Items Table Rows
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);
    
    foreach ($invoiceItems as $item) {
        $description = $item['product_id'] 
            ? trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''))
            : ($item['description'] ?? '');
        
        // Get starting position
        $startX = $pdf->GetX();
        $startY = $pdf->GetY();
        
        // Fixed row height - no wrapping for short descriptions
        $lineHeight = 6; // Height per line in mm
        $minHeight = 8; // Minimum row height
        
        // Check text width to see if it fits in one line
        $pdf->SetFont('helvetica', '', 9);
        $textWidth = $pdf->GetStringWidth($description);
        $cellWidth = 63; // Description cell width in mm
        
        // If text fits in one line, use single line; otherwise wrap
        if ($textWidth <= $cellWidth) {
            // Single line - use fixed height
            $actualRowHeight = $minHeight;
            
            // Draw description as single cell
            $pdf->Cell(63, $actualRowHeight, htmlspecialchars($description), 1, 0, 'L');
        } else {
            // Multi-line - measure and draw
            $testY = $pdf->GetY();
            $pdf->MultiCell(63, $lineHeight, htmlspecialchars($description), 0, 'L', false, 0);
            $measuredHeight = $pdf->GetY() - $testY;
            $actualRowHeight = max($minHeight, $measuredHeight);
            
            // Reset and draw with border
            $pdf->SetXY($startX, $startY);
            $pdf->MultiCell(63, $lineHeight, htmlspecialchars($description), 1, 'L', false, 0);
            
            // Ensure we're at the right height
            $descEndY = $pdf->GetY();
            if ($descEndY < $startY + $actualRowHeight) {
                $pdf->SetY($startY + $actualRowHeight);
            }
        }
        
        // Position for other cells - align to start Y
        $pdf->SetXY($startX + 63, $startY);
        
        // Draw all other cells with same height - center text vertically
        $pdf->Cell(18, $actualRowHeight, $item['quantity'], 1, 0, 'C');
        $pdf->Cell(27, $actualRowHeight, number_format($item['unit_price_excl_vat'], 2), 1, 0, 'R');
        $pdf->Cell(22, $actualRowHeight, number_format($item['line_vat'], 2), 1, 0, 'R');
        $pdf->Cell(25, $actualRowHeight, number_format($item['line_total_incl_vat'], 2), 1, 0, 'R');
        $pdf->Cell(25, $actualRowHeight, number_format($item['line_total_excl_vat'], 2), 1, 1, 'R');
        
        // Move to next row
        $pdf->SetY($startY + $actualRowHeight);
    }
    
    $pdf->Ln(10);
    
    // Items HTML for compatibility
    $itemsHtml = '';
    
    // Summary Section
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(126, 0, '', 0, 0); // Spacer
    $pdf->Cell(54, 8, 'Subtotal (Excl VAT):', 1, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(0, 8, 'USD ' . number_format($totalExclVAT, 2), 1, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(126, 0, '', 0, 0); // Spacer
    $pdf->Cell(54, 8, 'VAT (' . $taxRate . '%):', 1, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(0, 8, 'USD ' . number_format($totalVAT, 2), 1, 1, 'R');
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(126, 0, '', 0, 0); // Spacer
    $pdf->Cell(54, 10, 'Total (Incl VAT):', 1, 0, 'L');
    $pdf->Cell(0, 10, 'USD ' . number_format($totalInclVAT, 2), 1, 1, 'R');
    
    $pdf->Ln(12);
    
    // Summary HTML for compatibility
    $summaryHtml = '';
    
    // Terms & Conditions
    if ($termsText) {
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(8);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(0, 5, 'Terms & Conditions:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell(0, 4, htmlspecialchars($termsText), 0, 'L');
        $pdf->Ln(8);
    }
    
    // Banking Details
    if ($bankName && $bankAccount) {
        $pdf->SetFillColor(233, 236, 239);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 8, 'Nostro Banking Details', 1, 1, 'L', true);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetFillColor(248, 249, 250);
        $pdf->Cell(45, 8, 'Company:', 1, 0, 'L', true);
        $pdf->Cell(45, 8, htmlspecialchars($companyName), 1, 0, 'L');
        $pdf->Cell(45, 8, 'Account No.:', 1, 0, 'L', true);
        $pdf->Cell(0, 8, htmlspecialchars($bankAccount), 1, 1, 'L');
        $pdf->Cell(45, 8, 'Bank Name:', 1, 0, 'L', true);
        $pdf->Cell(45, 8, htmlspecialchars($bankName), 1, 0, 'L');
        if ($bankBranch) {
            $pdf->Cell(45, 8, 'Branch:', 1, 0, 'L', true);
            $pdf->Cell(0, 8, htmlspecialchars($bankBranch), 1, 1, 'L');
        } else {
            $pdf->Cell(0, 8, '', 1, 1, 'L');
        }
        $pdf->Ln(8);
    }
    
    // Footer - Position at bottom of page
    if ($invoiceFooterText) {
        // Calculate position for footer at bottom (A4 height is 297mm, bottom margin ~15mm)
        $pageHeight = 297;
        $bottomMargin = 15;
        $footerY = $pageHeight - $bottomMargin;
        
        // If current position is already below footer position, use current + spacing
        $currentY = $pdf->GetY();
        if ($currentY < $footerY - 20) {
            // Move to footer position
            $pdf->SetY($footerY - 20);
        } else {
            // Add spacing if we're close to bottom
            $pdf->Ln(10);
        }
        
        // Draw horizontal line above footer
        $lineY = $pdf->GetY();
        $pdf->Line(15, $lineY, 195, $lineY);
        
        // Add spacing after line
        $pdf->Ln(8);
        
        // Footer text - centered
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 6, htmlspecialchars($invoiceFooterText), 0, 1, 'C');
    }
    
    // HTML variables for compatibility (not used in PDF)
    $termsHtml = '';
    $bankingHtml = '';
    $footerHtml = '';
    $html = '';
    
    // Output PDF
    $pdf->Output('Invoice_' . $invoice['invoice_number'] . '.pdf', 'I');
    exit;
} else {
    // HTML version for screen display
    $pageTitle = 'Invoice #' . escapeHtml($invoice['invoice_number']);
    require_once APP_PATH . '/includes/header.php';
    ?>
    
    <style>
        body { 
            font-family: Arial, sans-serif; 
            font-size: 11pt;
            color: #000;
            background: white;
        }
        
        .invoice-container {
            max-width: 210mm;
            width: 100%;
            margin: 0 auto;
            padding: 20px;
            background: white;
            border: 2px solid #000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #000;
        }
        
        .company-details-left {
            flex: 1;
        }
        
        .company-details-left h2 {
            margin: 0 0 8px 0;
            font-size: 20pt;
            font-weight: bold;
            color: #000;
        }
        
        .company-details-left p {
            margin: 4px 0;
            font-size: 10pt;
            line-height: 1.4;
        }
        
        .logo-section-right {
            text-align: right;
            flex: 1;
        }
        
        .company-logo {
            max-height: 80px;
            margin-bottom: 8px;
        }
        
        .company-tagline {
            font-size: 9pt;
            color: #666;
            margin-top: 5px;
        }
        
        .invoice-meta-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #000;
            font-size: 10pt;
        }
        
        .invoice-meta-right {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .invoice-meta-item {
            display: flex;
            justify-content: space-between;
        }
        
        .invoice-meta-label {
            font-weight: bold;
            margin-right: 10px;
        }
        
        .invoice-type-title {
            font-size: 18pt;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        
        .client-section {
            margin-bottom: 20px;
            padding: 12px;
            background: #f8f9fa;
            border: 1px solid #000;
        }
        
        .client-section h6 {
            margin: 0 0 10px 0;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 11pt;
        }
        
        .client-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 10pt;
            border: 1px solid #000;
        }
        
        table thead {
            background: <?= escapeHtml($invoicePrimaryColor) ?>;
            color: white;
            font-weight: bold;
        }
        
        /* Ensure table headers are always visible with proper styling */
        table thead th,
        table th {
            color: #ffffff !important;
            background-color: <?= escapeHtml($invoicePrimaryColor) ?> !important;
            padding: 10px 8px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #000;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        
        table td {
            padding: 8px;
            border: 1px solid #000;
        }
        
        table tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .text-end {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .summary-section {
            margin-top: 20px;
            margin-bottom: 20px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #000;
        }
        
        .summary-row.total {
            font-weight: bold;
            font-size: 12pt;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 10px 0;
            margin-top: 5px;
        }
        
        .terms-section {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #000;
            font-size: 9pt;
        }
        
        .terms-section h6 {
            margin-bottom: 8px;
            font-weight: bold;
        }
        
        .banking-section {
            margin-top: 20px;
            padding: 12px;
            background: #f8f9fa;
            border: 1px solid #000;
            font-size: 9pt;
        }
        
        .banking-section h6 {
            margin: 0 0 10px 0;
            font-weight: bold;
        }
        
        .banking-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        @page {
            size: A4;
            margin: 15mm;
        }
        
        @media print {
            .no-print,
            .sidebar,
            .topbar,
            header,
            footer,
            .navbar {
                display: none !important;
            }
            
            body { 
                margin: 0; 
                padding: 0; 
                background: white !important;
            }
            
            .content-area {
                display: block !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            .invoice-container { 
                padding: 0; 
                border: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                max-width: 100% !important;
            }
        }
    </style>
    
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <h2>Invoice #<?= escapeHtml($invoice['invoice_number']) ?></h2>
        <div>
            <button onclick="window.open('?id=<?= $id ?>&pdf=1', '_blank')" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Download PDF</button>
            <button onclick="window.open('?id=<?= $id ?>&pdf=1&print=1', '_blank')" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button>
            <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Invoices</a>
        </div>
    </div>
    
    <div class="invoice-container">
        <!-- Header Section - Logo on right, Company on left -->
        <div class="invoice-header">
            <div class="company-details-left">
                <h2><?= escapeHtml($companyName) ?></h2>
                <p><?= nl2br(escapeHtml($companyAddress)) ?></p>
                <?php if ($companyPhone): ?>
                    <p><strong>Contact Number:</strong> <?= escapeHtml($companyPhone) ?></p>
                <?php endif; ?>
                <?php if ($companyEmail): ?>
                    <p><strong>Email:</strong> <?= escapeHtml($companyEmail) ?></p>
                <?php endif; ?>
            </div>
            <div class="logo-section-right">
                <?php if ($showLogo && $invoiceLogo): 
                    $logoUrl = BASE_URL . ltrim($invoiceLogo, '/');
                    $logoFullPath = APP_PATH . '/' . ltrim($invoiceLogo, '/');
                    if (file_exists($logoFullPath)): ?>
                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="company-logo" onerror="this.style.display='none';">
                <?php endif; endif; ?>
                <div class="invoice-type-title">
                    <?= strtoupper($invoice['invoice_type']) ?> INVOICE (USD)
                </div>
                <?php if ($companyTagline): ?>
                    <div class="company-tagline"><?= escapeHtml($companyTagline) ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Invoice Meta Information - Date, Ref, TIN, VAT on right -->
        <div class="invoice-meta-section">
            <div class="invoice-meta-left">
                <!-- Empty for spacing -->
            </div>
            <div class="invoice-meta-right">
                <div class="invoice-meta-item">
                    <span class="invoice-meta-label">Date:</span>
                    <span><?= date('d/m/Y', strtotime($invoice['invoice_date'])) ?></span>
                </div>
                <div class="invoice-meta-item">
                    <span class="invoice-meta-label">Invoice Ref #:</span>
                    <span><?= escapeHtml($invoice['invoice_number']) ?></span>
                </div>
                <?php if ($showTaxId && $companyTIN): ?>
                <div class="invoice-meta-item">
                    <span class="invoice-meta-label">TIN #:</span>
                    <span><?= escapeHtml($companyTIN) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($showTaxId && $companyVAT): ?>
                <div class="invoice-meta-item">
                    <span class="invoice-meta-label">VAT No.:</span>
                    <span><?= escapeHtml($companyVAT) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Client Section -->
        <div class="client-section">
            <h6>Client Details</h6>
            <div class="client-details-grid">
                <div>
                    <strong>Client Name:</strong> <?= escapeHtml($clientName) ?><br>
                    <?php if (!empty($invoice['address'])): ?>
                        <strong>Address:</strong> <?= nl2br(escapeHtml($invoice['address'])) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($invoice['phone'])): ?>
                        <strong>Phone:</strong> <?= escapeHtml($invoice['phone']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($invoice['email'])): ?>
                        <strong>Email:</strong> <?= escapeHtml($invoice['email']) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($invoice['customer_tin']): ?>
                        <strong>Client TIN #:</strong> <?= escapeHtml($invoice['customer_tin']) ?><br>
                    <?php endif; ?>
                    <?php if ($invoice['customer_vat']): ?>
                        <strong>Client VAT No.:</strong> <?= escapeHtml($invoice['customer_vat']) ?><br>
                    <?php endif; ?>
                    <?php if ($salesRep): ?>
                        <strong>Sales Rep:</strong> <?= escapeHtml($salesRep) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Items Table -->
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-center">Quantity</th>
                    <th class="text-end">Unit Price (Excl VAT)</th>
                    <th class="text-end">VAT</th>
                    <th class="text-end">Total (Incl VAT)</th>
                    <th class="text-end">Total (Excl VAT)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoiceItems as $item): ?>
                    <tr>
                        <td>
                            <?php if ($item['product_id']): ?>
                                <?= escapeHtml(trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''))) ?>
                            <?php else: ?>
                                <?= escapeHtml($item['description'] ?? '') ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= $item['quantity'] ?></td>
                        <td class="text-end"><?= number_format($item['unit_price_excl_vat'], 2) ?></td>
                        <td class="text-end"><?= number_format($item['line_vat'], 2) ?></td>
                        <td class="text-end"><?= number_format($item['line_total_incl_vat'], 2) ?></td>
                        <td class="text-end"><?= number_format($item['line_total_excl_vat'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Summary Section -->
        <div class="summary-section">
            <div class="summary-row">
                <span><strong>Subtotal (Excl VAT):</strong></span>
                <span><strong>USD <?= number_format($totalExclVAT, 2) ?></strong></span>
            </div>
            <div class="summary-row">
                <span><strong>VAT (<?= $taxRate ?>%):</strong></span>
                <span><strong>USD <?= number_format($totalVAT, 2) ?></strong></span>
            </div>
            <div class="summary-row total">
                <span><strong>Total (Incl VAT):</strong></span>
                <span><strong>USD <?= number_format($totalInclVAT, 2) ?></strong></span>
            </div>
        </div>
        
        <!-- Terms & Conditions -->
        <?php if ($termsText): ?>
        <div class="terms-section">
            <h6>Terms & Conditions:</h6>
            <?= nl2br(escapeHtml($termsText)) ?>
        </div>
        <?php endif; ?>
        
        <!-- Banking Details -->
        <?php if ($bankName && $bankAccount): ?>
        <div class="banking-section">
            <h6>Nostro Banking Details</h6>
            <div class="banking-grid">
                <div><strong>Company:</strong> <?= escapeHtml($companyName) ?></div>
                <div><strong>Account No.:</strong> <?= escapeHtml($bankAccount) ?></div>
                <div><strong>Bank:</strong> <?= escapeHtml($bankName) ?></div>
                <?php if ($bankBranch): ?>
                <div><strong>Branch:</strong> <?= escapeHtml($bankBranch) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Footer Text -->
        <?php if ($invoiceFooterText): ?>
        <div style="margin-top: 20px; text-align: center; font-size: 9pt; padding-top: 15px; border-top: 1px solid #000;">
            <?= nl2br(escapeHtml($invoiceFooterText)) ?>
        </div>
        <?php endif; ?>
    </div>
    
    <?php require_once APP_PATH . '/includes/footer.php'; ?>
<?php } ?>
