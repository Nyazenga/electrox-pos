<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Reports Dashboard';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Default to today's date range
$startDate = date('Y-m-01'); // First day of current month
$endDate = date('Y-m-d'); // Today

// Build base query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

$whereClause = implode(' AND ', $whereConditions);

// ========== SALES SUMMARY STATS ==========
$salesSummary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_receipts,
    COALESCE(SUM(s.total_amount), 0) as gross_sales,
    COALESCE(SUM(s.discount_amount), 0) as total_discount,
    COALESCE(SUM(s.tax_amount), 0) as total_tax,
    COALESCE(SUM(CASE WHEN s.payment_status = 'refunded' THEN s.total_amount ELSE 0 END), 0) as total_refunds
FROM sales s
WHERE $whereClause", $params);

if ($salesSummary === false) {
    $salesSummary = [
        'total_receipts' => 0,
        'gross_sales' => 0,
        'total_discount' => 0,
        'total_tax' => 0,
        'total_refunds' => 0
    ];
}

$netSales = $salesSummary['gross_sales'] - $salesSummary['total_refunds'] - $salesSummary['total_discount'];

// Get product cost
$productCost = $db->getRow("SELECT COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost
                            FROM sale_items si
                            INNER JOIN sales s ON si.sale_id = s.id
                            LEFT JOIN products p ON si.product_id = p.id
                            WHERE $whereClause", $params);
if ($productCost === false) {
    $productCost = ['total_cost' => 0];
}

$grossProfit = $netSales - $productCost['total_cost'];
$profitMargin = $netSales > 0 ? (($grossProfit / $netSales) * 100) : 0;

// ========== RECEIPTS STATS ==========
$receiptsStats = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_receipts,
    COUNT(DISTINCT s.customer_id) as unique_customers,
    COALESCE(AVG(s.total_amount), 0) as avg_receipt_value
FROM sales s
WHERE $whereClause", $params);

if ($receiptsStats === false) {
    $receiptsStats = ['total_receipts' => 0, 'unique_customers' => 0, 'avg_receipt_value' => 0];
}

// ========== REFUNDS STATS ==========
$refundsWhereConditions = ["DATE(r.refund_date) BETWEEN :start_date AND :end_date", "r.status = 'completed'"];
$refundsParams = [':start_date' => $startDate, ':end_date' => $endDate];
if ($branchId !== null) {
    $refundsWhereConditions[] = "r.branch_id = :branch_id";
    $refundsParams[':branch_id'] = $branchId;
}
$refundsWhereClause = implode(' AND ', $refundsWhereConditions);

$refundsStats = $db->getRow("SELECT 
    COUNT(DISTINCT r.id) as total_refunds,
    COALESCE(SUM(r.total_amount), 0) as total_refund_amount
FROM refunds r
WHERE $refundsWhereClause", $refundsParams);

if ($refundsStats === false) {
    $refundsStats = ['total_refunds' => 0, 'total_refund_amount' => 0];
}

// ========== PRODUCTS STATS ==========
$productsStats = $db->getRow("SELECT 
    COUNT(DISTINCT si.product_id) as unique_products_sold,
    SUM(si.quantity) as total_units_sold
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
WHERE $whereClause", $params);

if ($productsStats === false) {
    $productsStats = ['unique_products_sold' => 0, 'total_units_sold' => 0];
}

// ========== CATEGORY STATS ==========
$categoryStats = $db->getRows("SELECT 
    pc.name as category_name,
    COUNT(DISTINCT si.product_id) as product_count,
    SUM(si.quantity) as units_sold,
    COALESCE(SUM(si.total_price), 0) as category_sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE $whereClause
GROUP BY pc.id, pc.name
ORDER BY category_sales DESC
LIMIT 5", $params);

if ($categoryStats === false) {
    $categoryStats = [];
}

// ========== PAYMENT TYPES STATS ==========
$paymentStats = $db->getRows("SELECT 
    sp.payment_method,
    COUNT(DISTINCT sp.sale_id) as transaction_count,
    COALESCE(SUM(sp.amount), 0) as total_amount
FROM sale_payments sp
INNER JOIN sales s ON sp.sale_id = s.id
WHERE $whereClause
GROUP BY sp.payment_method
ORDER BY total_amount DESC", $params);

if ($paymentStats === false) {
    $paymentStats = [];
}

// ========== SHIFTS STATS ==========
$shiftsWhereConditions = ["DATE(s.opened_at) BETWEEN :start_date AND :end_date"];
$shiftsParams = [':start_date' => $startDate, ':end_date' => $endDate];
if ($branchId !== null) {
    $shiftsWhereConditions[] = "s.branch_id = :branch_id";
    $shiftsParams[':branch_id'] = $branchId;
}
$shiftsWhereClause = implode(' AND ', $shiftsWhereConditions);

$shiftsStats = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_shifts,
    COUNT(DISTINCT CASE WHEN s.closed_at IS NULL THEN s.id END) as open_shifts,
    COALESCE(SUM(s.expected_cash), 0) as total_expected_cash,
    COALESCE(SUM(s.actual_cash), 0) as total_actual_cash
FROM shifts s
WHERE $shiftsWhereClause", $shiftsParams);

if ($shiftsStats === false) {
    $shiftsStats = ['total_shifts' => 0, 'open_shifts' => 0, 'total_expected_cash' => 0, 'total_actual_cash' => 0];
}

$cashDifference = $shiftsStats['total_actual_cash'] - $shiftsStats['total_expected_cash'];

// ========== DELETED RECEIPTS STATS ==========
// Check if deleted_at column exists
$hasDeletedAtColumn = false;
try {
    $colCheck = $db->getRow("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'sales' 
                            AND COLUMN_NAME = 'deleted_at'");
    $hasDeletedAtColumn = ($colCheck && $colCheck['count'] > 0);
} catch (Exception $e) {
    $hasDeletedAtColumn = false;
}

$deletedReceiptsStats = ['total_deleted' => 0];
if ($hasDeletedAtColumn) {
    $deletedWhereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", "s.deleted_at IS NOT NULL"];
    $deletedParams = [':start_date' => $startDate, ':end_date' => $endDate];
    if ($branchId !== null) {
        $deletedWhereConditions[] = "s.branch_id = :branch_id";
        $deletedParams[':branch_id'] = $branchId;
    }
    $deletedWhereClause = implode(' AND ', $deletedWhereConditions);
    
    $deletedStats = $db->getRow("SELECT COUNT(DISTINCT s.id) as total_deleted
                                FROM sales s
                                WHERE $deletedWhereClause", $deletedParams);
    if ($deletedStats !== false) {
        $deletedReceiptsStats = $deletedStats;
    }
}

// ========== TOP PRODUCTS ==========
$topProducts = $db->getRows("SELECT 
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    SUM(si.quantity) as units_sold,
    COALESCE(SUM(si.total_price), 0) as total_sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause
GROUP BY si.product_id, product_name
ORDER BY total_sales DESC
LIMIT 5", $params);

if ($topProducts === false) {
    $topProducts = [];
}

// ========== STAFF STATS ==========
$staffStats = $db->getRows("SELECT 
    CONCAT(u.first_name, ' ', u.last_name) as staff_name,
    COUNT(DISTINCT s.id) as receipt_count,
    COALESCE(SUM(s.total_amount), 0) as total_sales
FROM sales s
LEFT JOIN users u ON s.user_id = u.id
WHERE $whereClause
GROUP BY s.user_id, staff_name
ORDER BY total_sales DESC
LIMIT 5", $params);

if ($staffStats === false) {
    $staffStats = [];
}

require_once APP_PATH . '/includes/header.php';
?>

<style>
.stat-card {
    border-left: 4px solid;
    transition: transform 0.2s, box-shadow 0.2s;
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.stat-card.primary { border-left-color: #1e3a8a; }
.stat-card.success { border-left-color: #198754; }
.stat-card.info { border-left-color: #0dcaf0; }
.stat-card.warning { border-left-color: #ffc107; }
.stat-card.danger { border-left-color: #dc3545; }
.stat-card.secondary { border-left-color: #6c757d; }
.stat-value {
    font-size: 2rem;
    font-weight: bold;
    margin: 0.5rem 0;
}
.stat-label {
    color: #6c757d;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.stat-icon {
    font-size: 2.5rem;
    opacity: 0.3;
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-graph-up"></i> Reports Dashboard</h2>
    <div>
        <small class="text-muted">Period: <?= date('M d, Y', strtotime($startDate)) ?> - <?= date('M d, Y', strtotime($endDate)) ?></small>
    </div>
</div>

<!-- Sales Overview -->
<div class="row mb-4">
    <div class="col-12">
        <h4 class="mb-3"><i class="bi bi-bar-chart"></i> Sales Overview</h4>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card primary">
            <div class="card-body position-relative">
                <div class="stat-label">Total Receipts</div>
                <div class="stat-value text-primary"><?= number_format($salesSummary['total_receipts']) ?></div>
                <i class="bi bi-receipt stat-icon text-primary"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card success">
            <div class="card-body position-relative">
                <div class="stat-label">Gross Sales</div>
                <div class="stat-value text-success"><?= formatCurrency($salesSummary['gross_sales']) ?></div>
                <i class="bi bi-currency-dollar stat-icon text-success"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card info">
            <div class="card-body position-relative">
                <div class="stat-label">Net Sales</div>
                <div class="stat-value text-info"><?= formatCurrency($netSales) ?></div>
                <i class="bi bi-cash-coin stat-icon text-info"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card warning">
            <div class="card-body position-relative">
                <div class="stat-label">Gross Profit</div>
                <div class="stat-value text-warning"><?= formatCurrency($grossProfit) ?></div>
                <i class="bi bi-graph-up-arrow stat-icon text-warning"></i>
            </div>
        </div>
    </div>
</div>

<!-- Financial Metrics -->
<div class="row mb-4">
    <div class="col-12">
        <h4 class="mb-3"><i class="bi bi-calculator"></i> Financial Metrics</h4>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card secondary">
            <div class="card-body position-relative">
                <div class="stat-label">Total Discounts</div>
                <div class="stat-value text-secondary"><?= formatCurrency($salesSummary['total_discount']) ?></div>
                <i class="bi bi-percent stat-icon text-secondary"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card info">
            <div class="card-body position-relative">
                <div class="stat-label">Total Taxes</div>
                <div class="stat-value text-info"><?= formatCurrency($salesSummary['total_tax']) ?></div>
                <i class="bi bi-receipt stat-icon text-info"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card danger">
            <div class="card-body position-relative">
                <div class="stat-label">Total Refunds</div>
                <div class="stat-value text-danger"><?= formatCurrency($salesSummary['total_refunds']) ?></div>
                <i class="bi bi-arrow-counterclockwise stat-icon text-danger"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card success">
            <div class="card-body position-relative">
                <div class="stat-label">Profit Margin</div>
                <div class="stat-value text-success"><?= number_format($profitMargin, 2) ?>%</div>
                <i class="bi bi-percent stat-icon text-success"></i>
            </div>
        </div>
    </div>
</div>

<!-- Transactions & Customers -->
<div class="row mb-4">
    <div class="col-12">
        <h4 class="mb-3"><i class="bi bi-people"></i> Transactions & Customers</h4>
    </div>
    <div class="col-md-4 col-sm-6 mb-3">
        <div class="card stat-card primary">
            <div class="card-body position-relative">
                <div class="stat-label">Unique Customers</div>
                <div class="stat-value text-primary"><?= number_format($receiptsStats['unique_customers']) ?></div>
                <i class="bi bi-person stat-icon text-primary"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6 mb-3">
        <div class="card stat-card info">
            <div class="card-body position-relative">
                <div class="stat-label">Avg Receipt Value</div>
                <div class="stat-value text-info"><?= formatCurrency($receiptsStats['avg_receipt_value']) ?></div>
                <i class="bi bi-calculator stat-icon text-info"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6 mb-3">
        <div class="card stat-card warning">
            <div class="card-body position-relative">
                <div class="stat-label">Refund Transactions</div>
                <div class="stat-value text-warning"><?= number_format($refundsStats['total_refunds']) ?></div>
                <i class="bi bi-arrow-counterclockwise stat-icon text-warning"></i>
            </div>
        </div>
    </div>
</div>

<!-- Products & Inventory -->
<div class="row mb-4">
    <div class="col-12">
        <h4 class="mb-3"><i class="bi bi-box-seam"></i> Products & Inventory</h4>
    </div>
    <div class="col-md-4 col-sm-6 mb-3">
        <div class="card stat-card success">
            <div class="card-body position-relative">
                <div class="stat-label">Products Sold</div>
                <div class="stat-value text-success"><?= number_format($productsStats['unique_products_sold']) ?></div>
                <i class="bi bi-box stat-icon text-success"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6 mb-3">
        <div class="card stat-card info">
            <div class="card-body position-relative">
                <div class="stat-label">Units Sold</div>
                <div class="stat-value text-info"><?= number_format($productsStats['total_units_sold']) ?></div>
                <i class="bi bi-cart stat-icon text-info"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6 mb-3">
        <div class="card stat-card secondary">
            <div class="card-body position-relative">
                <div class="stat-label">Product Cost</div>
                <div class="stat-value text-secondary"><?= formatCurrency($productCost['total_cost']) ?></div>
                <i class="bi bi-tag stat-icon text-secondary"></i>
            </div>
        </div>
    </div>
</div>

<!-- Shifts & Cash -->
<div class="row mb-4">
    <div class="col-12">
        <h4 class="mb-3"><i class="bi bi-clock-history"></i> Shifts & Cash</h4>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card primary">
            <div class="card-body position-relative">
                <div class="stat-label">Total Shifts</div>
                <div class="stat-value text-primary"><?= number_format($shiftsStats['total_shifts']) ?></div>
                <i class="bi bi-clock stat-icon text-primary"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card warning">
            <div class="card-body position-relative">
                <div class="stat-label">Open Shifts</div>
                <div class="stat-value text-warning"><?= number_format($shiftsStats['open_shifts']) ?></div>
                <i class="bi bi-clock-history stat-icon text-warning"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card success">
            <div class="card-body position-relative">
                <div class="stat-label">Expected Cash</div>
                <div class="stat-value text-success"><?= formatCurrency($shiftsStats['total_expected_cash']) ?></div>
                <i class="bi bi-cash stat-icon text-success"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card stat-card <?= $cashDifference >= 0 ? 'success' : 'danger' ?>">
            <div class="card-body position-relative">
                <div class="stat-label">Cash Difference</div>
                <div class="stat-value text-<?= $cashDifference >= 0 ? 'success' : 'danger' ?>"><?= formatCurrency($cashDifference) ?></div>
                <i class="bi bi-<?= $cashDifference >= 0 ? 'arrow-up' : 'arrow-down' ?> stat-icon text-<?= $cashDifference >= 0 ? 'success' : 'danger' ?>"></i>
            </div>
        </div>
    </div>
</div>

<!-- Top Categories -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-tags"></i> Top Categories by Sales</h5>
            </div>
            <div class="card-body">
                <?php if (empty($categoryStats)): ?>
                    <p class="text-muted mb-0">No category data available</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th class="text-end">Products</th>
                                    <th class="text-end">Units Sold</th>
                                    <th class="text-end">Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categoryStats as $cat): ?>
                                    <tr>
                                        <td><?= escapeHtml($cat['category_name'] ?? 'Uncategorized') ?></td>
                                        <td class="text-end"><?= number_format($cat['product_count']) ?></td>
                                        <td class="text-end"><?= number_format($cat['units_sold']) ?></td>
                                        <td class="text-end"><?= formatCurrency($cat['category_sales']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Top Products -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-star"></i> Top Products by Sales</h5>
            </div>
            <div class="card-body">
                <?php if (empty($topProducts)): ?>
                    <p class="text-muted mb-0">No product data available</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Units</th>
                                    <th class="text-end">Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topProducts as $product): ?>
                                    <tr>
                                        <td><?= escapeHtml($product['product_name'] ?? 'Unknown') ?></td>
                                        <td class="text-end"><?= number_format($product['units_sold']) ?></td>
                                        <td class="text-end"><?= formatCurrency($product['total_sales']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Payment Methods & Staff Performance -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-credit-card"></i> Payment Methods</h5>
            </div>
            <div class="card-body">
                <?php if (empty($paymentStats)): ?>
                    <p class="text-muted mb-0">No payment data available</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Method</th>
                                    <th class="text-end">Transactions</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paymentStats as $payment): ?>
                                    <tr>
                                        <td><?= escapeHtml(ucfirst($payment['payment_method'])) ?></td>
                                        <td class="text-end"><?= number_format($payment['transaction_count']) ?></td>
                                        <td class="text-end"><?= formatCurrency($payment['total_amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Top Staff -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-person-badge"></i> Top Staff by Sales</h5>
            </div>
            <div class="card-body">
                <?php if (empty($staffStats)): ?>
                    <p class="text-muted mb-0">No staff data available</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Staff</th>
                                    <th class="text-end">Receipts</th>
                                    <th class="text-end">Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($staffStats as $staff): ?>
                                    <tr>
                                        <td><?= escapeHtml($staff['staff_name'] ?? 'Unknown') ?></td>
                                        <td class="text-end"><?= number_format($staff['receipt_count']) ?></td>
                                        <td class="text-end"><?= formatCurrency($staff['total_sales']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Suspicious Activity -->
<?php if ($deletedReceiptsStats['total_deleted'] > 0): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Suspicious Activity</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="stat-label">Deleted Receipts</div>
                        <div class="stat-value text-danger"><?= number_format($deletedReceiptsStats['total_deleted']) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
