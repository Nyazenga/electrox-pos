<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('sales.view', 'sales.refund', 'receipts.refund');

$refundId = intval($_GET['id'] ?? 0);
$format = $_GET['format'] ?? 'A4';

if (!$refundId) {
    redirectTo('modules/sales/cancelled_sales.php');
}

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get refund with credit note
$refund = $db->getRow("SELECT r.*, s.receipt_number, s.sale_date,
                        c.first_name, c.last_name, c.company_name, c.email, c.phone, c.address,
                        u.first_name as cashier_first, u.last_name as cashier_last,
                        b.branch_name, b.address as branch_address, b.phone as branch_phone
                        FROM refunds r
                        LEFT JOIN sales s ON r.sale_id = s.id
                        LEFT JOIN customers c ON r.customer_id = c.id
                        LEFT JOIN users u ON r.user_id = u.id
                        LEFT JOIN branches b ON r.branch_id = b.id
                        WHERE r.id = :id", [':id' => $refundId]);

if (!$refund || !$refund['credit_note_number']) {
    redirectTo('modules/sales/cancelled_sales.php');
}

// Get credit note items
$creditNoteItems = $db->getRows("SELECT * FROM credit_note_items WHERE credit_note_id = (SELECT id FROM credit_notes WHERE refund_id = :id LIMIT 1)", [':id' => $refundId]);
if ($creditNoteItems === false) {
    $creditNoteItems = [];
}

// Get fiscal details
$fiscalDetails = null;
if (!empty($refund['fiscal_details'])) {
    $fiscalDetails = json_decode($refund['fiscal_details'], true);
}

// Get fiscal receipt from primary database (for QR code)
$fiscalReceipt = null;
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

// Get company settings
$companyName = getSetting('company_name', SYSTEM_NAME);
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');
$companyTIN = getSetting('company_tin', '');
$companyVAT = getSetting('company_vat_number', '');
$companyTagline = getSetting('company_tagline', 'Transforming Your Tomorrow');

// Get receipt logo - use EXACT same logic as invoice print
$receiptLogoPath = getSetting('pos_receipt_logo', getSetting('invoice_logo', getSetting('company_logo', '')));
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
$pdf->SetTitle('Credit Note ' . $refund['credit_note_number']);
$pdf->SetSubject('Credit Note');

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
        $logoHeight = 25; // Fixed logo height in mm
    }
}

// Start Y position for header
$startY = 15;
$pdf->SetY($startY);

// Logo on right (if exists)
$logoBottomY = $startY;
if ($logoPath) {
    $logoWidth = 45;
    $logoX = 195 - 5 - $logoWidth; // Right margin (5mm) - logo width
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

// Credit Note title and tagline on right - below logo
$rightStartY = $logoBottomY + 3;
$pdf->SetXY(110, $rightStartY);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 6, 'CREDIT NOTE', 0, 1, 'R');
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

// Credit Note Meta Section
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(95, 5, '', 0, 0, 'L'); // Spacer
$pdf->Cell(0, 5, 'Date: ' . date('d/m/Y', strtotime($refund['refund_date'])), 0, 1, 'R');
$pdf->Cell(95, 5, '', 0, 0, 'L'); // Spacer
$pdf->Cell(0, 5, 'Credit Note #: ' . htmlspecialchars($refund['credit_note_number']), 0, 1, 'R');
$pdf->Cell(95, 5, '', 0, 0, 'L'); // Spacer
$pdf->Cell(0, 5, 'Refund #: ' . htmlspecialchars($refund['refund_number']), 0, 1, 'R');
$pdf->Cell(95, 5, '', 0, 0, 'L'); // Spacer
$pdf->Cell(0, 5, 'Original Receipt #: ' . htmlspecialchars($refund['receipt_number']), 0, 1, 'R');
if ($refund['branch_name']) {
    $pdf->Cell(95, 5, '', 0, 0, 'L'); // Spacer
    $pdf->Cell(0, 5, 'Branch: ' . htmlspecialchars($refund['branch_name']), 0, 1, 'R');
}
$pdf->Ln(8);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(10);

// Client Details Section
$pdf->SetFillColor(233, 236, 239);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 8, 'CLIENT DETAILS', 1, 1, 'L', true);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(248, 249, 250);

// Customer Name
$customerName = trim(($refund['first_name'] ?? '') . ' ' . ($refund['last_name'] ?? ''));
if (empty($customerName)) {
    $customerName = $refund['company_name'] ?? 'Walk-in Customer';
}
$pdf->Cell(95, 8, 'Client Name: ' . htmlspecialchars($customerName), 1, 0, 'L', true);
$pdf->Cell(0, 8, '', 1, 1, 'L', true);

// Address (if available)
if (!empty($refund['address'])) {
    $addressLines = explode("\n", $refund['address']);
    foreach ($addressLines as $line) {
        $line = trim($line);
        if ($line) {
            $pdf->Cell(0, 8, 'Address: ' . htmlspecialchars($line), 1, 1, 'L', true);
        }
    }
}

// Phone and Email in one row
$contactInfo = '';
if (!empty($refund['phone'])) {
    $contactInfo .= 'Phone: ' . htmlspecialchars($refund['phone']);
}
if (!empty($refund['email'])) {
    if ($contactInfo) $contactInfo .= ' | ';
    $contactInfo .= 'Email: ' . htmlspecialchars($refund['email']);
}
if ($contactInfo) {
    $pdf->Cell(0, 8, $contactInfo, 1, 1, 'L', true);
}

$pdf->Ln(8);

// Reason for Credit Note
if ($refund['reason']) {
    $pdf->SetFillColor(255, 245, 245);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(0, 8, 'Reason for Credit Note: ' . htmlspecialchars($refund['reason']), 1, 1, 'L', true);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Ln(5);
}

// Items Table Header
$pdf->SetFillColor(233, 236, 239);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(10, 8, '#', 1, 0, 'C', true);
$pdf->Cell(80, 8, 'Product', 1, 0, 'L', true);
$pdf->Cell(25, 8, 'Quantity', 1, 0, 'C', true);
$pdf->Cell(30, 8, 'Unit Price', 1, 0, 'R', true);
$pdf->Cell(35, 8, 'Total', 1, 1, 'R', true);

// Items
$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(255, 255, 255);
foreach ($creditNoteItems as $index => $item) {
    $pdf->Cell(10, 8, ($index + 1), 1, 0, 'C');
    $pdf->Cell(80, 8, htmlspecialchars($item['product_name']), 1, 0, 'L');
    $pdf->Cell(25, 8, $item['quantity'], 1, 0, 'C');
    $pdf->Cell(30, 8, formatCurrency($item['unit_price']), 1, 0, 'R');
    $pdf->Cell(35, 8, formatCurrency($item['total_price']), 1, 1, 'R');
}

$pdf->Ln(10);

// Summary Section
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(126, 0, '', 0, 0); // Spacer
$pdf->Cell(54, 8, 'Subtotal:', 1, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 8, formatCurrency($refund['subtotal']), 1, 1, 'R');

if ($refund['discount_amount'] > 0) {
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(126, 0, '', 0, 0); // Spacer
    $pdf->Cell(54, 8, 'Discount:', 1, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(0, 8, '-' . formatCurrency($refund['discount_amount']), 1, 1, 'R');
}

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(220, 53, 69); // Red color for credit note total
$pdf->Cell(126, 0, '', 0, 0); // Spacer
$pdf->Cell(54, 10, 'Total Credit:', 1, 0, 'L');
$pdf->Cell(0, 10, formatCurrency($refund['total_amount']), 1, 1, 'R');
$pdf->SetTextColor(0, 0, 0);

$pdf->Ln(12);

// Notes (if any)
if ($refund['notes']) {
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(8);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(0, 5, 'Notes:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->MultiCell(0, 4, htmlspecialchars($refund['notes']), 0, 'L');
    $pdf->Ln(8);
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
$invoiceFooterText = getSetting('invoice_footer_text', 'Thank you for your business!');
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

// Output PDF
$filename = 'Credit_Note_' . $refund['credit_note_number'] . '_' . date('Ymd') . '.pdf';
$pdf->Output($filename, 'I');
exit;
