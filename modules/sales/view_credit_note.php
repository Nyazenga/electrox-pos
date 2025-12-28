<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('sales.view', 'sales.refund', 'receipts.refund');

$refundId = intval($_GET['id'] ?? 0);
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

// Get company settings
$companyName = getSetting('company_name', SYSTEM_NAME);
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');
$companyTIN = getSetting('company_tin', '');
$companyVAT = getSetting('company_vat_number', '');

$pageTitle = 'Credit Note - ' . $refund['credit_note_number'];
require_once APP_PATH . '/includes/header.php';
?>

<style>
.credit-note-container {
    max-width: 800px;
    margin: 0 auto;
    background: white;
    padding: 30px;
    border: 1px solid #ddd;
}

.credit-note-header {
    text-align: center;
    margin-bottom: 30px;
    border-bottom: 3px solid #dc3545;
    padding-bottom: 20px;
}

.credit-note-title {
    font-size: 28px;
    font-weight: bold;
    color: #dc3545;
    margin-bottom: 10px;
}

.credit-note-number {
    font-size: 18px;
    color: #666;
}

.credit-note-body {
    margin-bottom: 30px;
}

.info-section {
    margin-bottom: 20px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
}

.info-label {
    font-weight: bold;
    color: #333;
}

.info-value {
    color: #666;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
}

.items-table th,
.items-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.items-table th {
    background-color: #f8f9fa;
    font-weight: bold;
}

.items-table .text-right {
    text-align: right;
}

.total-section {
    margin-top: 20px;
    text-align: right;
}

.total-row {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 10px;
}

.total-label {
    font-weight: bold;
    margin-right: 20px;
    min-width: 150px;
    text-align: right;
}

.total-value {
    min-width: 120px;
    text-align: right;
}

.grand-total {
    font-size: 20px;
    font-weight: bold;
    color: #dc3545;
    border-top: 2px solid #dc3545;
    padding-top: 10px;
    margin-top: 10px;
}

.fiscal-info {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #ddd;
    font-size: 12px;
    color: #666;
}

.qr-code {
    text-align: center;
    margin: 20px 0;
}

@media print {
    .no-print {
        display: none;
    }
    .credit-note-container {
        border: none;
        padding: 0;
    }
}
</style>

<div class="credit-note-container">
    <div class="credit-note-header">
        <div class="credit-note-title">CREDIT NOTE</div>
        <div class="credit-note-number">Credit Note #<?= escapeHtml($refund['credit_note_number']) ?></div>
    </div>
    
    <div class="credit-note-body">
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="info-section">
                    <h6><strong>Company Information</strong></h6>
                    <div class="info-row">
                        <span class="info-label">Name:</span>
                        <span class="info-value"><?= escapeHtml($companyName) ?></span>
                    </div>
                    <?php if ($companyAddress): ?>
                    <div class="info-row">
                        <span class="info-label">Address:</span>
                        <span class="info-value"><?= escapeHtml($companyAddress) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($companyPhone): ?>
                    <div class="info-row">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?= escapeHtml($companyPhone) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($companyTIN): ?>
                    <div class="info-row">
                        <span class="info-label">TIN:</span>
                        <span class="info-value"><?= escapeHtml($companyTIN) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="info-section">
                    <h6><strong>Credit Note Details</strong></h6>
                    <div class="info-row">
                        <span class="info-label">Date:</span>
                        <span class="info-value"><?= date('M d, Y', strtotime($refund['refund_date'])) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Refund #:</span>
                        <span class="info-value"><?= escapeHtml($refund['refund_number']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Original Receipt #:</span>
                        <span class="info-value"><?= escapeHtml($refund['receipt_number']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Branch:</span>
                        <span class="info-value"><?= escapeHtml($refund['branch_name'] ?? 'N/A') ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($refund['customer_id']): ?>
        <div class="info-section mb-4">
            <h6><strong>Customer Information</strong></h6>
            <div class="info-row">
                <span class="info-label">Name:</span>
                <span class="info-value"><?= escapeHtml(trim(($refund['first_name'] ?? '') . ' ' . ($refund['last_name'] ?? '')) ?: ($refund['company_name'] ?? 'Walk-in')) ?></span>
            </div>
            <?php if ($refund['email']): ?>
            <div class="info-row">
                <span class="info-label">Email:</span>
                <span class="info-value"><?= escapeHtml($refund['email']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($refund['phone']): ?>
            <div class="info-row">
                <span class="info-label">Phone:</span>
                <span class="info-value"><?= escapeHtml($refund['phone']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($refund['reason']): ?>
        <div class="alert alert-info mb-4">
            <strong>Reason for Credit Note:</strong> <?= escapeHtml($refund['reason']) ?>
        </div>
        <?php endif; ?>
        
        <h6><strong>Credited Items</strong></h6>
        <table class="items-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($creditNoteItems as $index => $item): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= escapeHtml($item['product_name']) ?></td>
                        <td class="text-right"><?= $item['quantity'] ?></td>
                        <td class="text-right"><?= formatCurrency($item['unit_price']) ?></td>
                        <td class="text-right"><?= formatCurrency($item['total_price']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="total-section">
            <div class="total-row">
                <span class="total-label">Subtotal:</span>
                <span class="total-value"><?= formatCurrency($refund['subtotal']) ?></span>
            </div>
            <?php if ($refund['discount_amount'] > 0): ?>
            <div class="total-row">
                <span class="total-label">Discount:</span>
                <span class="total-value">-<?= formatCurrency($refund['discount_amount']) ?></span>
            </div>
            <?php endif; ?>
            <div class="total-row grand-total">
                <span class="total-label">Total Credit:</span>
                <span class="total-value"><?= formatCurrency($refund['total_amount']) ?></span>
            </div>
        </div>
        
        <?php if ($fiscalDetails): ?>
        <div class="fiscal-info">
            <h6><strong>Fiscal Information</strong></h6>
            <div class="info-row">
                <span class="info-label">Receipt Global No:</span>
                <span class="info-value"><?= escapeHtml($fiscalDetails['receipt_global_no'] ?? 'N/A') ?></span>
            </div>
            <?php if (isset($fiscalDetails['verification_code'])): ?>
            <div class="info-row">
                <span class="info-label">Verification Code:</span>
                <span class="info-value"><?= escapeHtml($fiscalDetails['verification_code']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (isset($fiscalDetails['qr_code_image'])): ?>
            <div class="qr-code">
                <img src="data:image/png;base64,<?= $fiscalDetails['qr_code_image'] ?>" alt="QR Code" style="max-width: 200px;">
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($refund['notes']): ?>
        <div class="mt-4">
            <strong>Notes:</strong>
            <p><?= nl2br(escapeHtml($refund['notes'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="text-center mt-4 no-print">
        <button onclick="window.print()" class="btn btn-primary me-2">
            <i class="bi bi-printer"></i> Print
        </button>
        <a href="cancelled_sales.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to Cancelled Sales
        </a>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

