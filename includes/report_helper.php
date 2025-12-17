<?php
/**
 * Report Helper Functions
 * Common functions for generating reports
 */

if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

require_once APP_PATH . '/vendor/autoload.php';

class ReportHelper {
    
    /**
     * Generate PDF report using TCPDF
     */
    public static function generatePDF($title, $htmlContent, $filename = null) {
        $companyName = getSetting('company_name', SYSTEM_NAME);
        $companyAddress = getSetting('company_address', '');
        $companyPhone = getSetting('company_phone', '');
        $companyEmail = getSetting('company_email', '');
        $receiptLogoPath = getSetting('pos_receipt_logo', '');
        
        // Create PDF
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('ELECTROX-POS');
        $pdf->SetAuthor($companyName);
        $pdf->SetTitle($title);
        $pdf->SetSubject('Report');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 20);
        
        // Add a page
        $pdf->AddPage();
        
        // Get logo path
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
            }
        }
        
        // Company name on left
        $pdf->SetXY(15, $startY);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(95, 8, htmlspecialchars($companyName), 0, 1, 'L');
        
        // Company address
        $pdf->SetFont('helvetica', '', 9);
        if ($companyAddress) {
            $pdf->MultiCell(95, 5, htmlspecialchars($companyAddress), 0, 'L', false, 0);
        }
        
        // Contact info
        $contactStartY = max($pdf->GetY(), $logoBottomY + 3);
        $pdf->SetXY(15, $contactStartY);
        if ($companyPhone) {
            $pdf->Cell(95, 5, 'Phone: ' . htmlspecialchars($companyPhone), 0, 1, 'L');
        }
        if ($companyEmail) {
            $pdf->Cell(95, 5, 'Email: ' . htmlspecialchars($companyEmail), 0, 1, 'L');
        }
        
        // Report title on right
        $rightStartY = $logoBottomY + 3;
        $pdf->SetXY(110, $rightStartY);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 6, htmlspecialchars($title), 0, 1, 'R');
        
        // Date on right
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetX(110);
        $pdf->Cell(0, 5, 'Generated: ' . date('d/m/Y H:i'), 0, 1, 'R');
        
        // Move to next section
        $nextY = max($pdf->GetY(), $rightStartY + 15);
        $pdf->SetY($nextY);
        
        // Horizontal line
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(8);
        
        // Add HTML content
        $pdf->SetFont('helvetica', '', 9);
        $pdf->writeHTML($htmlContent, true, false, true, false, '');
        
        // Output PDF
        $filename = $filename ?: 'Report_' . date('YmdHis') . '.pdf';
        $pdf->Output($filename, 'D');
        exit;
    }
    
    /**
     * Build sales query with filters
     */
    public static function buildSalesQuery($filters = []) {
        $where = ["s.status != 'deleted'"];
        $params = [];
        
        // Date range
        if (!empty($filters['start_date'])) {
            $where[] = "DATE(s.sale_date) >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $where[] = "DATE(s.sale_date) <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        
        // Branch
        if (!empty($filters['branch_id']) && $filters['branch_id'] !== 'all') {
            $where[] = "s.branch_id = :branch_id";
            $params[':branch_id'] = $filters['branch_id'];
        }
        
        // Customer
        if (!empty($filters['customer_id']) && $filters['customer_id'] !== 'all') {
            $where[] = "s.customer_id = :customer_id";
            $params[':customer_id'] = $filters['customer_id'];
        }
        
        // Cashier/User
        if (!empty($filters['user_id']) && $filters['user_id'] !== 'all') {
            $where[] = "s.user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }
        
        // Category
        if (!empty($filters['category_id']) && $filters['category_id'] !== 'all') {
            $where[] = "p.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        
        return [
            'where' => implode(' AND ', $where),
            'params' => $params
        ];
    }
    
    /**
     * Format currency for reports
     */
    public static function formatCurrency($amount, $currency = null) {
        return formatCurrency($amount);
    }
}

