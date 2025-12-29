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
/* Modern Dashboard Styles */
.dashboard-container {
    padding: 0;
    background: var(--light-gray);
    min-height: 100vh;
}

/* Modern Stat Cards */
.stat-card-modern {
    background: var(--white);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
    border: 1px solid var(--border-color);
    height: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    animation: fadeInUp 0.5s ease-out;
}

.stat-card-modern::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--secondary-blue);
    transition: width 0.3s ease;
}

.stat-card-modern:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}

.stat-card-modern:hover::before {
    width: 6px;
}

.stat-card-modern.primary::before { background: #1e3a8a; }
.stat-card-modern.success::before { background: #10b981; }
.stat-card-modern.info::before { background: #06b6d4; }
.stat-card-modern.warning::before { background: #f59e0b; }
.stat-card-modern.danger::before { background: #ef4444; }
.stat-card-modern.secondary::before { background: #6b7280; }

.stat-card-header-modern {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 12px;
}

.stat-icon-modern {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: var(--white);
    flex-shrink: 0;
    animation: iconPulse 2s ease-in-out infinite;
    position: relative;
}

.stat-icon-modern::after {
    content: '';
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: 12px;
    opacity: 0.3;
    animation: iconRipple 2s ease-in-out infinite;
}

.stat-icon-modern.primary { background: #1e3a8a; }
.stat-icon-modern.success { background: #10b981; }
.stat-icon-modern.info { background: #06b6d4; }
.stat-icon-modern.warning { background: #f59e0b; }
.stat-icon-modern.danger { background: #ef4444; }
.stat-icon-modern.secondary { background: #6b7280; }

.stat-content-modern {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.stat-label-modern {
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    font-weight: 600;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.stat-label-modern i {
    font-size: 11px;
}

.stat-value-modern {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
    line-height: 1.2;
    animation: countUp 0.8s ease-out;
}

/* Section Headers */
.section-header-modern {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border-color);
}

.section-header-modern i {
    font-size: 16px;
    color: var(--secondary-blue);
}

/* Table Cards */
.table-card-modern {
    background: var(--white);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
    border: 1px solid var(--border-color);
    height: 100%;
    animation: fadeInUp 0.6s ease-out;
}

.table-card-header-modern {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-color);
}

.table-title-modern {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.table-title-modern i {
    font-size: 16px;
    color: var(--secondary-blue);
}

.table-card-modern .table {
    font-size: 11px;
    margin-bottom: 0;
}

.table-card-modern .table thead th {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    padding: 10px 8px;
    border-bottom: 2px solid var(--border-color);
}

.table-card-modern .table tbody td {
    padding: 10px 8px;
    font-size: 11px;
}

/* Period Display */
.period-display {
    font-size: 11px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
}

.period-display i {
    font-size: 12px;
}

/* Animations */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes iconPulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

@keyframes iconRipple {
    0% {
        transform: scale(1);
        opacity: 0.3;
    }
    100% {
        transform: scale(1.3);
        opacity: 0;
    }
}

@keyframes countUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Stagger animation delays for cards */
.stat-card-modern:nth-child(1) { animation-delay: 0.1s; }
.stat-card-modern:nth-child(2) { animation-delay: 0.2s; }
.stat-card-modern:nth-child(3) { animation-delay: 0.3s; }
.stat-card-modern:nth-child(4) { animation-delay: 0.4s; }
.stat-card-modern:nth-child(5) { animation-delay: 0.5s; }
.stat-card-modern:nth-child(6) { animation-delay: 0.6s; }

/* Responsive Design */
@media (max-width: 1366px) {
    .stat-value-modern {
        font-size: 20px;
    }
    
    .stat-icon-modern {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
}

@media (max-width: 768px) {
    .stat-value-modern {
        font-size: 18px;
    }
    
    .stat-icon-modern {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
}
</style>

<div class="dashboard-container">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 style="font-size: 18px; font-weight: 700; margin: 0;">
            <i class="bi bi-graph-up"></i> Reports Dashboard
        </h2>
        <div class="period-display">
            <i class="bi bi-calendar-range"></i>
            <span>Period: <?= date('M d, Y', strtotime($startDate)) ?> - <?= date('M d, Y', strtotime($endDate)) ?></span>
        </div>
    </div>

    <!-- Sales Overview -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="section-header-modern">
                <i class="bi bi-bar-chart"></i>
                <span>Sales Overview</span>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern primary">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-receipt"></i>
                            <span>Total Receipts</span>
                        </div>
                        <div class="stat-value-modern" style="color: #1e3a8a;"><?= number_format($salesSummary['total_receipts']) ?></div>
                    </div>
                    <div class="stat-icon-modern primary">
                        <i class="bi bi-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern success">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-currency-dollar"></i>
                            <span>Gross Sales</span>
                        </div>
                        <div class="stat-value-modern" style="color: #10b981;"><?= formatCurrency($salesSummary['gross_sales']) ?></div>
                    </div>
                    <div class="stat-icon-modern success">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern info">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-cash-coin"></i>
                            <span>Net Sales</span>
                        </div>
                        <div class="stat-value-modern" style="color: #06b6d4;"><?= formatCurrency($netSales) ?></div>
                    </div>
                    <div class="stat-icon-modern info">
                        <i class="bi bi-cash-coin"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern warning">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-graph-up-arrow"></i>
                            <span>Gross Profit</span>
                        </div>
                        <div class="stat-value-modern" style="color: #f59e0b;"><?= formatCurrency($grossProfit) ?></div>
                    </div>
                    <div class="stat-icon-modern warning">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="section-header-modern">
                <i class="bi bi-calculator"></i>
                <span>Financial Metrics</span>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern secondary">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-percent"></i>
                            <span>Total Discounts</span>
                        </div>
                        <div class="stat-value-modern" style="color: #6b7280;"><?= formatCurrency($salesSummary['total_discount']) ?></div>
                    </div>
                    <div class="stat-icon-modern secondary">
                        <i class="bi bi-percent"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern info">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-receipt"></i>
                            <span>Total Taxes</span>
                        </div>
                        <div class="stat-value-modern" style="color: #06b6d4;"><?= formatCurrency($salesSummary['total_tax']) ?></div>
                    </div>
                    <div class="stat-icon-modern info">
                        <i class="bi bi-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern danger">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span>Total Refunds</span>
                        </div>
                        <div class="stat-value-modern" style="color: #ef4444;"><?= formatCurrency($salesSummary['total_refunds']) ?></div>
                    </div>
                    <div class="stat-icon-modern danger">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern success">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-percent"></i>
                            <span>Profit Margin</span>
                        </div>
                        <div class="stat-value-modern" style="color: #10b981; font-size: 20px;"><?= number_format($profitMargin, 2) ?>%</div>
                    </div>
                    <div class="stat-icon-modern success">
                        <i class="bi bi-percent"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Transactions & Customers -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="section-header-modern">
                <i class="bi bi-people"></i>
                <span>Transactions & Customers</span>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="stat-card-modern primary">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-person"></i>
                            <span>Unique Customers</span>
                        </div>
                        <div class="stat-value-modern" style="color: #1e3a8a;"><?= number_format($receiptsStats['unique_customers']) ?></div>
                    </div>
                    <div class="stat-icon-modern primary">
                        <i class="bi bi-person"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="stat-card-modern info">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-calculator"></i>
                            <span>Avg Receipt Value</span>
                        </div>
                        <div class="stat-value-modern" style="color: #06b6d4; font-size: 20px;"><?= formatCurrency($receiptsStats['avg_receipt_value']) ?></div>
                    </div>
                    <div class="stat-icon-modern info">
                        <i class="bi bi-calculator"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="stat-card-modern warning">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span>Refund Transactions</span>
                        </div>
                        <div class="stat-value-modern" style="color: #f59e0b;"><?= number_format($refundsStats['total_refunds']) ?></div>
                    </div>
                    <div class="stat-icon-modern warning">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Products & Inventory -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="section-header-modern">
                <i class="bi bi-box-seam"></i>
                <span>Products & Inventory</span>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="stat-card-modern success">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-box"></i>
                            <span>Products Sold</span>
                        </div>
                        <div class="stat-value-modern" style="color: #10b981;"><?= number_format($productsStats['unique_products_sold']) ?></div>
                    </div>
                    <div class="stat-icon-modern success">
                        <i class="bi bi-box"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="stat-card-modern info">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-cart"></i>
                            <span>Units Sold</span>
                        </div>
                        <div class="stat-value-modern" style="color: #06b6d4;"><?= number_format($productsStats['total_units_sold']) ?></div>
                    </div>
                    <div class="stat-icon-modern info">
                        <i class="bi bi-cart"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="stat-card-modern secondary">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-tag"></i>
                            <span>Product Cost</span>
                        </div>
                        <div class="stat-value-modern" style="color: #6b7280;"><?= formatCurrency($productCost['total_cost']) ?></div>
                    </div>
                    <div class="stat-icon-modern secondary">
                        <i class="bi bi-tag"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Shifts & Cash -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="section-header-modern">
                <i class="bi bi-clock-history"></i>
                <span>Shifts & Cash</span>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern primary">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-clock"></i>
                            <span>Total Shifts</span>
                        </div>
                        <div class="stat-value-modern" style="color: #1e3a8a;"><?= number_format($shiftsStats['total_shifts']) ?></div>
                    </div>
                    <div class="stat-icon-modern primary">
                        <i class="bi bi-clock"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern warning">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-clock-history"></i>
                            <span>Open Shifts</span>
                        </div>
                        <div class="stat-value-modern" style="color: #f59e0b;"><?= number_format($shiftsStats['open_shifts']) ?></div>
                    </div>
                    <div class="stat-icon-modern warning">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern success">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-cash"></i>
                            <span>Expected Cash</span>
                        </div>
                        <div class="stat-value-modern" style="color: #10b981; font-size: 20px;"><?= formatCurrency($shiftsStats['total_expected_cash']) ?></div>
                    </div>
                    <div class="stat-icon-modern success">
                        <i class="bi bi-cash"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern <?= $cashDifference >= 0 ? 'success' : 'danger' ?>">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-<?= $cashDifference >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                            <span>Cash Difference</span>
                        </div>
                        <div class="stat-value-modern" style="color: <?= $cashDifference >= 0 ? '#10b981' : '#ef4444' ?>; font-size: 20px;"><?= formatCurrency($cashDifference) ?></div>
                    </div>
                    <div class="stat-icon-modern <?= $cashDifference >= 0 ? 'success' : 'danger' ?>">
                        <i class="bi bi-<?= $cashDifference >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Categories & Products -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="table-card-modern">
                <div class="table-card-header-modern">
                    <h5 class="table-title-modern">
                        <i class="bi bi-tags"></i>
                        <span>Top Categories by Sales</span>
                    </h5>
                </div>
                <?php if (empty($categoryStats)): ?>
                    <p class="text-muted mb-0" style="font-size: 11px;">No category data available</p>
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
        
        <div class="col-lg-6">
            <div class="table-card-modern">
                <div class="table-card-header-modern">
                    <h5 class="table-title-modern">
                        <i class="bi bi-star"></i>
                        <span>Top Products by Sales</span>
                    </h5>
                </div>
                <?php if (empty($topProducts)): ?>
                    <p class="text-muted mb-0" style="font-size: 11px;">No product data available</p>
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

    <!-- Payment Methods & Staff Performance -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="table-card-modern">
                <div class="table-card-header-modern">
                    <h5 class="table-title-modern">
                        <i class="bi bi-credit-card"></i>
                        <span>Payment Methods</span>
                    </h5>
                </div>
                <?php if (empty($paymentStats)): ?>
                    <p class="text-muted mb-0" style="font-size: 11px;">No payment data available</p>
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
        
        <div class="col-lg-6">
            <div class="table-card-modern">
                <div class="table-card-header-modern">
                    <h5 class="table-title-modern">
                        <i class="bi bi-person-badge"></i>
                        <span>Top Staff by Sales</span>
                    </h5>
                </div>
                <?php if (empty($staffStats)): ?>
                    <p class="text-muted mb-0" style="font-size: 11px;">No staff data available</p>
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

    <!-- Suspicious Activity -->
    <?php if ($deletedReceiptsStats['total_deleted'] > 0): ?>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="table-card-modern" style="border-left: 4px solid #ef4444;">
                <div class="table-card-header-modern">
                    <h5 class="table-title-modern" style="color: #ef4444;">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span>Suspicious Activity</span>
                    </h5>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="stat-label-modern">Deleted Receipts</div>
                        <div class="stat-value-modern" style="color: #ef4444;"><?= number_format($deletedReceiptsStats['total_deleted']) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
