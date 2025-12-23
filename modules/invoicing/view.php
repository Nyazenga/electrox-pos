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
$invoice = $db->getRow("SELECT i.*, c.first_name, c.last_name, c.email, c.phone, c.address, b.branch_name, u.first_name as user_first, u.last_name as user_last 
    FROM invoices i 
    LEFT JOIN customers c ON i.customer_id = c.id 
    LEFT JOIN branches b ON i.branch_id = b.id
    LEFT JOIN users u ON i.user_id = u.id
    WHERE i.id = :id", [':id' => $id]);

if (!$invoice) {
    redirectTo('modules/invoicing/index.php');
}

$invoiceItems = $db->getRows("SELECT ii.*, p.brand, p.model FROM invoice_items ii LEFT JOIN products p ON ii.product_id = p.id WHERE ii.invoice_id = :id", [':id' => $id]);
if ($invoiceItems === false) $invoiceItems = [];

$pageTitle = 'Invoice #' . $invoice['invoice_number'];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Invoice #<?= escapeHtml($invoice['invoice_number']) ?></h2>
    <div class="btn-group">
        <button onclick="window.open('print.php?id=<?= $invoice['id'] ?>', '_blank')" class="btn btn-primary">
            <i class="bi bi-printer"></i> Print
        </button>
        <a href="index.php" class="btn btn-secondary">Back</a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Invoice Details</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Invoice Type:</strong><br>
                        <span class="badge bg-info"><?= escapeHtml($invoice['invoice_type']) ?></span>
                    </div>
                    <div class="col-md-6">
                        <strong>Status:</strong><br>
                        <?php
                        $statusColors = [
                            'Paid' => 'success',
                            'Sent' => 'primary',
                            'Draft' => 'secondary',
                            'Overdue' => 'danger',
                            'Void' => 'dark'
                        ];
                        $color = $statusColors[$invoice['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $color ?>"><?= escapeHtml($invoice['status']) ?></span>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Invoice Date:</strong><br>
                        <?= formatDateTime($invoice['invoice_date']) ?>
                    </div>
                    <?php if ($invoice['due_date']): ?>
                    <div class="col-md-6">
                        <strong>Due Date:</strong><br>
                        <?= formatDate($invoice['due_date']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($invoice['notes']): ?>
                <div class="mb-3">
                    <strong>Notes:</strong><br>
                    <?= nl2br(escapeHtml($invoice['notes'])) ?>
                </div>
                <?php endif; ?>
                <?php if ($invoice['terms']): ?>
                <div class="mb-3">
                    <strong>Terms & Conditions:</strong><br>
                    <?= nl2br(escapeHtml($invoice['terms'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Items</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th>Discount</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoiceItems as $index => $item): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <?php if ($item['product_id']): ?>
                                            <?= escapeHtml(($item['brand'] ?? '') . ' ' . ($item['model'] ?? '')) ?>
                                        <?php else: ?>
                                            <?= escapeHtml($item['description'] ?? '') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $item['quantity'] ?></td>
                                    <td><?= formatCurrency($item['unit_price']) ?></td>
                                    <td><?= $item['discount_percentage'] > 0 ? $item['discount_percentage'] . '%' : '-' ?></td>
                                    <td><strong><?= formatCurrency($item['line_total']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Subtotal:</strong></td>
                                <td><strong><?= formatCurrency($invoice['subtotal']) ?></strong></td>
                            </tr>
                            <?php if ($invoice['discount_amount'] > 0): ?>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Discount:</strong></td>
                                <td><strong class="text-warning">-<?= formatCurrency($invoice['discount_amount']) ?></strong></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($invoice['tax_amount'] > 0): ?>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Tax:</strong></td>
                                <td><strong><?= formatCurrency($invoice['tax_amount']) ?></strong></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Total:</strong></td>
                                <td><strong style="font-size: 18px; color: var(--primary-blue);"><?= formatCurrency($invoice['total_amount']) ?></strong></td>
                            </tr>
                            <?php if ($invoice['balance_due'] > 0): ?>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Balance Due:</strong></td>
                                <td><strong class="text-danger"><?= formatCurrency($invoice['balance_due']) ?></strong></td>
                            </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Customer</h5>
            </div>
            <div class="card-body">
                <p>
                    <strong><?= escapeHtml(trim(($invoice['first_name'] ?? '') . ' ' . ($invoice['last_name'] ?? 'Walk-in Customer'))) ?></strong><br>
                    <?php if ($invoice['email']): ?>
                        <?= escapeHtml($invoice['email']) ?><br>
                    <?php endif; ?>
                    <?php if ($invoice['phone']): ?>
                        <?= escapeHtml($invoice['phone']) ?><br>
                    <?php endif; ?>
                    <?php if ($invoice['address']): ?>
                        <?= nl2br(escapeHtml($invoice['address'])) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Created By</h5>
            </div>
            <div class="card-body">
                <p><?= escapeHtml(trim(($invoice['user_first'] ?? '') . ' ' . ($invoice['user_last'] ?? ''))) ?></p>
            </div>
        </div>
        
        <?php if ($invoice['branch_name']): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Branch</h5>
            </div>
            <div class="card-body">
                <p><?= escapeHtml($invoice['branch_name']) ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


