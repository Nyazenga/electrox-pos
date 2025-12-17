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

$pageTitle = 'Product Wise Orders Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedCategory = $_GET['category_id'] ?? 'all';

// Get categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Build query conditions - Orders are sales with pending status or linked to invoices
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", ];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedCategory !== 'all' && $selectedCategory) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $selectedCategory;
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

// Get product wise orders
$productOrders = $db->getRows("SELECT 
    si.product_id,
    si.product_name,
    p.product_code,
    pc.name as category_name,
    SUM(si.quantity) as total_qty,
    SUM(si.total_price) as gross_sale,
    COALESCE(SUM(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0))), 0) as discounts,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as net_sale,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as cost,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0) - (si.quantity * COALESCE(p.cost_price, 0))), 0) as profit
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE $whereClause
GROUP BY si.product_id, si.product_name, p.product_code, pc.name
ORDER BY net_sale DESC
LIMIT 1000", $params);

if ($productOrders === false) {
    $productOrders = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Product Wise Orders Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Amount</th></tr>';
    $html .= '<tr><td>Total Gross Sale</td><td style="text-align: right;">' . formatCurrency($summary['total_gross_sale']) . '</td></tr>';
    $html .= '<tr><td>Total Net Sale</td><td style="text-align: right;">' . formatCurrency($summary['total_net_sale']) . '</td></tr>';
    $html .= '<tr><td>Total Cost</td><td style="text-align: right;">' . formatCurrency($summary['total_cost']) . '</td></tr>';
    $html .= '<tr><td>Total Profit</td><td style="text-align: right;">' . formatCurrency($summary['total_profit']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Product Orders</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product</th><th>Code</th><th>Category</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Gross Sale</th><th style="text-align: right;">Discounts</th><th style="text-align: right;">Net Sale</th><th style="text-align: right;">Cost</th><th style="text-align: right;">Profit</th></tr>';
    foreach ($productOrders as $po) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($po['product_name']) . '</td>';
        $html .= '<td>' . escapeHtml($po['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($po['category_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . $po['total_qty'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($po['gross_sale']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($po['discounts']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($po['net_sale']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($po['cost']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($po['profit']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Product Wise Orders Report', $html, 'Product_Wise_Orders_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-arrow-in-right"></i> Product Wise Orders Report</h2>
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
                <select name="category_id" class="form-select">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $selectedCategory == $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="product_wise_orders.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
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

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Product Orders</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="productWiseOrdersTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Code</th>
                        <th>Category</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Gross Sale</th>
                        <th class="text-end">Discounts</th>
                        <th class="text-end">Net Sale</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productOrders)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productOrders as $po): ?>
                            <tr>
                                <td><?= escapeHtml($po['product_name']) ?></td>
                                <td><?= escapeHtml($po['product_code'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($po['category_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $po['total_qty'] ?></td>
                                <td class="text-end"><?= formatCurrency($po['gross_sale']) ?></td>
                                <td class="text-end"><?= formatCurrency($po['discounts']) ?></td>
                                <td class="text-end"><?= formatCurrency($po['net_sale']) ?></td>
                                <td class="text-end"><?= formatCurrency($po['cost']) ?></td>
                                <td class="text-end"><strong><?= formatCurrency($po['profit']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Wait for jQuery to be available
    if (typeof jQuery === 'undefined') {
        console.error('jQuery is not loaded');
        return;
    }
    
    var $ = jQuery;
    
    if ($.fn.DataTable) {
        var table = $('#productWiseOrdersTable');
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
});
</script>

