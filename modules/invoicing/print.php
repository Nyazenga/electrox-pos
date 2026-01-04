<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
// This page matches sidebar "All Invoices" menu item
$auth->requirePermission('invoicing.view');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    redirectTo('modules/invoicing/index.php');
}

$db = Database::getInstance();
$invoice = $db->getRow("SELECT i.*, c.first_name, c.last_name, c.company_name, c.email, c.phone, c.address, c.tin as customer_tin, c.vat_number as customer_vat, b.branch_name, b.address as branch_address, b.phone as branch_phone, u.first_name as sales_rep_first, u.last_name as sales_rep_last FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id LEFT JOIN branches b ON i.branch_id = b.id LEFT JOIN users u ON i.user_id = u.id WHERE i.id = :id", [':id' => $id]);

if (!$invoice) {
    redirectTo('modules/invoicing/index.php');
}

$invoiceItems = $db->getRows("SELECT ii.*, p.product_name, p.brand, p.model, p.tax_id as product_tax_id, pc.tax_id as category_tax_id FROM invoice_items ii LEFT JOIN products p ON ii.product_id = p.id LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE ii.invoice_id = :id ORDER BY ii.id", [':id' => $id]);
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

// Get prices_include_tax setting (same as POS)
$pricesIncludeTax = getSetting('prices_include_tax', '1') == '1';

// Get applicable taxes from fiscal_config for product-specific tax rates
$applicableTaxes = [];
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;
if ($branchId) {
    $fiscalConfig = $primaryDb->getRow(
        "SELECT applicable_taxes FROM fiscal_config WHERE branch_id = :branch_id LIMIT 1",
        [':branch_id' => $branchId]
    );
    if ($fiscalConfig && !empty($fiscalConfig['applicable_taxes'])) {
        $applicableTaxes = json_decode($fiscalConfig['applicable_taxes'], true);
        if (!is_array($applicableTaxes)) {
            $applicableTaxes = [];
        }
    }
}

// Create tax lookup map
$taxMap = [];
foreach ($applicableTaxes as $tax) {
    if (isset($tax['taxID'])) {
        $taxId = intval($tax['taxID']);
        $taxPercent = isset($tax['taxPercent']) ? floatval($tax['taxPercent']) : 0;
        $taxMap[$taxId] = $taxPercent;
        $taxMap[(string)$taxId] = $taxPercent;
    }
}

// Get default tax rate function
require_once APP_PATH . '/includes/settings_functions.php';
$defaultTaxRate = getDefaultTaxRate();

// Get invoice customizations
$invoiceTemplate = getSetting('invoice_template', 'modern'); // Get template selection
$invoiceLogo = getSetting('invoice_logo', getSetting('company_logo', ''));
// If no logo setting found, try to find the most recent invoice_logo file
if (empty($invoiceLogo)) {
    $logoDir = APP_PATH . '/assets/images/';
    $logoFiles = glob($logoDir . 'invoice_logo_*.png');
    if (!empty($logoFiles)) {
        // Sort by modification time, most recent first
        usort($logoFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $mostRecent = $logoFiles[0];
        $invoiceLogo = 'assets/images/' . basename($mostRecent);
    }
}
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

// Calculate global discount percentage from invoice discount_amount
// Only apply discount if discount_amount > 0
$globalDiscountPercent = 0;
if ($invoice['subtotal'] > 0 && $invoice['discount_amount'] > 0 && $invoice['discount_amount'] > 0.01) {
    $globalDiscountPercent = ($invoice['discount_amount'] / $invoice['subtotal']) * 100;
}

foreach ($invoiceItems as &$item) {
    $unitPrice = floatval($item['unit_price'] ?? 0); // Original price WITH tax if pricesIncludeTax
    $quantity = intval($item['quantity'] ?? 1);
    
    // Get product-specific tax rate
    $productTaxId = $item['product_tax_id'] ?? null;
    $categoryTaxId = $item['category_tax_id'] ?? null;
    $finalTaxId = $productTaxId ?: $categoryTaxId;
    $itemTaxRate = $defaultTaxRate; // Default to default tax rate
    
    if ($finalTaxId) {
        $taxIdInt = intval($finalTaxId);
        if (isset($taxMap[$taxIdInt])) {
            $itemTaxRate = $taxMap[$taxIdInt];
        } elseif (isset($taxMap[(string)$taxIdInt])) {
            $itemTaxRate = $taxMap[(string)$taxIdInt];
        }
    }
    
    // Use original unit_price * quantity as the base (this is the price WITH tax if pricesIncludeTax)
    // This matches POS behavior - show original prices in item rows, discount in summary
    $lineSubtotal = $unitPrice * $quantity; // Original subtotal WITH tax (no discount applied to item display)
    
    // The lineSubtotal is the total including VAT (matches what POS shows in item rows)
    // Discount is shown separately in the summary section, not in item totals
    $lineTotalInclVAT = $lineSubtotal;
    
    // Calculate VAT based on prices_include_tax setting
    // Extract tax from the final total
    if ($pricesIncludeTax) {
        // Prices include tax - EXTRACT tax from the final total
        if ($itemTaxRate > 0) {
            $taxDecimal = $itemTaxRate / 100;
            $priceWithoutTax = $lineTotalInclVAT / (1 + $taxDecimal);
            $lineVAT = $lineTotalInclVAT - $priceWithoutTax;
        } else {
            $priceWithoutTax = $lineTotalInclVAT;
            $lineVAT = 0;
        }
    } else {
        // Prices do NOT include tax - ADD tax on top
        $priceWithoutTax = $lineSubtotal;
        if ($itemTaxRate > 0) {
            $lineVAT = $lineSubtotal * ($itemTaxRate / 100);
        } else {
            $lineVAT = 0;
        }
        $lineTotalInclVAT = $lineSubtotal + $lineVAT;
    }
    
    // Unit price excluding VAT
    $item['unit_price_excl_vat'] = $priceWithoutTax / $quantity;
    $item['line_total_excl_vat'] = $priceWithoutTax;
    $item['line_vat'] = $lineVAT;
    $item['line_total_incl_vat'] = $lineTotalInclVAT;
    $item['tax_rate'] = $itemTaxRate; // Store tax rate for grouping
    
    $totalExclVAT += $priceWithoutTax;
    $totalVAT += $lineVAT;
    $totalInclVAT += $lineTotalInclVAT;
}
unset($item);

// Group taxes by tax rate for display (like POS receipt)
// Use the tax amounts already calculated from items (which show original prices)
// These are already rounded per item, so we just sum them
$taxGroups = [];
foreach ($invoiceItems as $item) {
    $taxRate = $item['tax_rate'] ?? 0;
    $taxAmount = $item['line_vat'] ?? 0; // This is already rounded per item
    
    if ($taxAmount > 0) {
        $key = number_format($taxRate, 1);
        if (!isset($taxGroups[$key])) {
            $taxGroups[$key] = [
                'rate' => $taxRate,
                'amount' => 0
            ];
        }
        $taxGroups[$key]['amount'] += $taxAmount;
    }
}

// Sort tax groups by rate
ksort($taxGroups);

// Round tax group amounts to 2 decimal places (matching POS behavior)
foreach ($taxGroups as &$group) {
    $group['amount'] = round($group['amount'], 2);
}
unset($group);

// Summary calculation (matching POS receipt):
// 1. Subtotal (Excl VAT) = sum of item "Total (Excl VAT)" values
// 2. Discount = invoice discount_amount
// 3. Tax totals = sum of item VAT amounts (already grouped and rounded)
// 4. Total (Incl VAT) = Subtotal - Discount + Sum of Taxes

// Subtotal is already calculated as sum of item "Total (Excl VAT)" values
// $totalExclVAT is correct

// Discount amount
$discountAmount = floatval($invoice['discount_amount'] ?? 0);

// Sum of all taxes (from grouped tax amounts)
$sumOfTaxes = 0;
foreach ($taxGroups as $group) {
    $sumOfTaxes += $group['amount'];
}

// Total (Incl VAT) = Subtotal - Discount + Sum of Taxes
$totalInclVAT = $totalExclVAT - $discountAmount + $sumOfTaxes;

// Total VAT for display (sum of all tax groups)
$totalVAT = $sumOfTaxes;

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
    
    // Template-specific PDF styling
    // Convert hex color to RGB for TCPDF
    function hexToRgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) == 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        ];
    }
    
    $primaryRgb = hexToRgb($invoicePrimaryColor);
    $lineWidth = 0.5; // Default line width
    $headerBgColor = $primaryRgb; // Default header background
    $clientBgColor = [233, 236, 239]; // Default client section background
    $companyNameStyle = 'B'; // Default font style (Bold)
    $companyNameSize = 16; // Default font size
    
    // Apply template-specific styling
    if ($invoiceTemplate === 'classic') {
        // Classic: Thicker lines, darker colors
        $lineWidth = 0.8;
        $companyNameStyle = 'B';
        $companyNameSize = 17;
    } elseif ($invoiceTemplate === 'minimal') {
        // Minimal: Thinner lines, lighter colors
        $lineWidth = 0.3;
        $headerBgColor = [245, 245, 245]; // Light gray instead of primary color
        $clientBgColor = [255, 255, 255]; // White (no background)
        $companyNameStyle = '';
        $companyNameSize = 15;
    } elseif ($invoiceTemplate === 'elegant') {
        // Elegant: Medium lines, primary color accents
        $lineWidth = 0.6;
        $companyNameStyle = 'BI'; // Bold Italic
        $companyNameSize = 16;
    }
    // Modern template uses defaults
    
    $pdf->SetLineWidth($lineWidth);
    
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
    
    // Company name on left - Template-specific styling
    $pdf->SetXY(15, $startY);
    if ($invoiceTemplate === 'classic') {
        $pdf->SetTextColor($primaryRgb[0], $primaryRgb[1], $primaryRgb[2]);
    } elseif ($invoiceTemplate === 'minimal') {
        $pdf->SetTextColor(50, 50, 50);
    } elseif ($invoiceTemplate === 'elegant') {
        $pdf->SetTextColor($primaryRgb[0], $primaryRgb[1], $primaryRgb[2]);
    } else {
        $pdf->SetTextColor(0, 0, 0);
    }
    $pdf->SetFont('helvetica', $companyNameStyle, $companyNameSize);
    $pdf->Cell(95, 8, htmlspecialchars($companyName), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0); // Reset to black
    
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
    
    // Client Details Section - Template-specific styling
    if ($invoiceTemplate === 'minimal') {
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetTextColor(50, 50, 50);
    } elseif ($invoiceTemplate === 'elegant') {
        $pdf->SetFillColor($primaryRgb[0], $primaryRgb[1], $primaryRgb[2]);
        $pdf->SetTextColor(255, 255, 255);
    } else {
        $pdf->SetFillColor(233, 236, 239);
        $pdf->SetTextColor(0, 0, 0);
    }
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'CLIENT DETAILS', 1, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor($clientBgColor[0], $clientBgColor[1], $clientBgColor[2]);
    $pdf->SetTextColor(0, 0, 0); // Reset text color
    
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
    
    // Items Table Header - Template-specific styling
    if ($invoiceTemplate === 'minimal') {
        $pdf->SetFillColor(245, 245, 245); // Light gray
        $pdf->SetTextColor(50, 50, 50); // Dark gray text
    } elseif ($invoiceTemplate === 'elegant') {
        $pdf->SetFillColor($primaryRgb[0], $primaryRgb[1], $primaryRgb[2]); // Primary color
        $pdf->SetTextColor(255, 255, 255); // White text
    } else {
        $pdf->SetFillColor(30, 58, 138); // Dark blue (default/modern/classic)
        $pdf->SetTextColor(255, 255, 255); // White text
    }
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
        // Handle product description: General category uses product_name, others use brand+model
        if ($item['product_id']) {
            // Product exists - check for product_name first (General category)
            if (!empty($item['product_name'])) {
                $description = trim($item['product_name']);
            } else {
                // Other categories use brand + model
                $description = trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''));
            }
        } else {
            // Manual item - use description from invoice_items
            $description = $item['description'] ?? '';
        }
        
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
    
    // Display tax breakdown grouped by tax rate (like POS receipt)
    foreach ($taxGroups as $group) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(126, 0, '', 0, 0); // Spacer
        $label = 'Total ' . number_format($group['rate'], 1) . '% VAT:';
        $pdf->Cell(54, 8, $label, 1, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 8, 'USD ' . number_format($group['amount'], 2), 1, 1, 'R');
    }
    
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
    
    // Banking Details - Template-specific styling
    if ($bankName && $bankAccount) {
        if ($invoiceTemplate === 'minimal') {
            $pdf->SetFillColor(250, 250, 250);
        } elseif ($invoiceTemplate === 'elegant') {
            $pdf->SetFillColor(255, 255, 255);
        } else {
            $pdf->SetFillColor(233, 236, 239);
        }
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 8, 'Nostro Banking Details', 1, 1, 'L', true);
        $pdf->SetFont('helvetica', '', 8);
        if ($invoiceTemplate === 'minimal') {
            $pdf->SetFillColor(255, 255, 255);
        } elseif ($invoiceTemplate === 'elegant') {
            $pdf->SetFillColor(250, 250, 250);
        } else {
            $pdf->SetFillColor(248, 249, 250);
        }
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
    
    // Fiscal Information Section (if fiscalized) - Get early for header placement
    $fiscalDetails = null;
    $fiscalReceipt = null;
    if ($invoice['fiscalized'] && $invoice['fiscal_details']) {
        $fiscalDetails = json_decode($invoice['fiscal_details'], true);
        
        // Get fiscal receipt from primary database
        $primaryDb = Database::getPrimaryInstance();
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
    
    // Add QR Code and Verification at BOTTOM CENTERED (before footer)
    if ($fiscalDetails && $fiscalReceipt) {
        // Add spacing before QR code section
        $pdf->Ln(15);
        
        // Draw horizontal line above QR code section
        $lineY = $pdf->GetY();
        $pdf->Line(15, $lineY, 195, $lineY);
        $pdf->Ln(10);
        
        // Calculate center position for QR code (page width is 180mm, QR code is 25mm)
        $pageWidth = 180; // 195 - 15 (margins)
        $qrSize = 25;
        $qrX = 15 + ($pageWidth / 2) - ($qrSize / 2); // Center horizontally
        $qrY = $pdf->GetY();
        
        // QR Code (BOTTOM CENTERED)
        $qrCodeDisplayed = false;
        
        // First, try to use stored QR code image if available
        if (isset($fiscalReceipt['receipt_qr_code']) && !empty($fiscalReceipt['receipt_qr_code']) && strlen($fiscalReceipt['receipt_qr_code']) > 0) {
            try {
                $qrImageData = base64_decode($fiscalReceipt['receipt_qr_code']);
                if ($qrImageData !== false && strlen($qrImageData) > 0) {
                    $tempQrFile = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
                    file_put_contents($tempQrFile, $qrImageData);
                    $pdf->Image($tempQrFile, $qrX, $qrY, $qrSize, $qrSize, 'PNG', '', '', false, 300, '', false, false, 0);
                    @unlink($tempQrFile);
                    $qrCodeDisplayed = true;
                }
            } catch (Exception $e) {
                error_log("QR code image error: " . $e->getMessage());
            }
        }
        
        // Fallback: Generate QR code on-the-fly
        if (!$qrCodeDisplayed && isset($fiscalReceipt['receipt_qr_data']) && !empty($fiscalReceipt['receipt_qr_data'])) {
            try {
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
                    
                    $style = array(
                        'border' => false,
                        'padding' => 0,
                        'fgcolor' => array(0,0,0),
                        'bgcolor' => false,
                        'module_width' => 1,
                        'module_height' => 1
                    );
                    $pdf->write2DBarcode($qrCodeString, 'QRCODE,L', $qrX, $qrY, $qrSize, $qrSize, $style, 'N');
                    $qrCodeDisplayed = true;
                }
            } catch (Exception $e) {
                error_log("QR code generation error: " . $e->getMessage());
            }
        }
        
        // Verification Code (below QR code, centered)
        if (isset($fiscalDetails['verification_code'])) {
            $pdf->SetY($qrY + $qrSize + 5);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell(0, 4, 'Verification code', 0, 1, 'C');
            $pdf->SetFont('helvetica', '', 8);
            $pdf->Cell(0, 4, $fiscalDetails['verification_code'], 0, 1, 'C');
        }
        
        // Verification URL (centered)
        $pdf->SetY($pdf->GetY() + 2);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell(0, 3, 'You can verify this receipt manually at', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'U', 7);
        $pdf->SetTextColor(30, 58, 138);
        $pdf->Cell(0, 3, 'https://receipt.zimra.org/', 0, 1, 'C', false, 'https://receipt.zimra.org/');
        $pdf->SetTextColor(0, 0, 0);
        
        $pdf->Ln(10);
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
        }
        
        /* Template-specific styles */
        <?php if ($invoiceTemplate === 'modern'): ?>
        /* Modern Template - Current default style with borders and shadow */
        .invoice-container {
            border: 2px solid #000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        <?php elseif ($invoiceTemplate === 'classic'): ?>
        /* Classic Template - Traditional, clean borders */
        .invoice-container {
            border: 1px solid #333;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .invoice-header {
            border-bottom: 3px solid #333 !important;
        }
        .company-details-left h2 {
            color: #1a1a1a;
            border-bottom: 2px solid #333;
            padding-bottom: 8px;
            display: inline-block;
        }
        <?php elseif ($invoiceTemplate === 'minimal'): ?>
        /* Minimal Template - Clean, minimal design */
        .invoice-container {
            border: none;
            box-shadow: none;
            padding: 15px;
        }
        .invoice-header {
            border-bottom: 1px solid #ddd !important;
        }
        .company-details-left h2 {
            color: #333;
            font-weight: 300;
        }
        .invoice-meta-section {
            border-bottom: 1px solid #ddd !important;
        }
        .client-section {
            background: transparent !important;
            border: 1px solid #ddd !important;
        }
        table {
            border: 1px solid #ddd !important;
        }
        table thead {
            background: #f5f5f5 !important;
            color: #333 !important;
        }
        table thead th {
            background-color: #f5f5f5 !important;
            color: #333 !important;
            border: 1px solid #ddd !important;
            text-shadow: none !important;
        }
        .summary-row {
            border-bottom: 1px solid #ddd !important;
        }
        .summary-row.total {
            border-top: 1px solid #333 !important;
            border-bottom: 1px solid #333 !important;
        }
        .terms-section,
        .banking-section {
            border-top: 1px solid #ddd !important;
            border: 1px solid #ddd !important;
            background: transparent !important;
        }
        <?php elseif ($invoiceTemplate === 'elegant'): ?>
        /* Elegant Template - Sophisticated, refined design */
        .invoice-container {
            border: 1px solid #ccc;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            background: #fafafa;
        }
        .invoice-header {
            border-bottom: 2px solid <?= escapeHtml($invoicePrimaryColor) ?> !important;
            padding-bottom: 20px;
        }
        .company-details-left h2 {
            color: <?= escapeHtml($invoicePrimaryColor) ?>;
            font-style: italic;
            letter-spacing: 1px;
        }
        .invoice-meta-section {
            border-bottom: 2px solid <?= escapeHtml($invoicePrimaryColor) ?> !important;
            background: #fff;
            padding: 15px;
            border-radius: 4px;
        }
        .client-section {
            background: linear-gradient(to bottom, #fff, #f8f9fa) !important;
            border: 2px solid <?= escapeHtml($invoicePrimaryColor) ?> !important;
            border-radius: 4px;
        }
        table {
            border: 2px solid <?= escapeHtml($invoicePrimaryColor) ?> !important;
        }
        .summary-section {
            background: #fff;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        .terms-section,
        .banking-section {
            border: 1px solid #ddd !important;
            background: #fff !important;
            border-radius: 4px;
        }
        <?php else: ?>
        /* Default to Modern if template not recognized */
        .invoice-container {
            border: 2px solid #000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        <?php endif; ?>
        
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
                            <?php 
                            // Handle product description: General category uses product_name, others use brand+model
                            if ($item['product_id']) {
                                if (!empty($item['product_name'])) {
                                    echo escapeHtml(trim($item['product_name']));
                                } else {
                                    echo escapeHtml(trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? '')));
                                }
                            } else {
                                echo escapeHtml($item['description'] ?? '');
                            }
                            ?>
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
            <?php if ($discountAmount > 0.01): ?>
            <div class="summary-row">
                <span><strong>Discount:</strong></span>
                <span><strong>USD -<?= number_format($discountAmount, 2) ?></strong></span>
            </div>
            <?php endif; ?>
            <?php foreach ($taxGroups as $group): ?>
            <div class="summary-row">
                <span><strong>Total <?= number_format($group['rate'], 1) ?>% VAT:</strong></span>
                <span><strong>USD <?= number_format($group['amount'], 2) ?></strong></span>
            </div>
            <?php endforeach; ?>
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
