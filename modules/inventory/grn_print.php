<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.view');

$db = Database::getInstance();
$id = intval($_GET['id'] ?? 0);
$isPurchaseOrder = isset($_GET['po']) && $_GET['po'] == '1';

if (!$id) {
    redirectTo('modules/inventory/grn.php');
}

$grn = $db->getRow("SELECT grn.*, 
                    s.name as supplier_name, s.contact_person, s.phone as supplier_phone, s.email as supplier_email,
                    b.branch_name, b.address as branch_address,
                    u.first_name, u.last_name
                    FROM goods_received_notes grn 
                    LEFT JOIN suppliers s ON grn.supplier_id = s.id 
                    LEFT JOIN branches b ON grn.branch_id = b.id 
                    LEFT JOIN users u ON grn.received_by = u.id
                    WHERE grn.id = :id", [':id' => $id]);

if (!$grn) {
    redirectTo('modules/inventory/grn.php');
}

$grnItems = $db->getRows("SELECT gi.*, p.brand, p.model, p.product_code, p.product_name, c.name as category_name
                          FROM grn_items gi
                          LEFT JOIN products p ON gi.product_id = p.id
                          LEFT JOIN product_categories c ON p.category_id = c.id
                          WHERE gi.grn_id = :id
                          ORDER BY gi.id", [':id' => $id]);
if ($grnItems === false) $grnItems = [];

// Get company settings
$companyName = getSetting('company_name', SYSTEM_NAME);
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');
$companyTIN = getSetting('company_tin', '');
$companyVAT = getSetting('company_vat_number', '');

// Get logo
$receiptLogoPath = getSetting('pos_receipt_logo', '');
$showLogo = !empty($receiptLogoPath);

// Use TCPDF for PDF generation
require_once APP_PATH . '/vendor/autoload.php';

// Create PDF (Portrait, mm, A4)
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

// Set document information
$documentType = $isPurchaseOrder ? 'Purchase Order' : 'GRN';
$pdf->SetCreator('ELECTROX-POS');
$pdf->SetAuthor($companyName);
$pdf->SetTitle($documentType . ' ' . $grn['grn_number']);
$pdf->SetSubject($documentType);

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
    $pdf->Cell(95, 5, 'Phone: ' . htmlspecialchars($companyPhone), 0, 1, 'L');
}
if ($companyEmail) {
    $pdf->Cell(95, 5, 'Email: ' . htmlspecialchars($companyEmail), 0, 1, 'L');
}
if ($companyTIN) {
    $pdf->Cell(95, 5, 'TIN: ' . htmlspecialchars($companyTIN), 0, 1, 'L');
}
if ($companyVAT) {
    $pdf->Cell(95, 5, 'VAT No: ' . htmlspecialchars($companyVAT), 0, 1, 'L');
}
$leftSectionEndY = $pdf->GetY();

// Document type and number on right
$rightStartY = $logoBottomY + 3;
$pdf->SetXY(110, $rightStartY);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 7, strtoupper($documentType), 0, 1, 'R');
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 6, 'Ref #: ' . htmlspecialchars($grn['grn_number']), 0, 1, 'R');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(0, 5, 'Date: ' . date('d/m/Y', strtotime($grn['received_date'])), 0, 1, 'R');
$rightSectionEndY = $pdf->GetY();

// Move to the lower of left or right section
$nextY = max($leftSectionEndY, $rightSectionEndY);
$pdf->SetY($nextY);

// Horizontal line
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(8);

// GRN/Branch Information Section
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(233, 236, 239);
$pdf->Cell(0, 8, 'BRANCH INFORMATION', 1, 1, 'L', true);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(255, 255, 255);

$branchInfo = 'Branch: ' . htmlspecialchars($grn['branch_name'] ?? 'N/A');
if ($grn['branch_address']) {
    $branchInfo .= "\nAddress: " . htmlspecialchars($grn['branch_address']);
}
$pdf->MultiCell(87.5, 5, $branchInfo, 1, 'L', true, 0);

// Received By info
$receivedByInfo = 'Received By: ' . htmlspecialchars(trim(($grn['first_name'] ?? '') . ' ' . ($grn['last_name'] ?? '')) ?: 'N/A');
if ($grn['status']) {
    $receivedByInfo .= "\nStatus: " . htmlspecialchars($grn['status']);
}
$pdf->MultiCell(87.5, 5, $receivedByInfo, 1, 'L', true, 1);

$pdf->Ln(5);

// Supplier Information Section (if exists)
if ($grn['supplier_name']) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(233, 236, 239);
    $pdf->Cell(0, 8, 'SUPPLIER INFORMATION', 1, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);
    
    $supplierInfo = 'Supplier: ' . htmlspecialchars($grn['supplier_name']);
    if ($grn['contact_person']) {
        $supplierInfo .= "\nContact: " . htmlspecialchars($grn['contact_person']);
    }
    if ($grn['supplier_phone']) {
        $supplierInfo .= "\nPhone: " . htmlspecialchars($grn['supplier_phone']);
    }
    if ($grn['supplier_email']) {
        $supplierInfo .= "\nEmail: " . htmlspecialchars($grn['supplier_email']);
    }
    $pdf->MultiCell(0, 5, $supplierInfo, 1, 'L', true);
    $pdf->Ln(5);
}

// Items Table Header
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(51, 51, 51);
$pdf->SetTextColor(255, 255, 255);

// Column widths
$colNo = 10;
$colProduct = 65;
$colCategory = 25;
$colQty = 15;
$colCost = 25;
$colSelling = 25;
$colTotal = 25;

$pdf->Cell($colNo, 7, '#', 1, 0, 'C', true);
$pdf->Cell($colProduct, 7, 'Product', 1, 0, 'L', true);
$pdf->Cell($colCategory, 7, 'Category', 1, 0, 'L', true);
$pdf->Cell($colQty, 7, 'Qty', 1, 0, 'C', true);
$pdf->Cell($colCost, 7, 'Cost Price', 1, 0, 'R', true);
if (!$isPurchaseOrder) {
    $pdf->Cell($colSelling, 7, 'Selling Price', 1, 0, 'R', true);
}
$pdf->Cell($colTotal, 7, 'Total', 1, 1, 'R', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 9);

// Items
$counter = 1;
$totalValue = 0;
foreach ($grnItems as $item) {
    // For General category products, use product_name; for others use brand + model
    $categoryName = $item['category_name'] ?? '';
    $isGeneralCategory = strcasecmp(trim($categoryName), 'General') === 0;
    
    if ($isGeneralCategory && !empty($item['product_name'])) {
        $productDisplayName = $item['product_name'];
    } else {
        $productDisplayName = trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''));
        // Fallback to product_name if brand/model is empty
        if (empty($productDisplayName) && !empty($item['product_name'])) {
            $productDisplayName = $item['product_name'];
        }
        // Final fallback to product code
        if (empty($productDisplayName)) {
            $productDisplayName = $item['product_code'] ?? 'N/A';
        }
    }
    
    $productCode = $item['product_code'] ?? '';
    $quantity = intval($item['quantity'] ?? 0);
    $costPrice = floatval($item['cost_price'] ?? 0);
    $sellingPrice = floatval($item['selling_price'] ?? 0);
    $lineTotal = $costPrice * $quantity;
    $totalValue += $lineTotal;
    
    // Get current Y position for multi-cell
    $currentY = $pdf->GetY();
    
    // Product name with code (may wrap)
    $productText = htmlspecialchars($productDisplayName);
    if ($productCode && $productDisplayName !== $productCode) {
        $productText .= "\n" . htmlspecialchars($productCode);
    }
    
    // Calculate height needed for product name
    $productHeight = max(7, $pdf->getStringHeight($colProduct, $productText, false, true, '', 1));
    
    // Number
    $pdf->Cell($colNo, $productHeight, $counter++, 1, 0, 'C', false, '', 0, '', 'T');
    
    // Product (with wrapping)
    $pdf->MultiCell($colProduct, 7, $productText, 1, 'L', false, 0, '', '', true, 0, false, true, $productHeight, 'T');
    
    // Category
    $pdf->Cell($colCategory, $productHeight, htmlspecialchars($categoryName), 1, 0, 'L', false, '', 0, '', 'T');
    
    // Quantity
    $pdf->Cell($colQty, $productHeight, number_format($quantity), 1, 0, 'C', false, '', 0, '', 'T');
    
    // Cost Price
    $pdf->Cell($colCost, $productHeight, '$' . number_format($costPrice, 2), 1, 0, 'R', false, '', 0, '', 'T');
    
    // Selling Price (only for GRN, not Purchase Order)
    if (!$isPurchaseOrder) {
        $pdf->Cell($colSelling, $productHeight, '$' . number_format($sellingPrice, 2), 1, 0, 'R', false, '', 0, '', 'T');
    }
    
    // Total
    $pdf->Cell($colTotal, $productHeight, '$' . number_format($lineTotal, 2), 1, 1, 'R', false, '', 0, '', 'T');
}

// Total Row
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(245, 245, 245);

$totalCols = 4; // #, Product, Category, Qty
if (!$isPurchaseOrder) {
    $totalCols++; // Selling Price
}
$totalCols++; // Cost Price
$totalWidth = $colNo + $colProduct + $colCategory + $colQty + $colCost;
if (!$isPurchaseOrder) {
    $totalWidth += $colSelling;
}

$pdf->Cell($totalWidth, 8, 'Total Value:', 1, 0, 'R', true);
$pdf->Cell($colTotal, 8, '$' . number_format($totalValue, 2), 1, 1, 'R', true);

$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(255, 255, 255);

// Notes section
if (!empty($grn['notes'])) {
    $pdf->Ln(8);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Notes:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->MultiCell(0, 5, htmlspecialchars($grn['notes']), 0, 'L');
}

// Footer signature area (for Purchase Orders)
if ($isPurchaseOrder) {
    $pdf->Ln(15);
    $pdf->SetY($pdf->GetY());
    
    // Signature line for supplier
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(87.5, 5, 'Supplier Signature:', 0, 0, 'L');
    $pdf->Cell(87.5, 5, 'Authorized By:', 0, 1, 'L');
    
    $pdf->SetY($pdf->GetY() + 15);
    $pdf->Line(15, $pdf->GetY(), 102.5, $pdf->GetY());
    $pdf->Line(102.5, $pdf->GetY(), 195, $pdf->GetY());
    
    $pdf->SetY($pdf->GetY() + 5);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(87.5, 4, 'Date: _______________', 0, 0, 'L');
    $pdf->Cell(87.5, 4, 'Date: _______________', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

// Output PDF
$filename = ($isPurchaseOrder ? 'PO' : 'GRN') . '_' . $grn['grn_number'] . '.pdf';
$pdf->Output($filename, 'I');
exit;
