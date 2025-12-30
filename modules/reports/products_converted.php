<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.products_converted');

$pageTitle = 'Products Converted';

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

// Get products converted (sold) with conversion rate
$productsConverted = $db->getRows("SELECT 
    p.id,
    p.product_code,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    pc.name as category_name,
    b.branch_name,
    p.quantity_in_stock as current_stock,
    COALESCE(SUM(si.quantity), 0) as total_sold,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as net_sales,
    CASE 
        WHEN p.quantity_in_stock > 0 OR COALESCE(SUM(si.quantity), 0) > 0
        THEN (COALESCE(SUM(si.quantity), 0) / (p.quantity_in_stock + COALESCE(SUM(si.quantity), 0))) * 100
        ELSE 0
    END as conversion_rate
FROM products p
LEFT JOIN sale_items si ON p.id = si.product_id
LEFT JOIN sales s ON si.sale_id = s.id AND $whereClause
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN branches b ON p.branch_id = b.id
WHERE p.status = 'Active'
" . ($selectedCategory !== 'all' && $selectedCategory ? " AND p.category_id = :category_id" : "") . "
" . (!empty($search) ? " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)" : "") . "
GROUP BY p.id, p.product_code, p.product_name, p.brand, p.model, pc.name, b.branch_name, p.quantity_in_stock
HAVING total_sold > 0
ORDER BY conversion_rate DESC, total_sold DESC
LIMIT 1000", array_merge($params, 
    ($selectedCategory !== 'all' && $selectedCategory ? [':category_id' => $selectedCategory] : []),
    (!empty($search) ? [':search' => '%' . $search . '%'] : [])
));

if ($productsConverted === false) {
    $productsConverted = [];
}

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT si.product_id) as total_products_sold,
    COALESCE(SUM(si.quantity), 0) as total_units_sold,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as total_sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_products_sold' => 0,
        'total_units_sold' => 0,
        'total_sales' => 0
    ];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Products Converted Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Products Sold</td><td style="text-align: right;">' . $summary['total_products_sold'] . '</td></tr>';
    $html .= '<tr><td>Total Units Sold</td><td style="text-align: right;">' . number_format($summary['total_units_sold'], 2) . '</td></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_sales']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Products Converted Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product Code</th><th>Product Name</th><th>Category</th><th>Branch</th><th style="text-align: right;">Current Stock</th><th style="text-align: right;">Total Sold</th><th style="text-align: right;">Net Sales</th><th style="text-align: right;">Conversion %</th></tr>';
    foreach ($productsConverted as $product) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($product['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml(substr($product['product_name'] ?? 'N/A', 0, 25)) . '</td>';
        $html .= '<td>' . escapeHtml($product['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . escapeHtml($product['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($product['current_stock'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($product['total_sold'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['net_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($product['conversion_rate'], 2) . '%</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Products Converted Report', $html, 'Products_Converted_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-repeat"></i> Products Converted</h2>
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
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Products Sold</h6>
                <h3 class="mb-0"><?= $summary['total_products_sold'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Units Sold</h6>
                <h3 class="mb-0"><?= number_format($summary['total_units_sold'], 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
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
            <table class="table table-striped table-hover" id="convertedTable">
                <thead>
                    <tr>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Branch</th>
                        <th class="text-end">Current Stock</th>
                        <th class="text-end">Total Sold</th>
                        <th class="text-end">Net Sales</th>
                        <th class="text-end">Conversion %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productsConverted)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No products converted found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productsConverted as $product): ?>
                            <tr>
                                <td><span class="fw-bold"><?= escapeHtml($product['product_code'] ?? 'N/A') ?></span></td>
                                <td><?= escapeHtml($product['product_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($product['category_name'] ?? 'Uncategorized') ?></td>
                                <td><?= escapeHtml($product['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= number_format($product['current_stock'], 2) ?></td>
                                <td class="text-end text-primary"><?= number_format($product['total_sold'], 2) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($product['net_sales']) ?></td>
                                <td class="text-end">
                                    <span class="badge bg-<?= $product['conversion_rate'] > 50 ? 'success' : ($product['conversion_rate'] > 25 ? 'warning' : 'secondary') ?>">
                                        <?= number_format($product['conversion_rate'], 2) ?>%
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
    $('#convertedTable').DataTable({
        order: [[7, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


