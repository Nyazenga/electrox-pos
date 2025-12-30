<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.sales_for_product');

$pageTitle = 'Sales for Product';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedProduct = $_GET['product_id'] ?? '';
$search = $_GET['search'] ?? '';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get products
$productWhere = "p.status = 'Active'";
$productParams = [];
if (!empty($search)) {
    $productWhere .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";
    $productParams[':search'] = '%' . $search . '%';
}
$products = $db->getRows("SELECT id, product_code, product_name, brand, model FROM products p WHERE $productWhere ORDER BY p.product_code LIMIT 500", $productParams);
if ($products === false) $products = [];

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

if (!empty($selectedProduct)) {
    $whereConditions[] = "si.product_id = :product_id";
    $params[':product_id'] = $selectedProduct;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_sales,
    COUNT(DISTINCT si.product_id) as unique_products,
    COALESCE(SUM(si.quantity), 0) as total_quantity,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as net_sales,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_sales' => 0,
        'unique_products' => 0,
        'total_quantity' => 0,
        'net_sales' => 0,
        'total_cost' => 0
    ];
}

$grossProfit = $summary['net_sales'] - $summary['total_cost'];

// Get sales by product
$salesByProduct = $db->getRows("SELECT 
    si.product_id,
    p.product_code,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    pc.name as category_name,
    b.branch_name,
    COUNT(DISTINCT s.id) as sale_count,
    SUM(si.quantity) as total_quantity,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as net_sales,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)) - SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as gross_profit
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN branches b ON s.branch_id = b.id
WHERE $whereClause
GROUP BY si.product_id, p.product_code, p.product_name, p.brand, p.model, pc.name, b.branch_name
ORDER BY net_sales DESC
LIMIT 1000", $params);

if ($salesByProduct === false) {
    $salesByProduct = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales for Product</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . $summary['total_sales'] . '</td></tr>';
    $html .= '<tr><td>Unique Products</td><td style="text-align: right;">' . $summary['unique_products'] . '</td></tr>';
    $html .= '<tr><td>Total Quantity</td><td style="text-align: right;">' . number_format($summary['total_quantity'], 2) . '</td></tr>';
    $html .= '<tr><td>Net Sales</td><td style="text-align: right;">' . formatCurrency($summary['net_sales']) . '</td></tr>';
    $html .= '<tr><td>Gross Profit</td><td style="text-align: right;">' . formatCurrency($grossProfit) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Sales by Product</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product Code</th><th>Product Name</th><th>Category</th><th>Branch</th><th>Sales</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Net Sales</th><th style="text-align: right;">Cost</th><th style="text-align: right;">Profit</th></tr>';
    foreach ($salesByProduct as $product) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($product['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml(substr($product['product_name'] ?? 'N/A', 0, 25)) . '</td>';
        $html .= '<td>' . escapeHtml($product['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . escapeHtml($product['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . $product['sale_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($product['total_quantity'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['net_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['total_cost']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['gross_profit']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales for Product', $html, 'Sales_For_Product_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam"></i> Sales for Product</h2>
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
                <label class="form-label"><i class="bi bi-box"></i> Product</label>
                <select name="product_id" class="form-select">
                    <option value="">All Products</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['id'] ?>" <?= $selectedProduct == $product['id'] ? 'selected' : '' ?>>
                            <?= escapeHtml($product['product_code'] ?? 'N/A') ?> - <?= escapeHtml(substr($product['product_name'] ?? ($product['brand'] . ' ' . $product['model']), 0, 30)) ?>
                        </option>
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
                <h6 class="text-muted mb-2">Total Sales</h6>
                <h3 class="mb-0"><?= $summary['total_sales'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Quantity</h6>
                <h3 class="mb-0"><?= number_format($summary['total_quantity'], 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Net Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['net_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Gross Profit</h6>
                <h3 class="mb-0 text-success"><?= formatCurrency($grossProfit) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="salesByProductTable">
                <thead>
                    <tr>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Branch</th>
                        <th class="text-end">Sales</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Net Sales</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salesByProduct)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No sales found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($salesByProduct as $product): ?>
                            <tr>
                                <td><span class="fw-bold"><?= escapeHtml($product['product_code'] ?? 'N/A') ?></span></td>
                                <td><?= escapeHtml($product['product_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($product['category_name'] ?? 'Uncategorized') ?></td>
                                <td><?= escapeHtml($product['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $product['sale_count'] ?></td>
                                <td class="text-end"><?= number_format($product['total_quantity'], 2) ?></td>
                                <td class="text-end"><?= formatCurrency($product['net_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($product['total_cost']) ?></td>
                                <td class="text-end fw-bold text-success"><?= formatCurrency($product['gross_profit']) ?></td>
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
    $('#salesByProductTable').DataTable({
        order: [[6, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


