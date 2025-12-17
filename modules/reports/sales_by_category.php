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

$pageTitle = 'Sales by Category Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedCustomer = $_GET['customer_id'] ?? 'all';
$selectedCashier = $_GET['user_id'] ?? 'all';

// Get filter options
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
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedCustomer !== 'all' && $selectedCustomer) {
    $whereConditions[] = "s.customer_id = :customer_id";
    $params[':customer_id'] = $selectedCustomer;
}

if ($selectedCashier !== 'all' && $selectedCashier) {
    $whereConditions[] = "s.user_id = :user_id";
    $params[':user_id'] = $selectedCashier;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary
$summary = $db->getRow("SELECT 
    COALESCE(SUM(si.total_price), 0) as total_gross_sale,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as total_net_sale,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0) - (si.quantity * COALESCE(p.cost_price, 0))), 0) as total_profit
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_gross_sale' => 0,
        'total_net_sale' => 0,
        'total_cost' => 0,
        'total_profit' => 0
    ];
}

// Get category sales
$categorySales = $db->getRows("SELECT 
    COALESCE(pc.name, 'No category') as category_name,
    NULL as sub_category,
    SUM(si.quantity) as sold_qty,
    SUM(si.total_price) as category_gross_sales,
    COALESCE(SUM(CASE WHEN s.payment_status = 'refunded' THEN si.total_price ELSE 0 END), 0) as refunds,
    COALESCE(SUM(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0))), 0) as discounts,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as category_net_sales,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as category_cost,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0) - (si.quantity * COALESCE(p.cost_price, 0))), 0) as category_gross_profit
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE $whereClause
GROUP BY pc.id, pc.name
ORDER BY category_net_sales DESC", $params);

if ($categorySales === false) {
    $categorySales = [];
}

// Calculate profit margins
foreach ($categorySales as &$category) {
    $category['profit_margin'] = $category['category_net_sales'] > 0 
        ? (($category['category_gross_profit'] / $category['category_net_sales']) * 100) 
        : 0;
}
unset($category);

// Get top 5 categories for chart
$topCategories = array_slice($categorySales, 0, 5);

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales by Category Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Amount</th></tr>';
    $html .= '<tr><td>Total Gross Sale</td><td style="text-align: right;">' . formatCurrency($summary['total_gross_sale']) . '</td></tr>';
    $html .= '<tr><td>Total Net Sale</td><td style="text-align: right;">' . formatCurrency($summary['total_net_sale']) . '</td></tr>';
    $html .= '<tr><td>Total Cost</td><td style="text-align: right;">' . formatCurrency($summary['total_cost']) . '</td></tr>';
    $html .= '<tr><td>Total Profit</td><td style="text-align: right;">' . formatCurrency($summary['total_profit']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Category Sales</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Category</th><th>Sub Category</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Gross Sales</th><th style="text-align: right;">Refunds</th><th style="text-align: right;">Discounts</th><th style="text-align: right;">Net Sales</th><th style="text-align: right;">Cost</th><th style="text-align: right;">Profit</th><th style="text-align: right;">Margin %</th></tr>';
    foreach ($categorySales as $cat) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($cat['category_name']) . '</td>';
        $html .= '<td>' . escapeHtml($cat['sub_category'] ?? '') . '</td>';
        $html .= '<td style="text-align: right;">' . $cat['sold_qty'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($cat['category_gross_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($cat['refunds']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($cat['discounts']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($cat['category_net_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($cat['category_cost']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($cat['category_gross_profit']) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($cat['profit_margin'], 2) . '%</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales by Category Report', $html, 'Sales_by_Category_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-tags"></i> Sales by Category Report</h2>
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
                <label class="form-label"><i class="bi bi-person"></i> Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="all" <?= $selectedCustomer === 'all' ? 'selected' : '' ?>>All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= $customer['id'] ?>" <?= $selectedCustomer == $customer['id'] ? 'selected' : '' ?>><?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
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
                <a href="sales_by_category.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Gross Sale</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_gross_sale']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Net Sale</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_net_sale']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Cost</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_cost']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Profit</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_profit']) ?></h3>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($topCategories)): ?>
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Top 5 Categories by Net Sales</h5>
            </div>
            <div class="card-body">
                <canvas id="topCategoriesChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Top 5 Categories (Pie Chart)</h5>
            </div>
            <div class="card-body">
                <canvas id="topCategoriesPieChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Category Sales</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="salesByCategoryTable">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Sub Category</th>
                        <th class="text-end">Sold Qty</th>
                        <th class="text-end">Gross Sales</th>
                        <th class="text-end">Refunds</th>
                        <th class="text-end">Discounts</th>
                        <th class="text-end">Net Sales</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Profit</th>
                        <th class="text-end">Margin %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categorySales)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categorySales as $cat): ?>
                            <tr>
                                <td><?= escapeHtml($cat['category_name']) ?></td>
                                <td><?= escapeHtml($cat['sub_category'] ?? '') ?></td>
                                <td class="text-end"><?= $cat['sold_qty'] ?></td>
                                <td class="text-end"><?= formatCurrency($cat['category_gross_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($cat['refunds']) ?></td>
                                <td class="text-end"><?= formatCurrency($cat['discounts']) ?></td>
                                <td class="text-end"><?= formatCurrency($cat['category_net_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($cat['category_cost']) ?></td>
                                <td class="text-end"><?= formatCurrency($cat['category_gross_profit']) ?></td>
                                <td class="text-end"><?= number_format($cat['profit_margin'], 2) ?>%</td>
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
        var table = $('#salesByCategoryTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        table.DataTable({
            order: [[6, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
    
    <?php if (!empty($topCategories)): ?>
    // Top Categories Bar Chart
    const topCategoriesCtx = document.getElementById('topCategoriesChart');
    if (topCategoriesCtx && typeof Chart !== 'undefined') {
        new Chart(topCategoriesCtx, {
            type: 'bar',
            data: {
                labels: [<?= implode(',', array_map(function($c) { return "'" . addslashes($c['category_name']) . "'"; }, $topCategories)) ?>],
                datasets: [{
                    label: 'Net Sales',
                    data: [<?= implode(',', array_column($topCategories, 'category_net_sales')) ?>],
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
    
    // Top Categories Pie Chart
    const topCategoriesPieCtx = document.getElementById('topCategoriesPieChart');
    if (topCategoriesPieCtx && typeof Chart !== 'undefined') {
        new Chart(topCategoriesPieCtx, {
            type: 'pie',
            data: {
                labels: [<?= implode(',', array_map(function($c) { return "'" . addslashes($c['category_name']) . "'"; }, $topCategories)) ?>],
                datasets: [{
                    data: [<?= implode(',', array_column($topCategories, 'category_net_sales')) ?>],
                    backgroundColor: [
                        'rgba(30, 58, 138, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(96, 165, 250, 0.8)',
                        'rgba(147, 197, 253, 0.8)',
                        'rgba(191, 219, 254, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }
    <?php endif; ?>
});
</script>

