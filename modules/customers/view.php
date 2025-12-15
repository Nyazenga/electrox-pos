<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('customers.view');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    redirectTo('modules/customers/index.php');
}

$pageTitle = 'View Customer';

$db = Database::getInstance();
$customer = $db->getRow("SELECT * FROM customers WHERE id = :id", [':id' => $id]);

if (!$customer) {
    redirectTo('modules/customers/index.php');
}

$invoices = $db->getRows("SELECT * FROM invoices WHERE customer_id = :id ORDER BY created_at DESC LIMIT 10", [':id' => $id]);
$totalSpent = $db->getRow("SELECT SUM(total_amount) as total FROM invoices WHERE customer_id = :id AND status = 'Paid'", [':id' => $id]);

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Customer Details</h2>
    <div>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        <?php if ($auth->hasPermission('customers.edit')): ?>
            <a href="edit.php?id=<?= $customer['id'] ?>" class="btn btn-warning"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Customer Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Customer Code:</th>
                        <td><?= escapeHtml($customer['customer_code']) ?></td>
                    </tr>
                    <tr>
                        <th>Name:</th>
                        <td><?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?= escapeHtml($customer['email']) ?></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?= escapeHtml($customer['phone']) ?></td>
                    </tr>
                    <tr>
                        <th>Address:</th>
                        <td><?= escapeHtml($customer['address'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><span class="badge bg-<?= $customer['status'] == 'Active' ? 'success' : 'secondary' ?>"><?= escapeHtml($customer['status']) ?></span></td>
                    </tr>
                    <tr>
                        <th>Registered:</th>
                        <td><?= formatDateTime($customer['created_at']) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Purchase Statistics</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h6>Total Spent</h6>
                    <h3 class="text-primary"><?= formatCurrency($totalSpent['total'] ?? 0) ?></h3>
                </div>
                <div class="mb-3">
                    <h6>Total Invoices</h6>
                    <h3 class="text-info"><?= count($invoices) ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Recent Invoices</h5>
    </div>
    <div class="card-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="5" class="text-center">No invoices found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><?= escapeHtml($invoice['invoice_number']) ?></td>
                            <td><?= formatDate($invoice['invoice_date']) ?></td>
                            <td><?= formatCurrency($invoice['total_amount']) ?></td>
                            <td><span class="badge bg-<?= $invoice['status'] == 'Paid' ? 'success' : 'warning' ?>"><?= escapeHtml($invoice['status']) ?></span></td>
                            <td>
                                <a href="../invoicing/view.php?id=<?= $invoice['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

