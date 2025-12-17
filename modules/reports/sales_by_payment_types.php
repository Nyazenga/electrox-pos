<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Sales by Payment Types Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedCashier = $_GET['user_id'] ?? 'all';

// Get filter options
$cashiers = $db->getRows("SELECT DISTINCT u.* FROM users u 
                         INNER JOIN sales s ON u.id = s.user_id 
                         WHERE s.sale_date BETWEEN :start AND :end 
                         ORDER BY u.first_name, u.last_name", 
                         [':start' => $startDate, ':end' => $endDate]);
if ($cashiers === false) $cashiers = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedCashier !== 'all' && $selectedCashier) {
    $whereConditions[] = "s.user_id = :user_id";
    $params[':user_id'] = $selectedCashier;
}

$whereClause = implode(' AND ', $whereConditions);

// Get payment type breakdown
$paymentTypes = $db->getRows("SELECT 
    sp.payment_method,
    COUNT(DISTINCT sp.sale_id) as transaction_count,
    COALESCE(SUM(sp.base_amount), SUM(sp.amount), 0) as total_amount,
    COALESCE(AVG(sp.base_amount), AVG(sp.amount), 0) as avg_amount
FROM sale_payments sp
INNER JOIN sales s ON sp.sale_id = s.id
WHERE $whereClause
GROUP BY sp.payment_method
ORDER BY total_amount DESC", $params);

if ($paymentTypes === false) {
    $paymentTypes = [];
}

// Get total for summary
$total = array_sum(array_column($paymentTypes, 'total_amount'));

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales by Payment Types Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Payment Types</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Payment Method</th><th style="text-align: right;">Transactions</th><th style="text-align: right;">Total Amount</th><th style="text-align: right;">Average</th><th style="text-align: right;">Percentage</th></tr>';
    foreach ($paymentTypes as $pt) {
        $percentage = $total > 0 ? ($pt['total_amount'] / $total * 100) : 0;
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml(ucfirst($pt['payment_method'])) . '</td>';
        $html .= '<td style="text-align: right;">' . $pt['transaction_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($pt['total_amount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($pt['avg_amount']) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($percentage, 2) . '%</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales by Payment Types Report', $html, 'Sales_by_Payment_Types_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-credit-card"></i> Sales by Payment Types Report</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-calendar"></i> Start Date</label>
                <input type="date" name="start_date" value="<?= $startDate ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-calendar"></i> End Date</label>
                <input type="date" name="end_date" value="<?= $endDate ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-person-badge"></i> Cashier</label>
                <select name="user_id" class="form-select">
                    <option value="all" <?= $selectedCashier === 'all' ? 'selected' : '' ?>>All Cashiers</option>
                    <?php foreach ($cashiers as $cashier): ?>
                        <option value="<?= $cashier['id'] ?>" <?= $selectedCashier == $cashier['id'] ? 'selected' : '' ?>><?= escapeHtml($cashier['first_name'] . ' ' . $cashier['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="sales_by_payment_types.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($paymentTypes)): ?>
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Payment Types (Pie Chart)</h5>
            </div>
            <div class="card-body">
                <canvas id="paymentTypesChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Payment Types (Bar Chart)</h5>
            </div>
            <div class="card-body">
                <canvas id="paymentTypesBarChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Payment Types</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="salesByPaymentTypesTable">
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th class="text-end">Transactions</th>
                        <th class="text-end">Total Amount</th>
                        <th class="text-end">Average</th>
                        <th class="text-end">Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paymentTypes)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($paymentTypes as $pt): ?>
                            <?php $percentage = $total > 0 ? ($pt['total_amount'] / $total * 100) : 0; ?>
                            <tr>
                                <td><strong><?= escapeHtml(ucfirst($pt['payment_method'])) ?></strong></td>
                                <td class="text-end"><?= $pt['transaction_count'] ?></td>
                                <td class="text-end"><?= formatCurrency($pt['total_amount']) ?></td>
                                <td class="text-end"><?= formatCurrency($pt['avg_amount']) ?></td>
                                <td class="text-end">
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar" role="progressbar" style="width: <?= $percentage ?>%" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100"><?= number_format($percentage, 1) ?>%</div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Wait for jQuery to be available
    if (typeof jQuery === 'undefined') {
        console.error('jQuery is not loaded');
        return;
    }
    
    var $ = jQuery;
    
    if ($.fn.DataTable) {
        var table = $('#salesByPaymentTypesTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        table.DataTable({
            order: [[2, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
    
    <?php if (!empty($paymentTypes)): ?>
    // Payment Types Pie Chart
    const paymentTypesCtx = document.getElementById('paymentTypesChart');
    if (paymentTypesCtx && typeof Chart !== 'undefined') {
        new Chart(paymentTypesCtx, {
            type: 'pie',
            data: {
                labels: [<?= implode(',', array_map(function($pt) { return "'" . addslashes(ucfirst($pt['payment_method'])) . "'"; }, $paymentTypes)) ?>],
                datasets: [{
                    data: [<?= implode(',', array_column($paymentTypes, 'total_amount')) ?>],
                    backgroundColor: [
                        'rgba(30, 58, 138, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(96, 165, 250, 0.8)',
                        'rgba(147, 197, 253, 0.8)',
                        'rgba(191, 219, 254, 0.8)',
                        'rgba(219, 234, 254, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }
    
    // Payment Types Bar Chart
    const paymentTypesBarCtx = document.getElementById('paymentTypesBarChart');
    if (paymentTypesBarCtx && typeof Chart !== 'undefined') {
        new Chart(paymentTypesBarCtx, {
            type: 'bar',
            data: {
                labels: [<?= implode(',', array_map(function($pt) { return "'" . addslashes(ucfirst($pt['payment_method'])) . "'"; }, $paymentTypes)) ?>],
                datasets: [{
                    label: 'Total Amount',
                    data: [<?= implode(',', array_column($paymentTypes, 'total_amount')) ?>],
                    backgroundColor: 'rgba(30, 58, 138, 0.8)',
                    borderColor: 'rgba(30, 58, 138, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>

