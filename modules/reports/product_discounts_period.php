<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.product_discounts_period');

$pageTitle = 'Product Discounts for Period';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedProduct = $_GET['product_id'] ?? 'all';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get products for filter
$products = $db->getRows("SELECT id, product_code, product_name, brand, model FROM products WHERE status = 'Active' ORDER BY product_code LIMIT 500");
if ($products === false) $products = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", "s.discount_amount > 0"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats by product
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT si.product_id) as unique_products,
    COUNT(DISTINCT s.id) as total_transactions,
    COALESCE(SUM(s.discount_amount), 0) as total_discount
FROM sales s
INNER JOIN sale_items si ON s.id = si.sale_id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'unique_products' => 0,
        'total_transactions' => 0,
        'total_discount' => 0
    ];
}

// Get product discount details
$productDiscounts = $db->getRows("SELECT 
    p.id as product_id,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    p.product_code,
    COUNT(DISTINCT s.id) as transaction_count,
    COALESCE(SUM(s.discount_amount), 0) as total_discount,
    COALESCE(AVG(s.discount_amount), 0) as avg_discount,
    COALESCE(SUM(si.quantity), 0) as total_quantity_sold,
    COALESCE(SUM(si.total_price), 0) as total_sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause
" . ($selectedProduct !== 'all' && $selectedProduct ? " AND si.product_id = :product_id" : "") . "
GROUP BY si.product_id, p.id, p.product_name, p.product_code, p.brand, p.model
ORDER BY total_discount DESC
LIMIT 1000", 
$selectedProduct !== 'all' && $selectedProduct ? array_merge($params, [':product_id' => $selectedProduct]) : $params);

if ($productDiscounts === false) {
    $productDiscounts = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Product Discounts for Period</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Unique Products with Discounts</td><td style="text-align: right;">' . $summary['unique_products'] . '</td></tr>';
    $html .= '<tr><td>Total Transactions</td><td style="text-align: right;">' . $summary['total_transactions'] . '</td></tr>';
    $html .= '<tr><td>Total Discount Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_discount']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Product Discount Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product Code</th><th>Product Name</th><th>Transactions</th><th style="text-align: right;">Total Discount</th><th style="text-align: right;">Avg Discount</th><th style="text-align: right;">Qty Sold</th><th style="text-align: right;">Total Sales</th></tr>';
    foreach ($productDiscounts as $item) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($item['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($item['product_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . $item['transaction_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['total_discount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['avg_discount']) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($item['total_quantity_sold'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['total_sales']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Product Discounts for Period', $html, 'Product_Discounts_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-tag"></i> Product Discounts for Period</h2>
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
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-box-seam"></i> Product</label>
                <select name="product_id" class="form-select">
                    <option value="all" <?= $selectedProduct === 'all' ? 'selected' : '' ?>>All Products</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['id'] ?>" <?= $selectedProduct == $product['id'] ? 'selected' : '' ?>><?= escapeHtml(($product['product_code'] ?? '') . ' - ' . ($product['product_name'] ?? $product['brand'] . ' ' . $product['model'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Products</h6>
                <h3 class="mb-0"><?= $summary['unique_products'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Transactions</h6>
                <h3 class="mb-0"><?= $summary['total_transactions'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Discount</h6>
                <h3 class="mb-0 text-danger"><?= formatCurrency($summary['total_discount']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="productDiscountsTable">
                <thead>
                    <tr>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th class="text-end">Transactions</th>
                        <th class="text-end">Total Discount</th>
                        <th class="text-end">Avg Discount</th>
                        <th class="text-end">Qty Sold</th>
                        <th class="text-end">Total Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productDiscounts)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No product discounts found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productDiscounts as $item): ?>
                            <tr>
                                <td><?= escapeHtml($item['product_code'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($item['product_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $item['transaction_count'] ?></td>
                                <td class="text-end text-danger fw-bold"><?= formatCurrency($item['total_discount']) ?></td>
                                <td class="text-end"><?= formatCurrency($item['avg_discount']) ?></td>
                                <td class="text-end"><?= number_format($item['total_quantity_sold'], 2) ?></td>
                                <td class="text-end"><?= formatCurrency($item['total_sales']) ?></td>
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
    $('#productDiscountsTable').DataTable({
        order: [[3, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
