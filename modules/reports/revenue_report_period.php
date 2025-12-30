<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.revenue_report_period');

$pageTitle = 'Revenue Report for Period for Branch';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');

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

// Get revenue summary
$revenue = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_sales,
    COALESCE(SUM(s.total_amount), 0) as gross_revenue,
    COALESCE(SUM(s.discount_amount), 0) as total_discounts,
    COALESCE(SUM(s.tax_amount), 0) as total_tax,
    COALESCE(SUM(s.total_amount - s.discount_amount), 0) as net_revenue,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
    COALESCE(SUM(s.total_amount - s.discount_amount) - SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as gross_profit
FROM sales s
LEFT JOIN sale_items si ON s.id = si.sale_id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause", $params);

if ($revenue === false) {
    $revenue = [
        'total_sales' => 0,
        'gross_revenue' => 0,
        'total_discounts' => 0,
        'total_tax' => 0,
        'net_revenue' => 0,
        'total_cost' => 0,
        'gross_profit' => 0
    ];
}

$profitMargin = $revenue['net_revenue'] > 0 ? (($revenue['gross_profit'] / $revenue['net_revenue']) * 100) : 0;

// Get daily revenue breakdown
$dailyRevenue = $db->getRows("SELECT 
    DATE(s.sale_date) as sale_date,
    COUNT(DISTINCT s.id) as sale_count,
    COALESCE(SUM(s.total_amount), 0) as gross_revenue,
    COALESCE(SUM(s.discount_amount), 0) as discounts,
    COALESCE(SUM(s.total_amount - s.discount_amount), 0) as net_revenue
FROM sales s
WHERE $whereClause
GROUP BY DATE(s.sale_date)
ORDER BY sale_date DESC", $params);

if ($dailyRevenue === false) {
    $dailyRevenue = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Revenue Report for Period</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . $revenue['total_sales'] . '</td></tr>';
    $html .= '<tr><td>Gross Revenue</td><td style="text-align: right;">' . formatCurrency($revenue['gross_revenue']) . '</td></tr>';
    $html .= '<tr><td>Total Discounts</td><td style="text-align: right;">' . formatCurrency($revenue['total_discounts']) . '</td></tr>';
    $html .= '<tr><td>Total Tax</td><td style="text-align: right;">' . formatCurrency($revenue['total_tax']) . '</td></tr>';
    $html .= '<tr><td>Net Revenue</td><td style="text-align: right;">' . formatCurrency($revenue['net_revenue']) . '</td></tr>';
    $html .= '<tr><td>Total Cost</td><td style="text-align: right;">' . formatCurrency($revenue['total_cost']) . '</td></tr>';
    $html .= '<tr><td>Gross Profit</td><td style="text-align: right;">' . formatCurrency($revenue['gross_profit']) . '</td></tr>';
    $html .= '<tr><td>Profit Margin</td><td style="text-align: right;">' . number_format($profitMargin, 2) . '%</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Daily Revenue Breakdown</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Date</th><th>Sales</th><th style="text-align: right;">Gross Revenue</th><th style="text-align: right;">Discounts</th><th style="text-align: right;">Net Revenue</th></tr>';
    foreach ($dailyRevenue as $day) {
        $html .= '<tr>';
        $html .= '<td>' . date('M d, Y', strtotime($day['sale_date'])) . '</td>';
        $html .= '<td>' . $day['sale_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['gross_revenue']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['discounts']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['net_revenue']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Revenue Report for Period', $html, 'Revenue_Report_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-stack"></i> Revenue Report for Period</h2>
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
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
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
                <h6 class="text-muted mb-2">Gross Revenue</h6>
                <h3 class="mb-0"><?= formatCurrency($revenue['gross_revenue']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Net Revenue</h6>
                <h3 class="mb-0 text-success"><?= formatCurrency($revenue['net_revenue']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Gross Profit</h6>
                <h3 class="mb-0 text-primary"><?= formatCurrency($revenue['gross_profit']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Profit Margin</h6>
                <h3 class="mb-0"><?= number_format($profitMargin, 2) ?>%</h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="revenueTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Sales</th>
                        <th class="text-end">Gross Revenue</th>
                        <th class="text-end">Discounts</th>
                        <th class="text-end">Net Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dailyRevenue)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No revenue data found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dailyRevenue as $day): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($day['sale_date'])) ?></td>
                                <td class="text-end"><?= $day['sale_count'] ?></td>
                                <td class="text-end"><?= formatCurrency($day['gross_revenue']) ?></td>
                                <td class="text-end"><?= formatCurrency($day['discounts']) ?></td>
                                <td class="text-end fw-bold text-success"><?= formatCurrency($day['net_revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#revenueTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


