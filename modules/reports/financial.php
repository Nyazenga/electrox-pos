<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Financial Reports';

$db = Database::getInstance();
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$financial = $db->getRow("SELECT 
    SUM(CASE WHEN status = 'Paid' THEN total_amount ELSE 0 END) as paid,
    SUM(CASE WHEN status = 'Pending' THEN total_amount ELSE 0 END) as pending,
    SUM(total_amount) as total,
    SUM(tax_amount) as tax,
    SUM(discount_amount) as discount
    FROM invoices WHERE invoice_date BETWEEN :start AND :end", 
    [':start' => $startDate, ':end' => $endDate]);

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Financial Reports</h2>
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
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6>Paid Amount</h6>
                <h3><?= formatCurrency($financial['paid'] ?? 0) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h6>Pending Amount</h6>
                <h3><?= formatCurrency($financial['pending'] ?? 0) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6>Total Tax</h6>
                <h3><?= formatCurrency($financial['tax'] ?? 0) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-secondary text-white">
            <div class="card-body">
                <h6>Total Discount</h6>
                <h3><?= formatCurrency($financial['discount'] ?? 0) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Financial Summary</div>
    <div class="card-body">
        <table class="table table-bordered">
            <tr>
                <th width="30%">Total Revenue</th>
                <td><strong><?= formatCurrency($financial['total'] ?? 0) ?></strong></td>
            </tr>
            <tr>
                <th>Paid Amount</th>
                <td class="text-success"><strong><?= formatCurrency($financial['paid'] ?? 0) ?></strong></td>
            </tr>
            <tr>
                <th>Pending Amount</th>
                <td class="text-warning"><strong><?= formatCurrency($financial['pending'] ?? 0) ?></strong></td>
            </tr>
            <tr>
                <th>Tax Collected</th>
                <td><?= formatCurrency($financial['tax'] ?? 0) ?></td>
            </tr>
            <tr>
                <th>Discounts Given</th>
                <td><?= formatCurrency($financial['discount'] ?? 0) ?></td>
            </tr>
        </table>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

