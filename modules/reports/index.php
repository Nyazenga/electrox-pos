<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Reports';

require_once APP_PATH . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <i class="bi bi-graph-up" style="font-size: 48px; color: #1e3a8a;"></i>
                <h5 class="mt-3">Sales Reports</h5>
                <a href="sales.php" class="btn btn-primary btn-sm">View Reports</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <i class="bi bi-archive" style="font-size: 48px; color: #1e3a8a;"></i>
                <h5 class="mt-3">Inventory Reports</h5>
                <a href="inventory.php" class="btn btn-primary btn-sm">View Reports</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <i class="bi bi-cash-coin" style="font-size: 48px; color: #1e3a8a;"></i>
                <h5 class="mt-3">Financial Reports</h5>
                <a href="financial.php" class="btn btn-primary btn-sm">View Reports</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <i class="bi bi-people" style="font-size: 48px; color: #1e3a8a;"></i>
                <h5 class="mt-3">Customer Reports</h5>
                <a href="customers.php" class="btn btn-primary btn-sm">View Reports</a>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

