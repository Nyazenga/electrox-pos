<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.stock_expiry_report');

$pageTitle = 'Stock Expiry Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCategory = $_GET['category_id'] ?? 'all';
$expiryFilter = $_GET['expiry_filter'] ?? 'all'; // all, expired, expiring_soon, expiring_3months
$search = $_GET['search'] ?? '';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Build query conditions
$whereConditions = ["p.status = 'Active'", "p.expiry_date IS NOT NULL"];
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

$today = date('Y-m-d');
$threeMonthsFromNow = date('Y-m-d', strtotime('+3 months'));

if ($expiryFilter === 'expired') {
    $whereConditions[] = "p.expiry_date < :today";
    $params[':today'] = $today;
} elseif ($expiryFilter === 'expiring_soon') {
    $whereConditions[] = "p.expiry_date BETWEEN :today AND :three_months";
    $params[':today'] = $today;
    $params[':three_months'] = $threeMonthsFromNow;
} elseif ($expiryFilter === 'expiring_3months') {
    $whereConditions[] = "p.expiry_date <= :three_months";
    $params[':three_months'] = $threeMonthsFromNow;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT p.id) as total_products,
    COUNT(CASE WHEN p.expiry_date < :today THEN 1 END) as expired_count,
    COUNT(CASE WHEN p.expiry_date BETWEEN :today AND :three_months THEN 1 END) as expiring_soon_count,
    COALESCE(SUM(p.quantity_in_stock), 0) as total_quantity
FROM products p
WHERE " . str_replace([':today', ':three_months'], ["'$today'", "'$threeMonthsFromNow'"], $whereClause), $params);

if ($summary === false) {
    $summary = [
        'total_products' => 0,
        'expired_count' => 0,
        'expiring_soon_count' => 0,
        'total_quantity' => 0
    ];
}

// Get products with expiry dates
$products = $db->getRows("SELECT 
    p.id,
    p.product_code,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    pc.name as category_name,
    b.branch_name,
    p.expiry_date,
    p.quantity_in_stock,
    p.cost_price,
    p.selling_price,
    DATEDIFF(p.expiry_date, CURDATE()) as days_until_expiry,
    CASE 
        WHEN p.expiry_date < CURDATE() THEN 'Expired'
        WHEN p.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expiring Soon'
        WHEN p.expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 'Expiring in 3 Months'
        ELSE 'Valid'
    END as expiry_status
FROM products p
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN branches b ON p.branch_id = b.id
WHERE $whereClause
ORDER BY p.expiry_date ASC, p.product_code
LIMIT 2000", $params);

if ($products === false) {
    $products = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Stock Expiry Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Generated: ' . date('M d, Y H:i') . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Products</td><td style="text-align: right;">' . $summary['total_products'] . '</td></tr>';
    $html .= '<tr><td>Expired</td><td style="text-align: right; color: #dc2626;">' . $summary['expired_count'] . '</td></tr>';
    $html .= '<tr><td>Expiring Soon</td><td style="text-align: right; color: #f97316;">' . $summary['expiring_soon_count'] . '</td></tr>';
    $html .= '<tr><td>Total Quantity</td><td style="text-align: right;">' . number_format($summary['total_quantity'], 2) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Products with Expiry Dates</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product Code</th><th>Product Name</th><th>Category</th><th>Branch</th><th>Expiry Date</th><th>Days Until</th><th>Status</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Value</th></tr>';
    foreach ($products as $product) {
        $statusColor = $product['expiry_status'] == 'Expired' ? '#dc2626' : ($product['expiry_status'] == 'Expiring Soon' ? '#f97316' : '#10b981');
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($product['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml(substr($product['product_name'] ?? 'N/A', 0, 25)) . '</td>';
        $html .= '<td>' . escapeHtml($product['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . escapeHtml($product['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . date('M d, Y', strtotime($product['expiry_date'])) . '</td>';
        $html .= '<td>' . $product['days_until_expiry'] . '</td>';
        $html .= '<td style="color: ' . $statusColor . '; font-weight: bold;">' . escapeHtml($product['expiry_status']) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($product['quantity_in_stock'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['quantity_in_stock'] * $product['cost_price']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Stock Expiry Report', $html, 'Stock_Expiry_Report_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-calendar-x"></i> Stock Expiry Report</h2>
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
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-filter"></i> Expiry Filter</label>
                <select name="expiry_filter" class="form-select">
                    <option value="all" <?= $expiryFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="expired" <?= $expiryFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="expiring_soon" <?= $expiryFilter === 'expiring_soon' ? 'selected' : '' ?>>Expiring Soon</option>
                    <option value="expiring_3months" <?= $expiryFilter === 'expiring_3months' ? 'selected' : '' ?>>Expiring in 3 Months</option>
                </select>
            </div>
            <div class="col-md-4">
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
                <h6 class="text-muted mb-2">Expired</h6>
                <h3 class="mb-0 text-danger"><?= $summary['expired_count'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Expiring Soon</h6>
                <h3 class="mb-0 text-warning"><?= $summary['expiring_soon_count'] ?></h3>
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
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="expiryTable">
                <thead>
                    <tr>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Branch</th>
                        <th>Expiry Date</th>
                        <th class="text-end">Days Until</th>
                        <th>Status</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No products with expiry dates found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><span class="fw-bold"><?= escapeHtml($product['product_code'] ?? 'N/A') ?></span></td>
                                <td><?= escapeHtml($product['product_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($product['category_name'] ?? 'Uncategorized') ?></td>
                                <td><?= escapeHtml($product['branch_name'] ?? 'N/A') ?></td>
                                <td><?= date('M d, Y', strtotime($product['expiry_date'])) ?></td>
                                <td class="text-end">
                                    <span class="fw-bold <?= $product['days_until_expiry'] < 0 ? 'text-danger' : ($product['days_until_expiry'] < 30 ? 'text-warning' : 'text-success') ?>">
                                        <?= $product['days_until_expiry'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($product['expiry_status'] == 'Expired'): ?>
                                        <span class="badge bg-danger">Expired</span>
                                    <?php elseif ($product['expiry_status'] == 'Expiring Soon'): ?>
                                        <span class="badge bg-warning">Expiring Soon</span>
                                    <?php elseif ($product['expiry_status'] == 'Expiring in 3 Months'): ?>
                                        <span class="badge bg-info">Expiring in 3 Months</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Valid</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format($product['quantity_in_stock'], 2) ?></td>
                                <td class="text-end"><?= formatCurrency($product['quantity_in_stock'] * $product['cost_price']) ?></td>
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
    $('#expiryTable').DataTable({
        order: [[4, 'asc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

