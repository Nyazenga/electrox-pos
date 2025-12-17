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

$pageTitle = 'Sales by Products Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedCategory = $_GET['category_id'] ?? 'all';

// Get categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedCategory !== 'all' && $selectedCategory) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $selectedCategory;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary
$summary = $db->getRow("SELECT 
    COALESCE(SUM(si.total_price), 0) as total_gross_sale,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / s.subtotal), 0)), 0) as total_net_sale,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / s.subtotal), 0) - (si.quantity * COALESCE(p.cost_price, 0))), 0) as total_profit
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

// Get product sales
$productSales = $db->getRows("SELECT 
    si.product_id,
    si.product_name,
    p.product_code,
    pc.name as category_name,
    SUM(si.quantity) as sold_qty,
    SUM(si.total_price) as product_gross_sale,
    COALESCE(SUM(CASE WHEN s.payment_status = 'refunded' THEN si.total_price ELSE 0 END), 0) as refunds,
    COALESCE(SUM(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0))), 0) as discounts,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as product_net_sales,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as product_cost,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0) - (si.quantity * COALESCE(p.cost_price, 0))), 0) as product_gross_profit
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE $whereClause
GROUP BY si.product_id, si.product_name, p.product_code, pc.name
ORDER BY product_net_sales DESC
LIMIT 1000", $params);

if ($productSales === false) {
    $productSales = [];
}

// Calculate profit margins
foreach ($productSales as &$product) {
    $product['profit_margin'] = $product['product_net_sales'] > 0 
        ? (($product['product_gross_profit'] / $product['product_net_sales']) * 100) 
        : 0;
}
unset($product);

// Get top 5 products for chart
$topProducts = array_slice($productSales, 0, 5);

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales by Products Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Amount</th></tr>';
    $html .= '<tr><td>Total Gross Sale</td><td style="text-align: right;">' . formatCurrency($summary['total_gross_sale']) . '</td></tr>';
    $html .= '<tr><td>Total Net Sale</td><td style="text-align: right;">' . formatCurrency($summary['total_net_sale']) . '</td></tr>';
    $html .= '<tr><td>Total Cost</td><td style="text-align: right;">' . formatCurrency($summary['total_cost']) . '</td></tr>';
    $html .= '<tr><td>Total Profit</td><td style="text-align: right;">' . formatCurrency($summary['total_profit']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Product Sales</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product</th><th>Code</th><th>Category</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Gross Sale</th><th style="text-align: right;">Refunds</th><th style="text-align: right;">Discounts</th><th style="text-align: right;">Net Sales</th><th style="text-align: right;">Cost</th><th style="text-align: right;">Profit</th><th style="text-align: right;">Margin %</th></tr>';
    foreach ($productSales as $product) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($product['product_name']) . '</td>';
        $html .= '<td>' . escapeHtml($product['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($product['category_name'] ?? 'No category') . '</td>';
        $html .= '<td style="text-align: right;">' . $product['sold_qty'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['product_gross_sale']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['refunds']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['discounts']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['product_net_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['product_cost']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['product_gross_profit']) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($product['profit_margin'], 2) . '%</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales by Products Report', $html, 'Sales_by_Products_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam"></i> Sales by Products Report</h2>
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
                <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                <select name="category_id" class="form-select" id="categoryFilter">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $selectedCategory == $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="sales_by_products.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
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

<?php if (!empty($topProducts)): ?>
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Top 5 Products by Net Sales</h5>
            </div>
            <div class="card-body">
                <canvas id="topProductsChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Top 5 Products (Pie Chart)</h5>
            </div>
            <div class="card-body">
                <canvas id="topProductsPieChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Product Sales</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="salesByProductsTable">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>Product Code</th>
                        <th>Category</th>
                        <th class="text-end">Sold Qty</th>
                        <th class="text-end">Gross Sale</th>
                        <th class="text-end">Refunds</th>
                        <th class="text-end">Discounts</th>
                        <th class="text-end">Net Sales</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Profit</th>
                        <th class="text-end">Margin %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productSales)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productSales as $product): ?>
                            <tr>
                                <td><?= escapeHtml($product['product_name']) ?></td>
                                <td><?= escapeHtml($product['product_code'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($product['category_name'] ?? 'No category') ?></td>
                                <td class="text-end"><?= $product['sold_qty'] ?></td>
                                <td class="text-end"><?= formatCurrency($product['product_gross_sale']) ?></td>
                                <td class="text-end"><?= formatCurrency($product['refunds']) ?></td>
                                <td class="text-end"><?= formatCurrency($product['discounts']) ?></td>
                                <td class="text-end"><?= formatCurrency($product['product_net_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($product['product_cost']) ?></td>
                                <td class="text-end"><?= formatCurrency($product['product_gross_profit']) ?></td>
                                <td class="text-end"><?= number_format($product['profit_margin'], 2) ?>%</td>
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
    
    // Initialize searchable dropdowns with Select2
    if (typeof $.fn.select2 !== 'undefined') {
        $('#categoryFilter').select2({
            placeholder: 'All Categories',
            allowClear: true,
            width: '100%'
        });
    }
    
    if ($.fn.DataTable) {
        var table = $('#salesByProductsTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        
        // Check if table has actual data (11 columns with content, not empty state)
        var tbody = table.find('tbody');
        var firstRow = tbody.find('tr:first');
        var firstCell = firstRow.find('td').first();
        var hasColspan = firstCell.attr('colspan') !== undefined;
        var tdCount = firstRow.find('td').length;
        var firstCellText = firstCell.text().trim();
        
        // Only initialize if we have data (no colspan, 11 columns, and has content)
        var hasContent = !hasColspan && firstRow.length > 0 && tdCount === 11 && firstCellText !== '';
        
        if (hasContent) {
            table.DataTable({
                order: [[7, 'desc']],
                pageLength: 25,
                destroy: true,
                autoWidth: false,
                language: {
                    emptyTable: "No product sales found for the selected criteria"
                }
            });
        } else {
            // Show empty message
            tbody.html('<tr><td colspan="11" class="text-center text-muted">No data available</td></tr>');
        }
    }
    
    <?php if (!empty($topProducts)): ?>
    // Top Products Bar Chart
    const topProductsCtx = document.getElementById('topProductsChart');
    if (topProductsCtx && typeof Chart !== 'undefined') {
        new Chart(topProductsCtx, {
            type: 'bar',
            data: {
                labels: [<?= implode(',', array_map(function($p) { return "'" . addslashes($p['product_name']) . "'"; }, $topProducts)) ?>],
                datasets: [{
                    label: 'Net Sales',
                    data: [<?= implode(',', array_column($topProducts, 'product_net_sales')) ?>],
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
    
    // Top Products Pie Chart
    const topProductsPieCtx = document.getElementById('topProductsPieChart');
    if (topProductsPieCtx && typeof Chart !== 'undefined') {
        new Chart(topProductsPieCtx, {
            type: 'pie',
            data: {
                labels: [<?= implode(',', array_map(function($p) { return "'" . addslashes($p['product_name']) . "'"; }, $topProducts)) ?>],
                datasets: [{
                    data: [<?= implode(',', array_column($topProducts, 'product_net_sales')) ?>],
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

