<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.stock_take');

$reportId = intval($_GET['id'] ?? 0);
if (!$reportId) {
    redirectTo('modules/products/stock_take_reports.php');
}

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get report
$report = $primaryDb->getRow("SELECT 
                            str.*,
                            b.branch_name,
                            b.address as branch_address,
                            u.first_name as user_first,
                            u.last_name as user_last,
                            st.take_type,
                            st.completed_at
                            FROM stock_take_reports str
                            LEFT JOIN branches b ON str.branch_id = b.id
                            LEFT JOIN users u ON str.taken_by = u.id
                            LEFT JOIN stock_takes st ON str.stock_take_id = st.id
                            WHERE str.id = :id", [':id' => $reportId]);

if (!$report) {
    redirectTo('modules/products/stock_take_reports.php');
}

// Parse summary data
$summaryData = json_decode($report['summary_data'], true);
$detailedBreakdown = $summaryData['detailed_breakdown'] ?? [];

// Get company settings
$companyName = getSetting('company_name', SYSTEM_NAME);
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');
$companyTagline = getSetting('company_tagline', 'Transforming Your Tomorrow');

// Get receipt logo
$receiptLogoPath = getSetting('pos_receipt_logo', '');
$showLogo = !empty($receiptLogoPath);

// Use TCPDF for PDF generation
require_once APP_PATH . '/vendor/autoload.php';

// Create PDF (Portrait, mm, A4)
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('ELECTROX-POS');
$pdf->SetAuthor($companyName);
$pdf->SetTitle('Stock Take Report - ' . date('Y-m-d', strtotime($report['report_date'])));
$pdf->SetSubject('Stock Take Report');

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
$pdf->Cell(0, 6, 'STOCK TAKE REPORT', 0, 1, 'R');
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
$pdf->Cell(95, 5, '', 0, 0, 'L');
$pdf->Cell(0, 5, 'Report Date: ' . date('d/m/Y H:i', strtotime($report['report_date'])), 0, 1, 'R');
$pdf->Cell(95, 5, '', 0, 0, 'L');
$pdf->Cell(0, 5, 'Branch: ' . htmlspecialchars($report['branch_name'] ?? 'N/A'), 0, 1, 'R');
$pdf->Cell(95, 5, '', 0, 0, 'L');
$pdf->Cell(0, 5, 'Taken By: ' . htmlspecialchars(trim(($report['user_first'] ?? '') . ' ' . ($report['user_last'] ?? ''))), 0, 1, 'R');
if ($report['completed_at']) {
    $pdf->Cell(95, 5, '', 0, 0, 'L');
    $pdf->Cell(0, 5, 'Completed: ' . date('d/m/Y H:i', strtotime($report['completed_at'])), 0, 1, 'R');
}
$pdf->Ln(8);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(10);

// Summary Section
$pdf->SetFillColor(233, 236, 239);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 8, 'SUMMARY', 1, 1, 'L', true);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(248, 249, 250);

$pdf->Cell(95, 8, 'Total Items Counted:', 1, 0, 'L', true);
$pdf->Cell(0, 8, number_format($report['total_items']), 1, 1, 'R');

$pdf->Cell(95, 8, 'Items with Gains:', 1, 0, 'L', true);
$pdf->SetTextColor(0, 128, 0);
$pdf->Cell(0, 8, number_format($report['items_with_gains']) . ' (+' . number_format($report['total_gain_quantity'], 2) . ')', 1, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

$pdf->Cell(95, 8, 'Items with Losses:', 1, 0, 'L', true);
$pdf->SetTextColor(220, 53, 69);
$pdf->Cell(0, 8, number_format($report['items_with_losses']) . ' (-' . number_format($report['total_loss_quantity'], 2) . ')', 1, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

$pdf->Cell(95, 8, 'Items with No Change:', 1, 0, 'L', true);
$pdf->Cell(0, 8, number_format($report['items_no_change']), 1, 1, 'R');

$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(95, 10, 'Net Difference:', 1, 0, 'L', true);
$pdf->SetTextColor($report['net_difference'] >= 0 ? 0 : 220, $report['net_difference'] >= 0 ? 128 : 53, $report['net_difference'] >= 0 ? 0 : 69);
$pdf->Cell(0, 10, ($report['net_difference'] >= 0 ? '+' : '') . number_format($report['net_difference'], 2), 1, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

$pdf->Ln(12);

// Detailed Breakdown Section
if (!empty($detailedBreakdown)) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'DETAILED BREAKDOWN', 1, 1, 'L', true);
    
    // Table Header
    $pdf->SetFillColor(30, 58, 138);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(15, 8, '#', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Code', 1, 0, 'L', true);
    $pdf->Cell(60, 8, 'Product', 1, 0, 'L', true);
    $pdf->Cell(25, 8, 'Current', 1, 0, 'R', true);
    $pdf->Cell(25, 8, 'Counted', 1, 0, 'R', true);
    $pdf->Cell(30, 8, 'Difference', 1, 1, 'R', true);
    
    // Table Rows
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetFillColor(255, 255, 255);
    
    foreach ($detailedBreakdown as $index => $item) {
        $difference = floatval($item['difference'] ?? 0);
        $diffColor = $difference >= 0 ? [0, 128, 0] : [220, 53, 69];
        
        $pdf->Cell(15, 7, ($index + 1), 1, 0, 'C');
        $pdf->Cell(30, 7, htmlspecialchars(substr($item['product_code'] ?? 'N/A', 0, 12)), 1, 0, 'L');
        $pdf->Cell(60, 7, htmlspecialchars(substr($item['product_name'] ?? 'N/A', 0, 30)), 1, 0, 'L');
        $pdf->Cell(25, 7, number_format($item['current_stock'] ?? 0, 2), 1, 0, 'R');
        $pdf->Cell(25, 7, number_format($item['counted_stock'] ?? 0, 2), 1, 0, 'R');
        $pdf->SetTextColor($diffColor[0], $diffColor[1], $diffColor[2]);
        $pdf->Cell(30, 7, ($difference >= 0 ? '+' : '') . number_format($difference, 2), 1, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
        
        // Add notes if available
        if (!empty($item['notes'])) {
            $pdf->SetFont('helvetica', 'I', 7);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell(185, 4, 'Note: ' . htmlspecialchars(substr($item['notes'], 0, 80)), 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(0, 0, 0);
        }
    }
    
    $pdf->Ln(10);
}

// Footer
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 5, 'Generated on ' . date('d/m/Y H:i:s'), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

// Output PDF
$filename = 'Stock_Take_Report_' . date('Ymd_His', strtotime($report['report_date'])) . '.pdf';
$pdf->Output($filename, 'I');
exit;

