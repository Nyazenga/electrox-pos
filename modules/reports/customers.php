<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Customer Reports';

$db = Database::getInstance();
$topCustomers = $db->getRows("SELECT c.*, SUM(i.total_amount) as total_spent, COUNT(i.id) as invoice_count FROM customers c LEFT JOIN invoices i ON c.id = i.customer_id WHERE i.status = 'Paid' GROUP BY c.id ORDER BY total_spent DESC LIMIT 10");
$customerStats = $db->getRow("SELECT COUNT(*) as total, COUNT(CASE WHEN status = 'Active' THEN 1 END) as active FROM customers");

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Customer Reports</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6>Total Customers</h6>
                <h3><?= $customerStats['total'] ?? 0 ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6>Active Customers</h6>
                <h3><?= $customerStats['active'] ?? 0 ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Top Customers by Spending</div>
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Total Spent</th>
                    <th>Invoices</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topCustomers as $customer): ?>
                    <tr>
                        <td><?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?></td>
                        <td><?= escapeHtml($customer['email']) ?></td>
                        <td><?= escapeHtml($customer['phone']) ?></td>
                        <td><strong><?= formatCurrency($customer['total_spent'] ?? 0) ?></strong></td>
                        <td><?= $customer['invoice_count'] ?? 0 ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

