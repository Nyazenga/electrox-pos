<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.sales_vs_stock_balances');

$pageTitle = 'Sales vs Stock Balances for Period';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCategory = $_GET['category_id'] ?? 'all';
$search = $_GET['search'] ?? '';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Build query conditions for sales
$salesWhereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$salesParams = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $salesWhereConditions[] = "s.branch_id = :branch_id";
    $salesParams[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $salesWhereConditions[] = "s.branch_id = :branch_id";
    $salesParams[':branch_id'] = $branchId;
}

$salesWhereClause = implode(' AND ', $salesWhereConditions);

// Build query conditions for products
$productWhereConditions = ["p.status = 'Active'"];
$productParams = [];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $productWhereConditions[] = "p.branch_id = :branch_id";
    $productParams[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $productWhereConditions[] = "p.branch_id = :branch_id";
    $productParams[':branch_id'] = $branchId;
}

if ($selectedCategory !== 'all' && $selectedCategory) {
    $productWhereConditions[] = "p.category_id = :category_id";
    $productParams[':category_id'] = $selectedCategory;
}

if (!empty($search)) {
    $productWhereConditions[] = "(p.product_name LIKE :search OR p.product_code LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";
    $productParams[':search'] = '%' . $search . '%';
}

$productWhereClause = implode(' AND ', $productWhereConditions);

// Get sales vs stock data
$salesVsStock = $db->getRows("SELECT 
    p.id,
    p.product_code,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    pc.name as category_name,
    b.branch_name,
    p.quantity_in_stock as current_stock,
    COALESCE(SUM(si.quantity), 0) as sold_quantity,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as sales_amount,
    p.cost_price,
    p.selling_price,
    (p.quantity_in_stock * p.cost_price) as stock_value,
    CASE 
        WHEN p.quantity_in_stock > 0 AND COALESCE(SUM(si.quantity), 0) > 0
        THEN (COALESCE(SUM(si.quantity), 0) / p.quantity_in_stock) * 100
        ELSE 0
    END as turnover_rate
FROM products p
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN branches b ON p.branch_id = b.id
LEFT JOIN sale_items si ON p.id = si.product_id
LEFT JOIN sales s ON si.sale_id = s.id AND $salesWhereClause
WHERE $productWhereClause
GROUP BY p.id, p.product_code, p.product_name, p.brand, p.model, pc.name, b.branch_name, p.quantity_in_stock, p.cost_price, p.selling_price
HAVING sold_quantity > 0 OR current_stock > 0
ORDER BY sales_amount DESC, p.product_code
LIMIT 1000", array_merge($productParams, $salesParams));

if ($salesVsStock === false) {
    $salesVsStock = [];
}

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT p.id) as total_products,
    COALESCE(SUM(p.quantity_in_stock), 0) as total_stock,
    COALESCE(SUM(si.quantity), 0) as total_sold,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as total_sales,
    COALESCE(SUM(p.quantity_in_stock * p.cost_price), 0) as total_stock_value
FROM products p
LEFT JOIN sale_items si ON p.id = si.product_id
LEFT JOIN sales s ON si.sale_id = s.id AND $salesWhereClause
WHERE $productWhereClause", array_merge($productParams, $salesParams));

if ($summary === false) {
    $summary = [
        'total_products' => 0,
        'total_stock' => 0,
        'total_sold' => 0,
        'total_sales' => 0,
        'total_stock_value' => 0
    ];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales vs Stock Balances for Period</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Products</td><td style="text-align: right;">' . $summary['total_products'] . '</td></tr>';
    $html .= '<tr><td>Total Stock</td><td style="text-align: right;">' . number_format($summary['total_stock'], 2) . '</td></tr>';
    $html .= '<tr><td>Total Sold</td><td style="text-align: right;">' . number_format($summary['total_sold'], 2) . '</td></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_sales']) . '</td></tr>';
    $html .= '<tr><td>Total Stock Value</td><td style="text-align: right;">' . formatCurrency($summary['total_stock_value']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Sales vs Stock Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product Code</th><th>Product Name</th><th>Category</th><th>Branch</th><th style="text-align: right;">Current Stock</th><th style="text-align: right;">Sold Qty</th><th style="text-align: right;">Sales Amount</th><th style="text-align: right;">Stock Value</th><th style="text-align: right;">Turnover %</th></tr>';
    foreach ($salesVsStock as $item) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($item['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml(substr($item['product_name'] ?? 'N/A', 0, 25)) . '</td>';
        $html .= '<td>' . escapeHtml($item['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . escapeHtml($item['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($item['current_stock'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($item['sold_quantity'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['sales_amount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['stock_value']) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($item['turnover_rate'], 2) . '%</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales vs Stock Balances for Period', $html, 'Sales_Vs_Stock_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-bar-chart"></i> Sales vs Stock Balances for Period</h2>
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
                <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                <select name="category_id" class="form-select">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $selectedCategory == $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-search"></i> Search</label>
                <input type="text" name="search" value="<?= escapeHtml($search) ?>" class="form-control" placeholder="Search products...">
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
                <h6 class="text-muted mb-2">Total Products</h6>
                <h3 class="mb-0"><?= $summary['total_products'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Stock</h6>
                <h3 class="mb-0"><?= number_format($summary['total_stock'], 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Sold</h6>
                <h3 class="mb-0 text-primary"><?= number_format($summary['total_sold'], 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Sales</h6>
                <h3 class="mb-0 text-success"><?= formatCurrency($summary['total_sales']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="salesVsStockTable">
                <thead>
                    <tr>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Branch</th>
                        <th class="text-end">Current Stock</th>
                        <th class="text-end">Sold Qty</th>
                        <th class="text-end">Sales Amount</th>
                        <th class="text-end">Stock Value</th>
                        <th class="text-end">Turnover %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salesVsStock)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No data found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($salesVsStock as $item): ?>
                            <tr>
                                <td><span class="fw-bold"><?= escapeHtml($item['product_code'] ?? 'N/A') ?></span></td>
                                <td><?= escapeHtml($item['product_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($item['category_name'] ?? 'Uncategorized') ?></td>
                                <td><?= escapeHtml($item['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= number_format($item['current_stock'], 2) ?></td>
                                <td class="text-end text-primary"><?= number_format($item['sold_quantity'], 2) ?></td>
                                <td class="text-end fw-bold text-success"><?= formatCurrency($item['sales_amount']) ?></td>
                                <td class="text-end"><?= formatCurrency($item['stock_value']) ?></td>
                                <td class="text-end">
                                    <span class="badge bg-<?= $item['turnover_rate'] > 50 ? 'success' : ($item['turnover_rate'] > 25 ? 'warning' : 'secondary') ?>">
                                        <?= number_format($item['turnover_rate'], 2) ?>%
                                    </span>
                                </td>
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
    $('#salesVsStockTable').DataTable({
        order: [[6, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


