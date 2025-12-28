<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('sales.view', 'sales.refund', 'receipts.refund');

$refundId = intval($_GET['id'] ?? 0);
$format = $_GET['format'] ?? 'A4';

if (!$refundId) {
    redirectTo('modules/sales/cancelled_sales.php');
}

$db = Database::getInstance();

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

// Get company settings
$companyName = getSetting('company_name', SYSTEM_NAME);
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');
$companyTIN = getSetting('company_tin', '');
$companyVAT = getSetting('company_vat_number', '');

// Generate PDF
$html = '<h2 style="text-align: center; color: #dc3545; margin-bottom: 10px;">CREDIT NOTE</h2>';
$html .= '<p style="text-align: center; font-size: 14px; color: #666; margin-bottom: 30px;">Credit Note #' . escapeHtml($refund['credit_note_number']) . '</p>';

$html .= '<table style="width: 100%; margin-bottom: 20px;">';
$html .= '<tr>';
$html .= '<td style="width: 50%; vertical-align: top;">';
$html .= '<h6 style="margin-bottom: 10px;"><strong>Company Information</strong></h6>';
$html .= '<p style="margin: 5px 0;">' . escapeHtml($companyName) . '</p>';
if ($companyAddress) {
    $html .= '<p style="margin: 5px 0;">' . escapeHtml($companyAddress) . '</p>';
}
if ($companyPhone) {
    $html .= '<p style="margin: 5px 0;">Phone: ' . escapeHtml($companyPhone) . '</p>';
}
if ($companyTIN) {
    $html .= '<p style="margin: 5px 0;">TIN: ' . escapeHtml($companyTIN) . '</p>';
}
$html .= '</td>';
$html .= '<td style="width: 50%; vertical-align: top;">';
$html .= '<h6 style="margin-bottom: 10px;"><strong>Credit Note Details</strong></h6>';
$html .= '<p style="margin: 5px 0;">Date: ' . date('M d, Y', strtotime($refund['refund_date'])) . '</p>';
$html .= '<p style="margin: 5px 0;">Refund #: ' . escapeHtml($refund['refund_number']) . '</p>';
$html .= '<p style="margin: 5px 0;">Original Receipt #: ' . escapeHtml($refund['receipt_number']) . '</p>';
$html .= '<p style="margin: 5px 0;">Branch: ' . escapeHtml($refund['branch_name'] ?? 'N/A') . '</p>';
$html .= '</td>';
$html .= '</tr>';
$html .= '</table>';

if ($refund['customer_id']) {
    $customerName = trim(($refund['first_name'] ?? '') . ' ' . ($refund['last_name'] ?? ''));
    if (empty($customerName)) {
        $customerName = $refund['company_name'] ?? 'Walk-in';
    }
    $html .= '<h6 style="margin-top: 20px; margin-bottom: 10px;"><strong>Customer Information</strong></h6>';
    $html .= '<p style="margin: 5px 0;">' . escapeHtml($customerName) . '</p>';
    if ($refund['email']) {
        $html .= '<p style="margin: 5px 0;">Email: ' . escapeHtml($refund['email']) . '</p>';
    }
    if ($refund['phone']) {
        $html .= '<p style="margin: 5px 0;">Phone: ' . escapeHtml($refund['phone']) . '</p>';
    }
}

if ($refund['reason']) {
    $html .= '<div style="background-color: #f0f0f0; padding: 10px; margin: 20px 0; border-left: 4px solid #dc3545;">';
    $html .= '<strong>Reason for Credit Note:</strong> ' . escapeHtml($refund['reason']);
    $html .= '</div>';
}

$html .= '<h6 style="margin-top: 20px; margin-bottom: 10px;"><strong>Credited Items</strong></h6>';
$html .= '<table border="1" cellpadding="8" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
$html .= '<tr style="background-color: #f8f9fa;">';
$html .= '<th style="text-align: left;">#</th>';
$html .= '<th style="text-align: left;">Product</th>';
$html .= '<th style="text-align: right;">Quantity</th>';
$html .= '<th style="text-align: right;">Unit Price</th>';
$html .= '<th style="text-align: right;">Total</th>';
$html .= '</tr>';

foreach ($creditNoteItems as $index => $item) {
    $html .= '<tr>';
    $html .= '<td>' . ($index + 1) . '</td>';
    $html .= '<td>' . escapeHtml($item['product_name']) . '</td>';
    $html .= '<td style="text-align: right;">' . $item['quantity'] . '</td>';
    $html .= '<td style="text-align: right;">' . formatCurrency($item['unit_price']) . '</td>';
    $html .= '<td style="text-align: right;">' . formatCurrency($item['total_price']) . '</td>';
    $html .= '</tr>';
}

$html .= '</table>';

$html .= '<div style="text-align: right; margin-top: 20px;">';
$html .= '<p style="margin: 5px 0;">Subtotal: <strong>' . formatCurrency($refund['subtotal']) . '</strong></p>';
if ($refund['discount_amount'] > 0) {
    $html .= '<p style="margin: 5px 0;">Discount: <strong>-' . formatCurrency($refund['discount_amount']) . '</strong></p>';
}
$html .= '<p style="margin: 10px 0; font-size: 18px; font-weight: bold; color: #dc3545; border-top: 2px solid #dc3545; padding-top: 10px;">Total Credit: ' . formatCurrency($refund['total_amount']) . '</p>';
$html .= '</div>';

if ($fiscalDetails) {
    $html .= '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 11px; color: #666;">';
    $html .= '<h6><strong>Fiscal Information</strong></h6>';
    $html .= '<p style="margin: 5px 0;">Receipt Global No: ' . escapeHtml($fiscalDetails['receipt_global_no'] ?? 'N/A') . '</p>';
    if (isset($fiscalDetails['verification_code'])) {
        $html .= '<p style="margin: 5px 0;">Verification Code: ' . escapeHtml($fiscalDetails['verification_code']) . '</p>';
    }
    $html .= '</div>';
}

if ($refund['notes']) {
    $html .= '<div style="margin-top: 20px;">';
    $html .= '<strong>Notes:</strong>';
    $html .= '<p>' . nl2br(escapeHtml($refund['notes'])) . '</p>';
    $html .= '</div>';
}

$filename = 'Credit_Note_' . $refund['credit_note_number'] . '_' . date('Ymd') . '.pdf';
ReportHelper::generatePDF('Credit Note', $html, $filename);
exit;

