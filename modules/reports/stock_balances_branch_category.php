<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.stock_balances_branch_category');

$pageTitle = 'Stock Balances for Branch by Category';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCategory = $_GET['category_id'] ?? 'all';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Build query conditions
$whereConditions = ["p.status = 'Active'"];
$params = [];

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

$whereClause = implode(' AND ', $whereConditions);

// Get stock balances grouped by category
$stockByCategory = $db->getRows("SELECT 
    pc.id as category_id,
    pc.name as category_name,
    b.id as branch_id,
    b.branch_name,
    COUNT(DISTINCT p.id) as product_count,
    COALESCE(SUM(p.quantity_in_stock), 0) as total_quantity,
    COALESCE(SUM(p.quantity_in_stock * p.cost_price), 0) as total_value,
    COALESCE(SUM(p.quantity_in_stock * p.selling_price), 0) as total_selling_value,
    COUNT(CASE WHEN p.quantity_in_stock <= p.reorder_level AND p.reorder_level > 0 THEN 1 END) as low_stock_count
FROM products p
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN branches b ON p.branch_id = b.id
WHERE $whereClause
GROUP BY pc.id, pc.name, b.id, b.branch_name
ORDER BY b.branch_name, pc.name", $params);

if ($stockByCategory === false) {
    $stockByCategory = [];
}

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT p.id) as total_products,
    COUNT(DISTINCT p.category_id) as total_categories,
    COALESCE(SUM(p.quantity_in_stock), 0) as total_quantity,
    COALESCE(SUM(p.quantity_in_stock * p.cost_price), 0) as total_value
FROM products p
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_products' => 0,
        'total_categories' => 0,
        'total_quantity' => 0,
        'total_value' => 0
    ];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Stock Balances for Branch by Category</h2>';
    $branchName = $selectedBranch !== 'all' && $selectedBranch ? 
        ($db->getRow("SELECT branch_name FROM branches WHERE id = :id", [':id' => $selectedBranch])['branch_name'] ?? 'All Branches') : 
        'All Branches';
    $html .= '<p style="text-align: center; color: #666;">Branch: ' . escapeHtml($branchName) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Products</td><td style="text-align: right;">' . $summary['total_products'] . '</td></tr>';
    $html .= '<tr><td>Total Categories</td><td style="text-align: right;">' . $summary['total_categories'] . '</td></tr>';
    $html .= '<tr><td>Total Quantity</td><td style="text-align: right;">' . number_format($summary['total_quantity'], 2) . '</td></tr>';
    $html .= '<tr><td>Total Stock Value</td><td style="text-align: right;">' . formatCurrency($summary['total_value']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Stock by Category</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Branch</th><th>Category</th><th>Products</th><th style="text-align: right;">Total Qty</th><th style="text-align: right;">Stock Value</th><th style="text-align: right;">Selling Value</th><th>Low Stock</th></tr>';
    foreach ($stockByCategory as $item) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($item['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($item['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . $item['product_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($item['total_quantity'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['total_value']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['total_selling_value']) . '</td>';
        $html .= '<td>' . ($item['low_stock_count'] > 0 ? '<span style="color: #f97316;">' . $item['low_stock_count'] . '</span>' : '0') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Stock Balances for Branch by Category', $html, 'Stock_Balances_Category_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-boxes"></i> Stock Balances for Branch by Category</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-5">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                <select name="category_id" class="form-select">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $selectedCategory == $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
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
                <h6 class="text-muted mb-2">Total Categories</h6>
                <h3 class="mb-0"><?= $summary['total_categories'] ?></h3>
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
                <h6 class="text-muted mb-2">Total Stock Value</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_value']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="stockByCategoryTable">
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Category</th>
                        <th class="text-end">Products</th>
                        <th class="text-end">Total Qty</th>
                        <th class="text-end">Stock Value</th>
                        <th class="text-end">Selling Value</th>
                        <th class="text-end">Low Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockByCategory)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No stock balances found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stockByCategory as $item): ?>
                            <tr>
                                <td><?= escapeHtml($item['branch_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($item['category_name'] ?? 'Uncategorized') ?></td>
                                <td class="text-end"><?= $item['product_count'] ?></td>
                                <td class="text-end"><?= number_format($item['total_quantity'], 2) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($item['total_value']) ?></td>
                                <td class="text-end"><?= formatCurrency($item['total_selling_value']) ?></td>
                                <td class="text-end">
                                    <?php if ($item['low_stock_count'] > 0): ?>
                                        <span class="badge bg-warning"><?= $item['low_stock_count'] ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success">0</span>
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
    $('#stockByCategoryTable').DataTable({
        order: [[4, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


