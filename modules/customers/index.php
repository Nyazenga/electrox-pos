<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('customers.view');

$pageTitle = 'Customers';

$db = Database::getInstance();
$customers = $db->getRows("SELECT * FROM customers ORDER BY created_at DESC");

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Customers</h2>
    <?php if ($auth->hasPermission('customers.create')): ?>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Customer</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Customer Code</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td><?= escapeHtml($customer['customer_code']) ?></td>
                        <td><?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?></td>
                        <td><?= escapeHtml($customer['email']) ?></td>
                        <td><?= escapeHtml($customer['phone']) ?></td>
                        <td><span class="badge bg-<?= $customer['status'] == 'Active' ? 'success' : 'secondary' ?>"><?= escapeHtml($customer['status']) ?></span></td>
                        <td>
                            <a href="view.php?id=<?= $customer['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                            <?php if ($auth->hasPermission('customers.edit')): ?>
                                <a href="edit.php?id=<?= $customer['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

