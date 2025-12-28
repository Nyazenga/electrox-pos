<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.price_list');

$pageTitle = 'Price List';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCategory = $_GET['category_id'] ?? 'all';
$search = $_GET['search'] ?? '';
$showInactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] == '1';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Build query conditions
$whereConditions = [];
$params = [];

if (!$showInactive) {
    $whereConditions[] = "p.status = 'Active'";
}

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedCategory !== 'all' && $selectedCategory) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $selectedCategory;
}

if (!empty($search)) {
    $whereConditions[] = "(p.product_name LIKE :search OR p.product_code LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT p.id) as total_products,
    COUNT(DISTINCT p.category_id) as unique_categories,
    COALESCE(AVG(p.selling_price), 0) as avg_price,
    COALESCE(MIN(p.selling_price), 0) as min_price,
    COALESCE(MAX(p.selling_price), 0) as max_price
FROM products p
$whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_products' => 0,
        'unique_categories' => 0,
        'avg_price' => 0,
        'min_price' => 0,
        'max_price' => 0
    ];
}

// Get products with prices
$products = $db->getRows("SELECT 
    p.id,
    p.product_code,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    pc.name as category_name,
    b.branch_name,
    p.cost_price,
    p.selling_price,
    p.quantity_in_stock,
    p.status,
    CASE 
        WHEN p.selling_price > 0 AND p.cost_price > 0 
        THEN ((p.selling_price - p.cost_price) / p.cost_price) * 100
        ELSE 0
    END as profit_margin
FROM products p
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN branches b ON p.branch_id = b.id
$whereClause
ORDER BY p.product_code, p.product_name
LIMIT 2000", $params);

if ($products === false) {
    $products = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Price List</h2>';
    $html .= '<p style="text-align: center; color: #666;">Generated: ' . date('M d, Y H:i') . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Products</td><td style="text-align: right;">' . $summary['total_products'] . '</td></tr>';
    $html .= '<tr><td>Unique Categories</td><td style="text-align: right;">' . $summary['unique_categories'] . '</td></tr>';
    $html .= '<tr><td>Average Price</td><td style="text-align: right;">' . formatCurrency($summary['avg_price']) . '</td></tr>';
    $html .= '<tr><td>Min Price</td><td style="text-align: right;">' . formatCurrency($summary['min_price']) . '</td></tr>';
    $html .= '<tr><td>Max Price</td><td style="text-align: right;">' . formatCurrency($summary['max_price']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Product Price List</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product Code</th><th>Product Name</th><th>Category</th><th>Branch</th><th style="text-align: right;">Cost Price</th><th style="text-align: right;">Selling Price</th><th style="text-align: right;">Margin %</th><th style="text-align: right;">Stock</th></tr>';
    foreach ($products as $product) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($product['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml(substr($product['product_name'] ?? 'N/A', 0, 30)) . '</td>';
        $html .= '<td>' . escapeHtml($product['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . escapeHtml($product['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['cost_price']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['selling_price']) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($product['profit_margin'], 2) . '%</td>';
        $html .= '<td style="text-align: right;">' . number_format($product['quantity_in_stock'], 2) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Price List', $html, 'Price_List_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-tag"></i> Price List</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
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
                <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                <select name="category_id" class="form-select">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $selectedCategory == $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-search"></i> Search</label>
                <input type="text" name="search" value="<?= escapeHtml($search) ?>" class="form-control" placeholder="Search products...">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="show_inactive" id="showInactive" value="1" <?= $showInactive ? 'checked' : '' ?>>
                    <label class="form-check-label" for="showInactive">Show Inactive</label>
                </div>
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
                <h6 class="text-muted mb-2">Average Price</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['avg_price']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Min Price</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['min_price']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Max Price</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['max_price']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="priceListTable">
                <thead>
                    <tr>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Branch</th>
                        <th class="text-end">Cost Price</th>
                        <th class="text-end">Selling Price</th>
                        <th class="text-end">Margin %</th>
                        <th class="text-end">Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No products found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><span class="fw-bold"><?= escapeHtml($product['product_code'] ?? 'N/A') ?></span></td>
                                <td><?= escapeHtml($product['product_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($product['category_name'] ?? 'Uncategorized') ?></td>
                                <td><?= escapeHtml($product['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= formatCurrency($product['cost_price']) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($product['selling_price']) ?></td>
                                <td class="text-end">
                                    <span class="badge bg-<?= $product['profit_margin'] > 0 ? 'success' : 'secondary' ?>">
                                        <?= number_format($product['profit_margin'], 2) ?>%
                                    </span>
                                </td>
                                <td class="text-end"><?= number_format($product['quantity_in_stock'], 2) ?></td>
                                <td>
                                    <?php if ($product['status'] == 'Active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
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
    $('#priceListTable').DataTable({
        order: [[5, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

