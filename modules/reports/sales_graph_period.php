<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.sales_graph_period');

$pageTitle = 'Sales Graph for Period for Branch';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$graphType = $_GET['graph_type'] ?? 'daily'; // daily, weekly, monthly

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

$whereClause = implode(' AND ', $whereConditions);

// Get daily sales data
$dailySales = $db->getRows("SELECT 
    DATE(s.sale_date) as sale_date,
    COUNT(DISTINCT s.id) as sale_count,
    COALESCE(SUM(s.total_amount), 0) as gross_sales,
    COALESCE(SUM(s.discount_amount), 0) as discounts,
    COALESCE(SUM(s.total_amount - s.discount_amount), 0) as net_sales
FROM sales s
WHERE $whereClause
GROUP BY DATE(s.sale_date)
ORDER BY sale_date ASC", $params);

if ($dailySales === false) {
    $dailySales = [];
}

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT DATE(s.sale_date)) as total_days,
    COUNT(DISTINCT s.id) as total_sales,
    COALESCE(SUM(s.total_amount), 0) as gross_sales,
    COALESCE(SUM(s.discount_amount), 0) as total_discounts,
    COALESCE(SUM(s.total_amount - s.discount_amount), 0) as net_sales,
    COALESCE(AVG(s.total_amount - s.discount_amount), 0) as avg_daily_sales
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_days' => 0,
        'total_sales' => 0,
        'gross_sales' => 0,
        'total_discounts' => 0,
        'net_sales' => 0,
        'avg_daily_sales' => 0
    ];
}

// Prepare chart data
$chartLabels = [];
$chartNetSales = [];
$chartGrossSales = [];
$chartDiscounts = [];

foreach ($dailySales as $day) {
    $chartLabels[] = date('M d', strtotime($day['sale_date']));
    $chartNetSales[] = floatval($day['net_sales']);
    $chartGrossSales[] = floatval($day['gross_sales']);
    $chartDiscounts[] = floatval($day['discounts']);
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales Graph for Period</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Days</td><td style="text-align: right;">' . $summary['total_days'] . '</td></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . $summary['total_sales'] . '</td></tr>';
    $html .= '<tr><td>Gross Sales</td><td style="text-align: right;">' . formatCurrency($summary['gross_sales']) . '</td></tr>';
    $html .= '<tr><td>Total Discounts</td><td style="text-align: right;">' . formatCurrency($summary['total_discounts']) . '</td></tr>';
    $html .= '<tr><td>Net Sales</td><td style="text-align: right;">' . formatCurrency($summary['net_sales']) . '</td></tr>';
    $html .= '<tr><td>Average Daily Sales</td><td style="text-align: right;">' . formatCurrency($summary['avg_daily_sales']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Daily Sales Breakdown</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Date</th><th>Sales Count</th><th style="text-align: right;">Gross Sales</th><th style="text-align: right;">Discounts</th><th style="text-align: right;">Net Sales</th></tr>';
    foreach ($dailySales as $day) {
        $html .= '<tr>';
        $html .= '<td>' . date('M d, Y', strtotime($day['sale_date'])) . '</td>';
        $html .= '<td>' . $day['sale_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['gross_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['discounts']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['net_sales']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales Graph for Period', $html, 'Sales_Graph_Period_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-graph-up"></i> Sales Graph for Period</h2>
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
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-graph-up"></i> Graph Type</label>
                <select name="graph_type" class="form-select">
                    <option value="daily" <?= $graphType === 'daily' ? 'selected' : '' ?>>Daily</option>
                    <option value="weekly" <?= $graphType === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                    <option value="monthly" <?= $graphType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                </select>
            </div>
            <div class="col-md-12 d-flex align-items-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Days</h6>
                <h3 class="mb-0"><?= $summary['total_days'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Sales</h6>
                <h3 class="mb-0"><?= $summary['total_sales'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Net Sales</h6>
                <h3 class="mb-0 text-success"><?= formatCurrency($summary['net_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Avg Daily Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['avg_daily_sales']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Sales Trend</h5>
    </div>
    <div class="card-body">
        <canvas id="salesChart" height="80"></canvas>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="salesTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Sales Count</th>
                        <th class="text-end">Gross Sales</th>
                        <th class="text-end">Discounts</th>
                        <th class="text-end">Net Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dailySales)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No sales found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dailySales as $day): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($day['sale_date'])) ?></td>
                                <td class="text-end"><?= $day['sale_count'] ?></td>
                                <td class="text-end"><?= formatCurrency($day['gross_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($day['discounts']) ?></td>
                                <td class="text-end fw-bold text-success"><?= formatCurrency($day['net_sales']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    $('#salesTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: true
    });
    
    // Sales Chart
    const ctx = document.getElementById('salesChart').getContext('2d');
    const salesChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Net Sales',
                data: <?= json_encode($chartNetSales) ?>,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Gross Sales',
                data: <?= json_encode($chartGrossSales) ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                tension: 0.4,
                fill: false
            }, {
                label: 'Discounts',
                data: <?= json_encode($chartDiscounts) ?>,
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                tension: 0.4,
                fill: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '<?= getCurrencySymbol() ?>' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


