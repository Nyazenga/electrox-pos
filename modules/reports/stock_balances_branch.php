<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.stock_balances_branch');

$pageTitle = 'Stock Balances for Branch';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCategory = $_GET['category_id'] ?? 'all';
$search = $_GET['search'] ?? '';
$lowStockOnly = isset($_GET['low_stock_only']) && $_GET['low_stock_only'] == '1';

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

if (!empty($search)) {
    $whereConditions[] = "(p.product_name LIKE :search OR p.product_code LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($lowStockOnly) {
    $whereConditions[] = "p.quantity_in_stock <= p.reorder_level AND p.reorder_level > 0";
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT p.id) as total_products,
    COALESCE(SUM(p.quantity_in_stock), 0) as total_quantity,
    COALESCE(SUM(p.quantity_in_stock * p.cost_price), 0) as total_value,
    COUNT(CASE WHEN p.quantity_in_stock <= p.reorder_level AND p.reorder_level > 0 THEN 1 END) as low_stock_count
FROM products p
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_products' => 0,
        'total_quantity' => 0,
        'total_value' => 0,
        'low_stock_count' => 0
    ];
}

// Get stock balances
$stockBalances = $db->getRows("SELECT 
    p.id,
    p.product_code,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    pc.name as category_name,
    b.branch_name,
    p.quantity_in_stock,
    p.reorder_level,
    p.cost_price,
    p.selling_price,
    (p.quantity_in_stock * p.cost_price) as stock_value,
    CASE 
        WHEN p.quantity_in_stock <= p.reorder_level AND p.reorder_level > 0 THEN 'Low'
        WHEN p.quantity_in_stock = 0 THEN 'Out of Stock'
        ELSE 'In Stock'
    END as stock_status
FROM products p
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN branches b ON p.branch_id = b.id
WHERE $whereClause
ORDER BY p.product_code, p.product_name
LIMIT 2000", $params);

if ($stockBalances === false) {
    $stockBalances = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Stock Balances for Branch</h2>';
    $branchName = $selectedBranch !== 'all' && $selectedBranch ? 
        ($db->getRow("SELECT branch_name FROM branches WHERE id = :id", [':id' => $selectedBranch])['branch_name'] ?? 'All Branches') : 
        'All Branches';
    $html .= '<p style="text-align: center; color: #666;">Branch: ' . escapeHtml($branchName) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Products</td><td style="text-align: right;">' . $summary['total_products'] . '</td></tr>';
    $html .= '<tr><td>Total Quantity</td><td style="text-align: right;">' . number_format($summary['total_quantity'], 2) . '</td></tr>';
    $html .= '<tr><td>Total Stock Value</td><td style="text-align: right;">' . formatCurrency($summary['total_value']) . '</td></tr>';
    $html .= '<tr><td>Low Stock Items</td><td style="text-align: right;">' . $summary['low_stock_count'] . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Stock Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product Code</th><th>Product Name</th><th>Category</th><th>Branch</th><th style="text-align: right;">Qty in Stock</th><th style="text-align: right;">Reorder Level</th><th style="text-align: right;">Cost Price</th><th style="text-align: right;">Selling Price</th><th style="text-align: right;">Stock Value</th><th>Status</th></tr>';
    foreach ($stockBalances as $item) {
        $statusColor = $item['stock_status'] == 'Low' ? '#f97316' : ($item['stock_status'] == 'Out of Stock' ? '#dc2626' : '#10b981');
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($item['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($item['product_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($item['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . escapeHtml($item['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($item['quantity_in_stock'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($item['reorder_level'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['cost_price']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['selling_price']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['stock_value']) . '</td>';
        $html .= '<td style="color: ' . $statusColor . '; font-weight: bold;">' . escapeHtml($item['stock_status']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Stock Balances for Branch', $html, 'Stock_Balances_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-boxes"></i> Stock Balances for Branch</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
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
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-search"></i> Search</label>
                <input type="text" name="search" value="<?= escapeHtml($search) ?>" class="form-control" placeholder="Search products...">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="low_stock_only" id="lowStockOnly" value="1" <?= $lowStockOnly ? 'checked' : '' ?>>
                    <label class="form-check-label" for="lowStockOnly">Low Stock Only</label>
                </div>
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
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Low Stock Items</h6>
                <h3 class="mb-0 text-danger"><?= $summary['low_stock_count'] ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="stockBalancesTable">
                <thead>
                    <tr>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Branch</th>
                        <th class="text-end">Qty in Stock</th>
                        <th class="text-end">Reorder Level</th>
                        <th class="text-end">Cost Price</th>
                        <th class="text-end">Selling Price</th>
                        <th class="text-end">Stock Value</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockBalances)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">No stock balances found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stockBalances as $item): ?>
                            <tr>
                                <td><?= escapeHtml($item['product_code'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($item['product_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($item['category_name'] ?? 'Uncategorized') ?></td>
                                <td><?= escapeHtml($item['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= number_format($item['quantity_in_stock'], 2) ?></td>
                                <td class="text-end"><?= number_format($item['reorder_level'], 2) ?></td>
                                <td class="text-end"><?= formatCurrency($item['cost_price']) ?></td>
                                <td class="text-end"><?= formatCurrency($item['selling_price']) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($item['stock_value']) ?></td>
                                <td>
                                    <?php if ($item['stock_status'] == 'Low'): ?>
                                        <span class="badge bg-warning">Low</span>
                                    <?php elseif ($item['stock_status'] == 'Out of Stock'): ?>
                                        <span class="badge bg-danger">Out of Stock</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">In Stock</span>
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
    $('#stockBalancesTable').DataTable({
        order: [[4, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

