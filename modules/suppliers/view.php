<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('suppliers.view');

$pageTitle = 'Supplier Details';

$db = Database::getInstance();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    redirectTo('modules/suppliers/index.php');
}

$supplier = $db->getRow("SELECT * FROM suppliers WHERE id = :id", [':id' => $id]);

if (!$supplier) {
    redirectTo('modules/suppliers/index.php');
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Supplier Details</h2>
    <div>
        <?php if ($auth->hasPermission('suppliers.edit')): ?>
            <a href="edit.php?id=<?= $id ?>" class="btn btn-warning"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Supplier Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="200">Supplier Code:</th>
                        <td><?= escapeHtml($supplier['supplier_code'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Name:</th>
                        <td><?= escapeHtml($supplier['name']) ?></td>
                    </tr>
                    <tr>
                        <th>Contact Person:</th>
                        <td><?= escapeHtml($supplier['contact_person'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?= escapeHtml($supplier['phone'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?= escapeHtml($supplier['email'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Address:</th>
                        <td><?= nl2br(escapeHtml($supplier['address'] ?? 'N/A')) ?></td>
                    </tr>
                    <tr>
                        <th>TIN Number:</th>
                        <td><?= escapeHtml($supplier['tin'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Payment Terms:</th>
                        <td><?= escapeHtml($supplier['payment_terms'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Credit Limit:</th>
                        <td><?= formatCurrency($supplier['credit_limit'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td>
                            <span class="badge bg-<?= ($supplier['status'] ?? 'Active') == 'Active' ? 'success' : 'secondary' ?>">
                                <?= escapeHtml($supplier['status'] ?? 'Active') ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


