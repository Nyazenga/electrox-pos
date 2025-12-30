<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.product_sales_per_day');

$pageTitle = 'Product Sales per Day by Category';

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

// Get product sales per day grouped by category
$productSales = $db->getRows("SELECT 
    DATE(s.sale_date) as sale_date,
    pc.id as category_id,
    pc.name as category_name,
    COUNT(DISTINCT si.product_id) as unique_products,
    SUM(si.quantity) as total_quantity,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as category_total
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE $whereClause
GROUP BY DATE(s.sale_date), pc.id, pc.name
ORDER BY sale_date DESC, category_total DESC", $params);

if ($productSales === false) {
    $productSales = [];
}

// Group by date for summary
$dailyTotals = [];
foreach ($productSales as $sale) {
    $date = $sale['sale_date'];
    if (!isset($dailyTotals[$date])) {
        $dailyTotals[$date] = [
            'date' => $date,
            'categories' => [],
            'total_amount' => 0
        ];
    }
    $dailyTotals[$date]['categories'][] = $sale;
    $dailyTotals[$date]['total_amount'] += $sale['category_total'];
}

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT DATE(s.sale_date)) as total_days,
    COUNT(DISTINCT pc.id) as unique_categories,
    COUNT(DISTINCT si.product_id) as unique_products,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as total_sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_days' => 0,
        'unique_categories' => 0,
        'unique_products' => 0,
        'total_sales' => 0
    ];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Product Sales per Day by Category</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Days</td><td style="text-align: right;">' . $summary['total_days'] . '</td></tr>';
    $html .= '<tr><td>Unique Categories</td><td style="text-align: right;">' . $summary['unique_categories'] . '</td></tr>';
    $html .= '<tr><td>Unique Products</td><td style="text-align: right;">' . $summary['unique_products'] . '</td></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_sales']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Daily Sales by Category</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Date</th><th>Category</th><th>Products</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Amount</th></tr>';
    foreach ($productSales as $sale) {
        $html .= '<tr>';
        $html .= '<td>' . date('M d, Y', strtotime($sale['sale_date'])) . '</td>';
        $html .= '<td>' . escapeHtml($sale['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . $sale['unique_products'] . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($sale['total_quantity'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($sale['category_total']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Product Sales per Day by Category', $html, 'Product_Sales_Per_Day_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-calendar-day"></i> Product Sales per Day by Category</h2>
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
                <h6 class="text-muted mb-2">Total Days</h6>
                <h3 class="mb-0"><?= $summary['total_days'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Categories</h6>
                <h3 class="mb-0"><?= $summary['unique_categories'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Products</h6>
                <h3 class="mb-0"><?= $summary['unique_products'] ?></h3>
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
        <?php if (!empty($dailyTotals)): ?>
            <?php foreach ($dailyTotals as $date => $dayData): ?>
                <div class="mb-4">
                    <h5 class="mb-3">
                        <i class="bi bi-calendar"></i> <?= date('M d, Y', strtotime($date)) ?>
                        <span class="badge bg-primary ms-2"><?= formatCurrency($dayData['total_amount']) ?></span>
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th class="text-end">Products</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dayData['categories'] as $category): ?>
                                    <tr>
                                        <td><?= escapeHtml($category['category_name'] ?? 'Uncategorized') ?></td>
                                        <td class="text-end"><?= $category['unique_products'] ?></td>
                                        <td class="text-end"><?= number_format($category['total_quantity'], 2) ?></td>
                                        <td class="text-end fw-bold"><?= formatCurrency($category['category_total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center text-muted py-4">No sales found for the selected period</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


