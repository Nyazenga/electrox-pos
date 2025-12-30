<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.performance_report');

$pageTitle = 'Performance Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$reportType = $_GET['report_type'] ?? 'overall'; // overall, by_user, by_product, by_category

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

// Get overall performance summary
$overall = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_sales,
    COUNT(DISTINCT s.customer_id) as unique_customers,
    COUNT(DISTINCT s.user_id) as unique_cashiers,
    COUNT(DISTINCT si.product_id) as unique_products,
    COALESCE(SUM(s.total_amount), 0) as gross_sales,
    COALESCE(SUM(s.discount_amount), 0) as total_discounts,
    COALESCE(SUM(s.tax_amount), 0) as total_tax,
    COALESCE(SUM(s.total_amount - s.discount_amount), 0) as net_sales,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
    COALESCE(SUM(s.total_amount - s.discount_amount) - SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as gross_profit,
    COALESCE(AVG(s.total_amount - s.discount_amount), 0) as avg_transaction_value
FROM sales s
LEFT JOIN sale_items si ON s.id = si.sale_id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause", $params);

if ($overall === false) {
    $overall = [
        'total_sales' => 0,
        'unique_customers' => 0,
        'unique_cashiers' => 0,
        'unique_products' => 0,
        'gross_sales' => 0,
        'total_discounts' => 0,
        'total_tax' => 0,
        'net_sales' => 0,
        'total_cost' => 0,
        'gross_profit' => 0,
        'avg_transaction_value' => 0
    ];
}

$profitMargin = $overall['net_sales'] > 0 ? (($overall['gross_profit'] / $overall['net_sales']) * 100) : 0;

// Get performance by user
$byUser = [];
if ($reportType === 'by_user' || $reportType === 'overall') {
    $byUser = $db->getRows("SELECT 
        u.id,
        CONCAT(u.first_name, ' ', u.last_name) as user_name,
        u.email,
        COUNT(DISTINCT s.id) as sale_count,
        COALESCE(SUM(s.total_amount - s.discount_amount), 0) as net_sales,
        COALESCE(AVG(s.total_amount - s.discount_amount), 0) as avg_sale,
        COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
        COALESCE(SUM(s.total_amount - s.discount_amount) - SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as gross_profit
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN sale_items si ON s.id = si.sale_id
    LEFT JOIN products p ON si.product_id = p.id
    WHERE $whereClause
    GROUP BY u.id, u.first_name, u.last_name, u.email
    ORDER BY net_sales DESC
    LIMIT 20", $params);
    if ($byUser === false) $byUser = [];
}

// Get performance by product
$byProduct = [];
if ($reportType === 'by_product' || $reportType === 'overall') {
    $byProduct = $db->getRows("SELECT 
        p.id,
        p.product_code,
        COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
        SUM(si.quantity) as total_sold,
        COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as net_sales,
        COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
        COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)) - SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as gross_profit
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    LEFT JOIN products p ON si.product_id = p.id
    WHERE $whereClause
    GROUP BY p.id, p.product_code, p.product_name, p.brand, p.model
    ORDER BY net_sales DESC
    LIMIT 20", $params);
    if ($byProduct === false) $byProduct = [];
}

// Get performance by category
$byCategory = [];
if ($reportType === 'by_category' || $reportType === 'overall') {
    $byCategory = $db->getRows("SELECT 
        pc.id,
        pc.name as category_name,
        COUNT(DISTINCT si.product_id) as unique_products,
        SUM(si.quantity) as total_sold,
        COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as net_sales,
        COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
        COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)) - SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as gross_profit
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    LEFT JOIN products p ON si.product_id = p.id
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE $whereClause
    GROUP BY pc.id, pc.name
    ORDER BY net_sales DESC
    LIMIT 20", $params);
    if ($byCategory === false) $byCategory = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Performance Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . $overall['total_sales'] . '</td></tr>';
    $html .= '<tr><td>Unique Customers</td><td style="text-align: right;">' . $overall['unique_customers'] . '</td></tr>';
    $html .= '<tr><td>Unique Cashiers</td><td style="text-align: right;">' . $overall['unique_cashiers'] . '</td></tr>';
    $html .= '<tr><td>Unique Products</td><td style="text-align: right;">' . $overall['unique_products'] . '</td></tr>';
    $html .= '<tr><td>Gross Sales</td><td style="text-align: right;">' . formatCurrency($overall['gross_sales']) . '</td></tr>';
    $html .= '<tr><td>Net Sales</td><td style="text-align: right;">' . formatCurrency($overall['net_sales']) . '</td></tr>';
    $html .= '<tr><td>Gross Profit</td><td style="text-align: right;">' . formatCurrency($overall['gross_profit']) . '</td></tr>';
    $html .= '<tr><td>Profit Margin</td><td style="text-align: right;">' . number_format($profitMargin, 2) . '%</td></tr>';
    $html .= '<tr><td>Avg Transaction Value</td><td style="text-align: right;">' . formatCurrency($overall['avg_transaction_value']) . '</td></tr>';
    $html .= '</table>';
    
    if (!empty($byUser)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Top 20 Performers by User</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>User</th><th>Sales</th><th style="text-align: right;">Net Sales</th><th style="text-align: right;">Avg Sale</th><th style="text-align: right;">Profit</th></tr>';
        foreach ($byUser as $user) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($user['user_name'] ?? 'N/A') . '</td>';
            $html .= '<td>' . $user['sale_count'] . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($user['net_sales']) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($user['avg_sale']) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($user['gross_profit']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    if (!empty($byProduct)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Top 20 Products</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Product</th><th style="text-align: right;">Qty Sold</th><th style="text-align: right;">Net Sales</th><th style="text-align: right;">Profit</th></tr>';
        foreach ($byProduct as $product) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($product['product_code'] ?? 'N/A') . ' - ' . escapeHtml(substr($product['product_name'] ?? 'N/A', 0, 25)) . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($product['total_sold'], 2) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($product['net_sales']) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($product['gross_profit']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    if (!empty($byCategory)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Performance by Category</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Category</th><th>Products</th><th style="text-align: right;">Qty Sold</th><th style="text-align: right;">Net Sales</th><th style="text-align: right;">Profit</th></tr>';
        foreach ($byCategory as $category) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($category['category_name'] ?? 'Uncategorized') . '</td>';
            $html .= '<td>' . $category['unique_products'] . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($category['total_sold'], 2) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($category['net_sales']) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($category['gross_profit']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    ReportHelper::generatePDF('Performance Report', $html, 'Performance_Report_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-speedometer2"></i> Performance Report</h2>
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
                <label class="form-label"><i class="bi bi-graph-up"></i> Report Type</label>
                <select name="report_type" class="form-select">
                    <option value="overall" <?= $reportType === 'overall' ? 'selected' : '' ?>>Overall</option>
                    <option value="by_user" <?= $reportType === 'by_user' ? 'selected' : '' ?>>By User</option>
                    <option value="by_product" <?= $reportType === 'by_product' ? 'selected' : '' ?>>By Product</option>
                    <option value="by_category" <?= $reportType === 'by_category' ? 'selected' : '' ?>>By Category</option>
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
                <h6 class="text-muted mb-2">Total Sales</h6>
                <h3 class="mb-0"><?= $overall['total_sales'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Net Sales</h6>
                <h3 class="mb-0 text-success"><?= formatCurrency($overall['net_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Gross Profit</h6>
                <h3 class="mb-0 text-primary"><?= formatCurrency($overall['gross_profit']) ?></h3>
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

<?php if ($reportType === 'by_user' || $reportType === 'overall'): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-people"></i> Top 20 Performers by User</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th class="text-end">Sales</th>
                            <th class="text-end">Net Sales</th>
                            <th class="text-end">Avg Sale</th>
                            <th class="text-end">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($byUser)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">No data found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($byUser as $user): ?>
                                <tr>
                                    <td><span class="fw-bold"><?= escapeHtml($user['user_name'] ?? 'N/A') ?></span></td>
                                    <td class="text-end"><?= $user['sale_count'] ?></td>
                                    <td class="text-end"><?= formatCurrency($user['net_sales']) ?></td>
                                    <td class="text-end"><?= formatCurrency($user['avg_sale']) ?></td>
                                    <td class="text-end fw-bold text-success"><?= formatCurrency($user['gross_profit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($reportType === 'by_product' || $reportType === 'overall'): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-box"></i> Top 20 Products</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-end">Qty Sold</th>
                            <th class="text-end">Net Sales</th>
                            <th class="text-end">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($byProduct)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">No data found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($byProduct as $product): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= escapeHtml($product['product_code'] ?? 'N/A') ?></div>
                                        <small class="text-muted"><?= escapeHtml(substr($product['product_name'] ?? 'N/A', 0, 40)) ?></small>
                                    </td>
                                    <td class="text-end"><?= number_format($product['total_sold'], 2) ?></td>
                                    <td class="text-end"><?= formatCurrency($product['net_sales']) ?></td>
                                    <td class="text-end fw-bold text-success"><?= formatCurrency($product['gross_profit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($reportType === 'by_category' || $reportType === 'overall'): ?>
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-tags"></i> Performance by Category</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-end">Products</th>
                            <th class="text-end">Qty Sold</th>
                            <th class="text-end">Net Sales</th>
                            <th class="text-end">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($byCategory)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">No data found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($byCategory as $category): ?>
                                <tr>
                                    <td><span class="fw-bold"><?= escapeHtml($category['category_name'] ?? 'Uncategorized') ?></span></td>
                                    <td class="text-end"><?= $category['unique_products'] ?></td>
                                    <td class="text-end"><?= number_format($category['total_sold'], 2) ?></td>
                                    <td class="text-end"><?= formatCurrency($category['net_sales']) ?></td>
                                    <td class="text-end fw-bold text-success"><?= formatCurrency($category['gross_profit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


