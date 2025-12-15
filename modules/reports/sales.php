<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Sales Reports';

$db = Database::getInstance();
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$sales = $db->getRows("SELECT DATE(invoice_date) as date, COUNT(*) as count, SUM(total_amount) as total FROM invoices WHERE invoice_date BETWEEN :start AND :end GROUP BY DATE(invoice_date) ORDER BY date DESC", [':start' => $startDate, ':end' => $endDate]);
$totalSales = $db->getRow("SELECT COUNT(*) as count, SUM(total_amount) as total FROM invoices WHERE invoice_date BETWEEN :start AND :end", [':start' => $startDate, ':end' => $endDate]);

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Sales Reports</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label>Start Date</label>
                <input type="date" name="start_date" value="<?= $startDate ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label>End Date</label>
                <input type="date" name="end_date" value="<?= $endDate ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary d-block"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6>Total Sales</h6>
                <h3><?= formatCurrency($totalSales['total'] ?? 0) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6>Total Transactions</h6>
                <h3><?= $totalSales['count'] ?? 0 ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6>Average Sale</h6>
                <h3><?= $totalSales['count'] > 0 ? formatCurrency(($totalSales['total'] ?? 0) / $totalSales['count']) : formatCurrency(0) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Daily Sales Report</div>
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Transactions</th>
                    <th>Total Sales</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales as $sale): ?>
                    <tr>
                        <td><?= formatDate($sale['date']) ?></td>
                        <td><?= $sale['count'] ?></td>
                        <td><?= formatCurrency($sale['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

