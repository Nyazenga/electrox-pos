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

$pageTitle = 'Order Type Wise Sales Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCustomer = $_GET['customer_id'] ?? 'all';
$selectedCashier = $_GET['user_id'] ?? 'all';
$selectedStatus = $_GET['status'] ?? 'all';

// Get filter options
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

$customers = $db->getRows("SELECT DISTINCT c.* FROM customers c 
                          INNER JOIN sales s ON c.id = s.customer_id 
                          WHERE s.sale_date BETWEEN :start AND :end 
                          ORDER BY c.first_name, c.last_name", 
                          [':start' => $startDate, ':end' => $endDate]);
if ($customers === false) $customers = [];

$cashiers = $db->getRows("SELECT DISTINCT u.* FROM users u 
                         INNER JOIN sales s ON u.id = s.user_id 
                         WHERE s.sale_date BETWEEN :start AND :end 
                         ORDER BY u.first_name, u.last_name", 
                         [':start' => $startDate, ':end' => $endDate]);
if ($cashiers === false) $cashiers = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", ];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedCustomer !== 'all' && $selectedCustomer) {
    $whereConditions[] = "s.customer_id = :customer_id";
    $params[':customer_id'] = $selectedCustomer;
}

if ($selectedCashier !== 'all' && $selectedCashier) {
    $whereConditions[] = "s.user_id = :user_id";
    $params[':user_id'] = $selectedCashier;
}

if ($selectedStatus !== 'all' && $selectedStatus) {
    $whereConditions[] = "s.payment_status = :status";
    $params[':status'] = $selectedStatus;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary by order type (payment_status)
$orderTypes = $db->getRows("SELECT 
    s.payment_status as order_type,
    COUNT(DISTINCT s.id) as total_receipts,
    COALESCE(SUM(s.total_amount), 0) as total_amount,
    COALESCE(SUM(CASE WHEN s.payment_status = 'pending' THEN s.total_amount ELSE 0 END), 0) as advance_payment,
    COALESCE(SUM(CASE WHEN s.payment_status = 'pending' THEN s.total_amount ELSE 0 END), 0) as balance_payment
FROM sales s
WHERE $whereClause
GROUP BY s.payment_status
ORDER BY total_amount DESC", $params);

if ($orderTypes === false) {
    $orderTypes = [];
}

// Get summary totals
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_receipts,
    COALESCE(SUM(CASE WHEN s.payment_status = 'pending' THEN s.total_amount ELSE 0 END), 0) as total_advance_payment,
    COALESCE(SUM(s.total_amount), 0) as total_amount,
    COALESCE(SUM(CASE WHEN s.payment_status = 'pending' THEN s.total_amount ELSE 0 END), 0) as total_balance_payment
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_receipts' => 0,
        'total_advance_payment' => 0,
        'total_amount' => 0,
        'total_balance_payment' => 0
    ];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Order Type Wise Sales Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Receipts</td><td style="text-align: right;">' . $summary['total_receipts'] . '</td></tr>';
    $html .= '<tr><td>Total Advance Payment</td><td style="text-align: right;">' . formatCurrency($summary['total_advance_payment']) . '</td></tr>';
    $html .= '<tr><td>Total Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_amount']) . '</td></tr>';
    $html .= '<tr><td>Total Balance Payment</td><td style="text-align: right;">' . formatCurrency($summary['total_balance_payment']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Order Types</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Order Type</th><th style="text-align: right;">Receipts</th><th style="text-align: right;">Total Amount</th></tr>';
    foreach ($orderTypes as $ot) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml(ucfirst($ot['order_type'])) . '</td>';
        $html .= '<td style="text-align: right;">' . $ot['total_receipts'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($ot['total_amount']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Order Type Wise Sales Report', $html, 'Order_Type_Wise_Sales_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-list-check"></i> Order Type Wise Sales Report</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> Start Date</label>
                <input type="date" name="start_date" value="<?= $startDate ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> End Date</label>
                <input type="date" name="end_date" value="<?= $endDate ?>" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-person"></i> Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="all" <?= $selectedCustomer === 'all' ? 'selected' : '' ?>>All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= $customer['id'] ?>" <?= $selectedCustomer == $customer['id'] ? 'selected' : '' ?>><?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-person-badge"></i> Cashier</label>
                <select name="user_id" class="form-select">
                    <option value="all" <?= $selectedCashier === 'all' ? 'selected' : '' ?>>All Cashiers</option>
                    <?php foreach ($cashiers as $cashier): ?>
                        <option value="<?= $cashier['id'] ?>" <?= $selectedCashier == $cashier['id'] ? 'selected' : '' ?>><?= escapeHtml($cashier['first_name'] . ' ' . $cashier['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-info-circle"></i> Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="paid" <?= $selectedStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="pending" <?= $selectedStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="refunded" <?= $selectedStatus === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="order_type_wise_sales.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Receipts</h6>
                <h3 class="mb-0"><?= $summary['total_receipts'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Advance Payment</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_advance_payment']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Amount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_amount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Balance Payment</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_balance_payment']) ?></h3>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($orderTypes)): ?>
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Order Types (Pie Chart)</h5>
            </div>
            <div class="card-body">
                <canvas id="orderTypesChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Order Types (Bar Chart)</h5>
            </div>
            <div class="card-body">
                <canvas id="orderTypesBarChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Order Types</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="orderTypeWiseSalesTable">
                <thead>
                    <tr>
                        <th>Order Type</th>
                        <th class="text-end">Receipts</th>
                        <th class="text-end">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orderTypes)): ?>
                        <tr>
                            <td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orderTypes as $ot): ?>
                            <tr>
                                <td><span class="badge bg-<?= $ot['order_type'] === 'paid' ? 'success' : ($ot['order_type'] === 'pending' ? 'warning' : 'danger') ?>"><?= escapeHtml(ucfirst($ot['order_type'])) ?></span></td>
                                <td class="text-end"><?= $ot['total_receipts'] ?></td>
                                <td class="text-end"><strong><?= formatCurrency($ot['total_amount']) ?></strong></td>
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
(function() {
    // Wait for jQuery and Chart.js to be available
    function initCharts() {
        if (typeof jQuery === 'undefined') {
            console.error('jQuery is not loaded');
            setTimeout(initCharts, 100);
            return;
        }
        
        if (typeof Chart === 'undefined') {
            console.error('Chart.js is not loaded');
            setTimeout(initCharts, 100);
            return;
        }
        
        var $ = jQuery;
        
        $(document).ready(function() {
            // Initialize DataTable
            if ($.fn.DataTable) {
                var table = $('#orderTypeWiseSalesTable');
                if ($.fn.DataTable.isDataTable(table)) {
                    table.DataTable().destroy();
                }
                table.DataTable({
                    order: [[2, 'desc']],
                    pageLength: 25,
                    destroy: true,
                    autoWidth: false,
                    language: {
                        emptyTable: 'No data available'
                    }
                });
            }
            
            <?php if (!empty($orderTypes)): ?>
            // Initialize Pie Chart
            const orderTypesCtx = document.getElementById('orderTypesChart');
            if (orderTypesCtx && typeof Chart !== 'undefined') {
                new Chart(orderTypesCtx, {
                    type: 'pie',
                    data: {
                        labels: [<?= implode(',', array_map(function($ot) { return "'" . addslashes(ucfirst($ot['order_type'])) . "'"; }, $orderTypes)) ?>],
                        datasets: [{
                            data: [<?= implode(',', array_column($orderTypes, 'total_amount')) ?>],
                            backgroundColor: [
                                'rgba(25, 135, 84, 0.8)',
                                'rgba(255, 193, 7, 0.8)',
                                'rgba(220, 53, 69, 0.8)'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
            
            // Initialize Bar Chart
            const orderTypesBarCtx = document.getElementById('orderTypesBarChart');
            if (orderTypesBarCtx && typeof Chart !== 'undefined') {
                new Chart(orderTypesBarCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?= implode(',', array_map(function($ot) { return "'" . addslashes(ucfirst($ot['order_type'])) . "'"; }, $orderTypes)) ?>],
                        datasets: [{
                            label: 'Total Amount',
                            data: [<?= implode(',', array_column($orderTypes, 'total_amount')) ?>],
                            backgroundColor: [
                                'rgba(25, 135, 84, 0.8)',
                                'rgba(255, 193, 7, 0.8)',
                                'rgba(220, 53, 69, 0.8)'
                            ]
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
    }
    
    // Start initialization
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCharts);
    } else {
        initCharts();
    }
})();
</script>

