<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('dashboard.view');

$pageTitle = 'Dashboard';

$db = Database::getInstance();
// Check if branch_id is explicitly set in GET (even if empty for "All shops")
if (isset($_GET['branch_id'])) {
    $branchId = $_GET['branch_id'] === '' ? null : $_GET['branch_id'];
} else {
    $branchId = $_SESSION['branch_id'] ?? null;
}
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Get branches for filter
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");

// Build WHERE clause
$whereClause = "DATE(sale_date) BETWEEN :start_date AND :end_date";
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($branchId) {
    $whereClause .= " AND branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

// Ensure we have valid parameters
if (empty($params)) {
    $params = [];
}

// Get sales data
$salesData = $db->getRow("SELECT 
    COALESCE(SUM(total_amount), 0) as gross_sales,
    COALESCE(SUM(discount_amount), 0) as total_discount,
    COALESCE(SUM(subtotal), 0) as net_sales
    FROM sales WHERE $whereClause", $params) ?: ['gross_sales' => 0, 'total_discount' => 0, 'net_sales' => 0];

// Get cost of sales - fix the WHERE clause to reference sales table
$costWhereClause = "DATE(s.sale_date) BETWEEN :start_date AND :end_date";
$costParams = [':start_date' => $startDate, ':end_date' => $endDate];
if ($branchId) {
    $costWhereClause .= " AND s.branch_id = :branch_id";
    $costParams[':branch_id'] = $branchId;
}
$costData = $db->getRow("SELECT 
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as cost_of_sales
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    LEFT JOIN products p ON si.product_id = p.id
    WHERE $costWhereClause", $costParams) ?: ['cost_of_sales' => 0];

$grossSales = floatval($salesData['gross_sales'] ?? 0);
$netSales = floatval($salesData['net_sales'] ?? 0);
$costOfSales = floatval($costData['cost_of_sales'] ?? 0);
$grossProfit = $netSales - $costOfSales;
$discount = floatval($salesData['total_discount'] ?? 0);

// Additional comprehensive stats
// Total customers
// Customers table doesn't have branch_id column, so just count all
$totalCustomers = $db->getRow("SELECT COUNT(*) as count FROM customers", []) ?: ['count' => 0];

// Total products
$totalProducts = $db->getRow("SELECT COUNT(*) as count FROM products WHERE status = 'Active'" . ($branchId ? " AND branch_id = :branch_id" : ""), 
    $branchId ? [':branch_id' => $branchId] : []) ?: ['count' => 0];

// Total invoices
$invoiceWhereClause = "DATE(invoice_date) BETWEEN :start_date AND :end_date";
$invoiceParams = [':start_date' => $startDate, ':end_date' => $endDate];
if ($branchId) {
    $invoiceWhereClause .= " AND branch_id = :branch_id";
    $invoiceParams[':branch_id'] = $branchId;
}
$totalInvoices = $db->getRow("SELECT COUNT(*) as count FROM invoices WHERE $invoiceWhereClause", $invoiceParams) ?: ['count' => 0];

// Total sales count
$totalSalesCount = $db->getRow("SELECT COUNT(*) as count FROM sales WHERE $whereClause", $params) ?: ['count' => 0];

// Average transaction value
$avgTransaction = $totalSalesCount['count'] > 0 ? ($grossSales / $totalSalesCount['count']) : 0;

// Low stock products count (for stat card)
$lowStockCount = $db->getRow("SELECT COUNT(*) as count FROM products WHERE quantity_in_stock <= reorder_level AND status = 'Active'" . ($branchId ? " AND branch_id = :branch_id" : ""), 
    $branchId ? [':branch_id' => $branchId] : []) ?: ['count' => 0];

// Total inventory value
$inventoryValue = $db->getRow("SELECT COALESCE(SUM(quantity_in_stock * cost_price), 0) as value FROM products WHERE status = 'Active'" . ($branchId ? " AND branch_id = :branch_id" : ""), 
    $branchId ? [':branch_id' => $branchId] : []) ?: ['value' => 0];

// Credit Sales (if enabled)
$creditSalesStats = ['total_credit_sales' => 0, 'total_outstanding' => 0, 'outstanding_count' => 0];
if (getSetting('allow_credit_sales', '0') == '1') {
    $creditSalesWhere = "DATE(sale_date) BETWEEN :start_date AND :end_date AND is_credit_sale = 1";
    $creditSalesParams = [':start_date' => $startDate, ':end_date' => $endDate];
    if ($branchId) {
        $creditSalesWhere .= " AND branch_id = :branch_id";
        $creditSalesParams[':branch_id'] = $branchId;
    }
    
    $creditSales = $db->getRow("SELECT 
        COUNT(*) as total_credit_sales,
        COUNT(CASE WHEN account_balance > 0 THEN 1 END) as outstanding_count,
        COALESCE(SUM(account_balance), 0) as total_outstanding
        FROM sales
        WHERE $creditSalesWhere", $creditSalesParams);
    if ($creditSales !== false) {
        $creditSalesStats = $creditSales;
    }
}

// Get cash refunds
$refundData = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total_refunds 
    FROM drawer_transactions 
    WHERE transaction_type = 'pay_out' 
    AND DATE(created_at) BETWEEN :start_date AND :end_date" . ($branchId ? " AND EXISTS (SELECT 1 FROM shifts WHERE id = drawer_transactions.shift_id AND branch_id = :branch_id)" : ""), 
    $params) ?: ['total_refunds' => 0];
$cashRefund = floatval($refundData['total_refunds'] ?? 0);

// Get hourly sales for selected date range
$hourlySales = [];
$hourlyDeductions = [];
if ($startDate === $endDate) {
    $hourlyData = $db->getRows("SELECT 
        HOUR(sale_date) as hour,
        COALESCE(SUM(total_amount), 0) as gross_sales,
        COALESCE(SUM(subtotal - discount_amount), 0) as net_sales,
        COALESCE(SUM(discount_amount), 0) as discount_amount,
        COALESCE(SUM((SELECT SUM(si2.quantity * p2.cost_price) FROM sale_items si2 INNER JOIN products p2 ON si2.product_id = p2.id WHERE si2.sale_id = s.id)), 0) as cost_of_sales
        FROM sales s
        WHERE DATE(sale_date) BETWEEN :start_date AND :end_date" . ($branchId ? " AND branch_id = :branch_id" : "") . "
        GROUP BY HOUR(sale_date)
        ORDER BY hour", 
        array_merge([':start_date' => $startDate, ':end_date' => $endDate], $branchId ? [':branch_id' => $branchId] : [])) ?: [];
    
    // Get hourly refunds
    $hourlyRefundData = $db->getRows("SELECT 
        HOUR(dt.created_at) as hour,
        COALESCE(SUM(dt.amount), 0) as refund_amount
        FROM drawer_transactions dt
        WHERE dt.transaction_type = 'pay_out'
        AND DATE(dt.created_at) BETWEEN :start_date AND :end_date" . ($branchId ? " AND EXISTS (SELECT 1 FROM shifts WHERE id = dt.shift_id AND branch_id = :branch_id)" : "") . "
        GROUP BY HOUR(dt.created_at)
        ORDER BY hour", 
        array_merge([':start_date' => $startDate, ':end_date' => $endDate], $branchId ? [':branch_id' => $branchId] : [])) ?: [];
    
    // Initialize arrays for all 24 hours
    for ($i = 0; $i < 24; $i++) {
        $hourlySales[$i] = [
            'gross_sales' => 0,
            'net_sales' => 0,
            'cost_of_sales' => 0,
            'gross_profit' => 0
        ];
        $hourlyDeductions[$i] = [
            'discount' => 0,
            'refund' => 0,
            'total' => 0
        ];
    }
    
    // Populate hourly sales data
    if (is_array($hourlyData)) {
        foreach ($hourlyData as $row) {
            if (is_array($row) && isset($row['hour'])) {
                $hour = intval($row['hour']);
                $hourlySales[$hour] = [
                    'gross_sales' => floatval($row['gross_sales'] ?? 0),
                    'net_sales' => floatval($row['net_sales'] ?? 0),
                    'cost_of_sales' => floatval($row['cost_of_sales'] ?? 0),
                    'gross_profit' => floatval($row['net_sales'] ?? 0) - floatval($row['cost_of_sales'] ?? 0)
                ];
                $hourlyDeductions[$hour]['discount'] = floatval($row['discount_amount'] ?? 0);
            }
        }
    }
    
    // Populate hourly refund data
    if (is_array($hourlyRefundData)) {
        foreach ($hourlyRefundData as $row) {
            if (is_array($row) && isset($row['hour'])) {
                $hour = intval($row['hour']);
                $hourlyDeductions[$hour]['refund'] = floatval($row['refund_amount'] ?? 0);
            }
        }
    }
    
    // Calculate total deductions for each hour
    foreach ($hourlyDeductions as $hour => $data) {
        $hourlyDeductions[$hour]['total'] = $data['discount'] + $data['refund'];
    }
}

// Get sales trend for last 30 days
$trendData = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $daySales = $db->getRow("SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM sales 
        WHERE DATE(sale_date) = :date" . ($branchId ? " AND branch_id = :branch_id" : ""), 
        array_merge([':date' => $date], $branchId ? [':branch_id' => $branchId] : [])) ?: ['total' => 0];
    $trendData[] = [
        'date' => date('M d', strtotime($date)),
        'sales' => floatval($daySales['total'] ?? 0)
    ];
}

require_once APP_PATH . '/includes/settings_functions.php';
require_once APP_PATH . '/includes/header.php';

// Check for low stock at login (if enabled) - Only for Administrators
$checkLowStockAtLogin = getSetting('check_low_stock_at_login', '0') == '1';
$isAdministrator = false;
if (isset($_SESSION['role_name'])) {
    $roleName = strtolower($_SESSION['role_name']);
    $isAdministrator = ($roleName === 'system administrator' || $roleName === 'administrator');
}

$lowStockProducts = [];
if ($checkLowStockAtLogin && $isAdministrator && !isset($_SESSION['low_stock_checked'])) {
    // Get low stock products
    $lowStockQuery = "SELECT p.*, 
                     COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as display_name,
                     pc.name as category_name,
                     b.branch_name
                     FROM products p
                     LEFT JOIN product_categories pc ON p.category_id = pc.id
                     LEFT JOIN branches b ON p.branch_id = b.id
                     WHERE p.status = 'Active' 
                     AND p.quantity_in_stock <= p.reorder_level
                     AND p.reorder_level > 0";
    
    $lowStockParams = [];
    if ($branchId) {
        $lowStockQuery .= " AND p.branch_id = :branch_id";
        $lowStockParams[':branch_id'] = $branchId;
    }
    
    $lowStockQuery .= " ORDER BY p.quantity_in_stock ASC LIMIT 20";
    
    $lowStockProducts = $db->getRows($lowStockQuery, $lowStockParams);
    if ($lowStockProducts === false) {
        $lowStockProducts = [];
    }
    
    // Mark as checked for this session
    $_SESSION['low_stock_checked'] = true;
}
?>

<style>
/* Modern Dashboard Styles */
.dashboard-container {
    padding: 0;
    background: var(--light-gray);
    min-height: 100vh;
}

/* Filter Section - Modern Design */
.dashboard-filters {
    background: var(--white);
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    border: 1px solid var(--border-color);
}

.filter-row {
    display: flex;
    align-items: flex-end;
    gap: 20px;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1;
    min-width: 200px;
}

.filter-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.filter-label i {
    font-size: 13px;
    color: var(--secondary-blue);
}

.filter-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.filter-input-wrapper .form-select,
.filter-input-wrapper .form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    height: 42px;
    transition: all 0.2s ease;
    background: var(--white);
}

.filter-input-wrapper .form-select:focus,
.filter-input-wrapper .form-control:focus {
    border-color: var(--secondary-blue);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    outline: none;
}

.filter-btn-wrapper {
    display: flex;
    align-items: flex-end;
    margin-top: 24px;
}

.filter-btn {
    height: 42px;
    padding: 0 28px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    border: none;
    white-space: nowrap;
}

.filter-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
}

/* Modern Stat Cards */
.stat-card-modern {
    background: var(--white);
    border-radius: 16px;
    padding: 24px;
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

.stat-card-modern.gross-sales::before { background: #3b82f6; }
.stat-card-modern.net-sales::before { background: #10b981; }
.stat-card-modern.cost-sales::before { background: #ef4444; }
.stat-card-modern.gross-profit::before { background: #f59e0b; }
.stat-card-modern.total-sales::before { background: #8b5cf6; }
.stat-card-modern.avg-transaction::before { background: #06b6d4; }
.stat-card-modern.total-customers::before { background: #ec4899; }
.stat-card-modern.total-products::before { background: #14b8a6; }
.stat-card-modern.total-invoices::before { background: #6366f1; }
.stat-card-modern.low-stock::before { background: #f97316; }
.stat-card-modern.inventory-value::before { background: #0ea5e9; }
.stat-card-modern.discount::before { background: #a855f7; }
.stat-card-modern.cash-refund::before { background: #f43f5e; }

.stat-card-header-modern {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 16px;
}

.stat-icon-modern {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
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

.stat-icon-modern.gross-sales { background: #3b82f6; }
.stat-icon-modern.net-sales { background: #10b981; }
.stat-icon-modern.cost-sales { background: #ef4444; }
.stat-icon-modern.gross-profit { background: #f59e0b; }
.stat-icon-modern.total-sales { background: #8b5cf6; }
.stat-icon-modern.avg-transaction { background: #06b6d4; }
.stat-icon-modern.total-customers { background: #ec4899; }
.stat-icon-modern.total-products { background: #14b8a6; }
.stat-icon-modern.total-invoices { background: #6366f1; }
.stat-icon-modern.low-stock { background: #f97316; }
.stat-icon-modern.inventory-value { background: #0ea5e9; }
.stat-icon-modern.discount { background: #a855f7; }
.stat-icon-modern.cash-refund { background: #f43f5e; }

.stat-content-modern {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.stat-label-modern {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    font-weight: 600;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.stat-label-modern i {
    font-size: 12px;
}

.stat-value-modern {
    font-size: 28px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
    line-height: 1.2;
    animation: countUp 0.8s ease-out;
}

.stat-trend-modern {
    margin-top: 12px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--text-muted);
}

.stat-trend-modern i {
    font-size: 14px;
}

.mini-chart-modern {
    height: 60px;
    margin-top: 16px;
    position: relative;
}

/* Chart Cards */
.chart-card-modern {
    background: var(--white);
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
    border: 1px solid var(--border-color);
    height: 100%;
    animation: fadeInUp 0.6s ease-out;
}

.chart-card-header-modern {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-color);
}

.chart-title-modern {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-title-modern i {
    font-size: 20px;
    color: var(--secondary-blue);
}

.chart-container-modern {
    position: relative;
    height: 400px;
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
        font-size: 24px;
    }
    
    .stat-icon-modern {
        width: 48px;
        height: 48px;
        font-size: 20px;
    }
    
    .chart-container-modern {
        height: 300px;
    }
}

@media (max-width: 768px) {
    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        width: 100%;
        min-width: 100%;
    }
    
    .filter-btn-wrapper {
        margin-top: 0;
        width: 100%;
    }
    
    .filter-btn {
        width: 100%;
        justify-content: center;
    }
    
    .stat-value-modern {
        font-size: 20px;
    }
    
    .stat-icon-modern {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
    
    .chart-container-modern {
        height: 250px;
    }
}
</style>

<div class="dashboard-container">
    <!-- Modern Filter Section -->
    <div class="dashboard-filters">
        <div class="filter-row">
            <div class="filter-group">
                <label class="filter-label">
                    <i class="bi bi-building"></i>
                    <span>Branch</span>
                </label>
                <div class="filter-input-wrapper">
                    <select class="form-select" id="branchFilter">
                        <option value="" <?= !isset($_GET['branch_id']) || $_GET['branch_id'] === '' ? 'selected' : '' ?>>All Shops</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $branchId == $branch['id'] ? 'selected' : '' ?>>
                                <?= escapeHtml($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">
                    <i class="bi bi-calendar3"></i>
                    <span>Start Date</span>
                </label>
                <div class="filter-input-wrapper">
                    <input type="date" class="form-control" id="startDate" value="<?= $startDate ?>">
                </div>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">
                    <i class="bi bi-calendar-check"></i>
                    <span>End Date</span>
                </label>
                <div class="filter-input-wrapper">
                    <input type="date" class="form-control" id="endDate" value="<?= $endDate ?>">
                </div>
            </div>
            
            <div class="filter-btn-wrapper">
                <button class="btn btn-primary filter-btn" onclick="applyFilters()">
                    <i class="bi bi-funnel"></i>
                    <span>Apply Filters</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Primary Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern gross-sales">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-cash-stack"></i>
                            <span>Gross Sales</span>
                        </div>
                        <div class="stat-value-modern"><?= formatCurrency($grossSales) ?></div>
                    </div>
                    <div class="stat-icon-modern gross-sales">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
                <canvas class="mini-chart-modern" id="grossSalesChart"></canvas>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern net-sales">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-graph-up-arrow"></i>
                            <span>Net Sales</span>
                        </div>
                        <div class="stat-value-modern"><?= formatCurrency($netSales) ?></div>
                    </div>
                    <div class="stat-icon-modern net-sales">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
                <canvas class="mini-chart-modern" id="netSalesChart"></canvas>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern cost-sales">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-cart-dash"></i>
                            <span>Cost of Sales</span>
                        </div>
                        <div class="stat-value-modern"><?= formatCurrency($costOfSales) ?></div>
                    </div>
                    <div class="stat-icon-modern cost-sales">
                        <i class="bi bi-cart-dash"></i>
                    </div>
                </div>
                <canvas class="mini-chart-modern" id="costSalesChart"></canvas>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern gross-profit">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-trophy"></i>
                            <span>Gross Profit</span>
                        </div>
                        <div class="stat-value-modern"><?= formatCurrency($grossProfit) ?></div>
                    </div>
                    <div class="stat-icon-modern gross-profit">
                        <i class="bi bi-trophy"></i>
                    </div>
                </div>
                <canvas class="mini-chart-modern" id="grossProfitChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Secondary Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern total-sales">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-receipt-cutoff"></i>
                            <span>Total Sales</span>
                        </div>
                        <div class="stat-value-modern"><?= number_format($totalSalesCount['count']) ?></div>
                    </div>
                    <div class="stat-icon-modern total-sales">
                        <i class="bi bi-receipt-cutoff"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern avg-transaction">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-calculator"></i>
                            <span>Avg Transaction</span>
                        </div>
                        <div class="stat-value-modern" style="font-size: 22px;"><?= formatCurrency($avgTransaction) ?></div>
                    </div>
                    <div class="stat-icon-modern avg-transaction">
                        <i class="bi bi-calculator"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern total-customers">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-people"></i>
                            <span>Total Customers</span>
                        </div>
                        <div class="stat-value-modern"><?= number_format($totalCustomers['count']) ?></div>
                    </div>
                    <div class="stat-icon-modern total-customers">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern total-products">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-box-seam"></i>
                            <span>Total Products</span>
                        </div>
                        <div class="stat-value-modern"><?= number_format($totalProducts['count']) ?></div>
                    </div>
                    <div class="stat-icon-modern total-products">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tertiary Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern total-invoices">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-file-earmark-text"></i>
                            <span>Total Invoices</span>
                        </div>
                        <div class="stat-value-modern"><?= number_format($totalInvoices['count']) ?></div>
                    </div>
                    <div class="stat-icon-modern total-invoices">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern low-stock">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span>Low Stock</span>
                        </div>
                        <div class="stat-value-modern" style="color: <?= $lowStockCount['count'] > 0 ? '#ef4444' : 'var(--text-dark)' ?>;">
                            <?= number_format($lowStockCount['count']) ?>
                        </div>
                    </div>
                    <div class="stat-icon-modern low-stock">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern inventory-value">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-archive"></i>
                            <span>Inventory Value</span>
                        </div>
                        <div class="stat-value-modern" style="font-size: 22px;"><?= formatCurrency($inventoryValue['value']) ?></div>
                    </div>
                    <div class="stat-icon-modern inventory-value">
                        <i class="bi bi-archive"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($creditSalesStats['total_outstanding'] > 0): ?>
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern" style="border-left-color: #ec4899;">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-credit-card"></i>
                            <span>Outstanding Credit</span>
                        </div>
                        <div class="stat-value-modern" style="font-size: 22px;"><?= formatCurrency($creditSalesStats['total_outstanding']) ?></div>
                    </div>
                    <div class="stat-icon-modern" style="background: #ec4899;">
                        <i class="bi bi-credit-card"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sales Summary - Only show hourly charts for single day view -->
    <?php if ($startDate === $endDate): ?>
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="chart-card-modern">
                <div class="chart-card-header-modern">
                    <h5 class="chart-title-modern">
                        <i class="bi bi-bar-chart-fill"></i>
                        <span><?= $startDate === date('Y-m-d') ? 'Today Sales Summary' : 'Sales Summary' ?></span>
                    </h5>
                </div>
                <div class="chart-container-modern">
                    <canvas id="todaySalesChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="row g-3">
                <div class="col-12">
                    <div class="stat-card-modern discount">
                        <div class="stat-card-header-modern">
                            <div class="stat-content-modern">
                                <div class="stat-label-modern">
                                    <i class="bi bi-tag"></i>
                                    <span>Discount</span>
                                </div>
                                <div class="stat-value-modern" style="font-size: 24px;"><?= formatCurrency($discount) ?></div>
                            </div>
                            <div class="stat-icon-modern discount">
                                <i class="bi bi-tag"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="stat-card-modern cash-refund">
                        <div class="stat-card-header-modern">
                            <div class="stat-content-modern">
                                <div class="stat-label-modern">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                    <span>Cash Refund</span>
                                </div>
                                <div class="stat-value-modern" style="font-size: 24px;"><?= formatCurrency($cashRefund) ?></div>
                            </div>
                            <div class="stat-icon-modern cash-refund">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="chart-card-modern mt-3">
                <div class="chart-card-header-modern">
                    <h6 class="chart-title-modern" style="font-size: 14px;">
                        <i class="bi bi-pie-chart-fill"></i>
                        <span><?= $startDate === date('Y-m-d') ? 'Today Deductions' : 'Deductions' ?></span>
                    </h6>
                </div>
                <div style="height: 200px;">
                    <canvas id="deductionChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Today Sales Deduction Chart (Full Width) - Only show for single day view -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="chart-card-modern">
                <div class="chart-card-header-modern">
                    <h5 class="chart-title-modern">
                        <i class="bi bi-graph-down-arrow"></i>
                        <span><?= $startDate === date('Y-m-d') ? 'Today Sales Deduction Breakdown' : 'Sales Deduction Breakdown' ?></span>
                    </h5>
                </div>
                <div class="chart-container-modern">
                    <canvas id="todayDeductionChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sales Trend -->
    <div class="row g-3">
        <div class="col-12">
            <div class="chart-card-modern">
                <div class="chart-card-header-modern">
                    <h5 class="chart-title-modern">
                        <i class="bi bi-graph-up"></i>
                        <span>Sales Trend (Last 30 Days)</span>
                    </h5>
                </div>
                <div class="chart-container-modern">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function applyFilters() {
    const branch = document.getElementById('branchFilter').value;
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    let url = '<?= BASE_URL ?>modules/dashboard/index.php';
    const params = [];
    // Always include branch_id, even if empty (for "All shops")
    params.push('branch_id=' + encodeURIComponent(branch || ''));
    if (startDate) params.push('start_date=' + encodeURIComponent(startDate));
    if (endDate) params.push('end_date=' + encodeURIComponent(endDate));
    if (params.length > 0) {
        url += '?' + params.join('&');
    }
    window.location.href = url;
}

// Detect screen size for responsive chart options
const isSmallScreen = window.innerWidth <= 1366;
const isTablet = window.innerWidth <= 1023;
const isMobile = window.innerWidth <= 768;
const isSmallMobile = window.innerWidth <= 480;

// Mini charts for stat cards
const miniChartOptions = {
    responsive: true,
    maintainAspectRatio: true,
    aspectRatio: 4,
    plugins: { 
        legend: { display: false },
        tooltip: {
            bodyFont: {
                size: isSmallMobile ? 10 : isMobile ? 11 : isTablet ? 11 : 12
            },
            titleFont: {
                size: isSmallMobile ? 11 : isMobile ? 12 : isTablet ? 12 : 13
            }
        }
    },
    scales: { 
        x: { display: false }, 
        y: { display: false } 
    },
    elements: { point: { radius: 0 } },
    layout: { padding: 0 }
};

new Chart(document.getElementById('grossSalesChart'), {
    type: 'line',
    data: {
        labels: Array(12).fill(''),
        datasets: [{
            data: <?= json_encode(array_fill(0, 12, $grossSales / 12)) ?>,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.4,
            borderWidth: 2
        }]
    },
    options: miniChartOptions
});

new Chart(document.getElementById('netSalesChart'), {
    type: 'line',
    data: {
        labels: Array(12).fill(''),
        datasets: [{
            data: <?= json_encode(array_fill(0, 12, $netSales / 12)) ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            borderWidth: 2
        }]
    },
    options: miniChartOptions
});

new Chart(document.getElementById('costSalesChart'), {
    type: 'line',
    data: {
        labels: Array(12).fill(''),
        datasets: [{
            data: <?= json_encode(array_fill(0, 12, $costOfSales / 12)) ?>,
            borderColor: '#ef4444',
            backgroundColor: 'rgba(239, 68, 68, 0.1)',
            tension: 0.4,
            borderWidth: 2
        }]
    },
    options: miniChartOptions
});

new Chart(document.getElementById('grossProfitChart'), {
    type: 'line',
    data: {
        labels: Array(12).fill(''),
        datasets: [{
            data: <?= json_encode(array_fill(0, 12, $grossProfit / 12)) ?>,
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245, 158, 11, 0.1)',
            tension: 0.4,
            borderWidth: 2
        }]
    },
    options: miniChartOptions
});

<?php if ($startDate === $endDate): ?>
// Sales Summary Chart - Only show for single day view
const todayCtx = document.getElementById('todaySalesChart').getContext('2d');
new Chart(todayCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(function($i) { return date('g:i A', mktime($i, 0)); }, range(0, 23))) ?>,
        datasets: [
            {
                label: 'Gross Sales',
                data: <?= json_encode(array_column($hourlySales, 'gross_sales')) ?>,
                backgroundColor: 'rgba(59, 130, 246, 0.8)',
                borderColor: '#3b82f6',
                borderWidth: 1
            },
            {
                label: 'Net Sales',
                data: <?= json_encode(array_column($hourlySales, 'net_sales')) ?>,
                type: 'line',
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.4,
                borderWidth: 2
            },
            {
                label: 'Cost Of Sales',
                data: <?= json_encode(array_column($hourlySales, 'cost_of_sales')) ?>,
                type: 'line',
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                tension: 0.4,
                borderWidth: 2
            },
            {
                label: 'Gross Profit',
                data: <?= json_encode(array_column($hourlySales, 'gross_profit')) ?>,
                type: 'line',
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                tension: 0.4,
                borderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    font: {
                        size: isSmallMobile ? 10 : isMobile ? 11 : isTablet ? 11 : 12
                    },
                    padding: isSmallMobile ? 8 : isMobile ? 10 : isTablet ? 12 : 15
                }
            },
            tooltip: {
                bodyFont: {
                    size: isSmallMobile ? 10 : isMobile ? 11 : isTablet ? 11 : 12
                },
                titleFont: {
                    size: isSmallMobile ? 11 : isMobile ? 12 : isTablet ? 12 : 13
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    font: {
                        size: isSmallMobile ? 9 : isMobile ? 10 : isTablet ? 10 : 11
                    },
                    maxRotation: isMobile ? 90 : 45,
                    minRotation: isMobile ? 90 : 45
                }
            },
            y: { 
                beginAtZero: true,
                ticks: {
                    font: {
                        size: isSmallMobile ? 9 : isMobile ? 10 : isTablet ? 10 : 11
                    }
                }
            }
        }
    }
});

// Deduction Chart (small one in sidebar) - Only show cash refunds, not discounts
new Chart(document.getElementById('deductionChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(function($i) { return date('g:i A', mktime($i, 0)); }, range(0, 23))) ?>,
        datasets: [{
            label: 'Cash Refunds',
            data: <?= json_encode(array_column($hourlyDeductions, 'refund')) ?>,
            backgroundColor: 'rgba(244, 63, 94, 0.6)'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// Sales Deduction Chart (Full Width) - Only show for single day view
const deductionCtx = document.getElementById('todayDeductionChart').getContext('2d');
new Chart(deductionCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(function($i) { return date('g:i A', mktime($i, 0)); }, range(0, 23))) ?>,
        datasets: [
            {
                label: 'Discounts',
                data: <?= json_encode(array_column($hourlyDeductions, 'discount')) ?>,
                backgroundColor: 'rgba(168, 85, 247, 0.8)',
                borderColor: '#a855f7',
                borderWidth: 1
            },
            {
                label: 'Cash Refunds',
                data: <?= json_encode(array_column($hourlyDeductions, 'refund')) ?>,
                backgroundColor: 'rgba(244, 63, 94, 0.8)',
                borderColor: '#f43f5e',
                borderWidth: 1
            },
            {
                label: 'Total Deductions',
                data: <?= json_encode(array_column($hourlyDeductions, 'total')) ?>,
                type: 'line',
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: false,
                pointRadius: 4,
                pointHoverRadius: 6
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: {
                    usePointStyle: true,
                    padding: isSmallMobile ? 8 : isMobile ? 10 : isTablet ? 12 : 15,
                    font: {
                        size: isSmallMobile ? 10 : isMobile ? 11 : isTablet ? 11 : 12,
                        weight: '500'
                    }
                }
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                bodyFont: {
                    size: isSmallMobile ? 10 : isMobile ? 11 : isTablet ? 11 : 12
                },
                titleFont: {
                    size: isSmallMobile ? 11 : isMobile ? 12 : isTablet ? 12 : 13
                },
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed.y !== null) {
                            label += new Intl.NumberFormat('en-US', {
                                style: 'currency',
                                currency: 'USD'
                            }).format(context.parsed.y);
                        }
                        return label;
                    }
                }
            }
        },
        scales: {
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    maxRotation: isMobile ? 90 : 45,
                    minRotation: isMobile ? 90 : 45,
                    font: {
                        size: isSmallMobile ? 8 : isMobile ? 9 : isTablet ? 9 : 10
                    }
                }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return new Intl.NumberFormat('en-US', {
                            style: 'currency',
                            currency: 'USD',
                            minimumFractionDigits: 0,
                            maximumFractionDigits: 0
                        }).format(value);
                    },
                    font: {
                        size: isSmallMobile ? 8 : isMobile ? 9 : isTablet ? 9 : 11
                    }
                },
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                }
            }
        },
        interaction: {
            mode: 'index',
            intersect: false
        }
    }
});
<?php endif; ?>

// Sales Trend Chart
const trendCtx = document.getElementById('salesTrendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($trendData, 'date')) ?>,
        datasets: [{
            label: 'Sales',
            data: <?= json_encode(array_column($trendData, 'sales')) ?>,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.4,
            fill: true,
            borderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 5
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { 
                display: true,
                labels: {
                    font: {
                        size: isSmallMobile ? 10 : isMobile ? 11 : isTablet ? 11 : 12
                    },
                    padding: isSmallMobile ? 8 : isMobile ? 10 : isTablet ? 12 : 15
                }
            },
            tooltip: {
                bodyFont: {
                    size: isSmallMobile ? 10 : isMobile ? 11 : isTablet ? 11 : 12
                },
                titleFont: {
                    size: isSmallMobile ? 11 : isMobile ? 12 : isTablet ? 12 : 13
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    font: {
                        size: isSmallMobile ? 8 : isMobile ? 9 : isTablet ? 9 : 10
                    },
                    maxRotation: isMobile ? 90 : 45,
                    minRotation: isMobile ? 90 : 45
                }
            },
            y: { 
                beginAtZero: true,
                ticks: {
                    font: {
                        size: isSmallMobile ? 8 : isMobile ? 9 : isTablet ? 9 : 11
                    }
                }
            }
        }
    }
});
</script>

<?php if ($checkLowStockAtLogin && $isAdministrator && !empty($lowStockProducts)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const lowStockCount = <?= count($lowStockProducts) ?>;
    const lowStockProducts = <?= json_encode($lowStockProducts) ?>;
    
    let productList = '<div style="max-height: 400px; overflow-y: auto; text-align: left;">';
    productList += '<table class="table table-sm table-bordered">';
    productList += '<thead><tr><th>Product</th><th>Code</th><th>Stock</th><th>Reorder Level</th><th>Branch</th></tr></thead>';
    productList += '<tbody>';
    
    lowStockProducts.forEach(function(product) {
        productList += '<tr>';
        productList += '<td>' + escapeHtml(product.display_name || 'N/A') + '</td>';
        productList += '<td>' + escapeHtml(product.product_code || 'N/A') + '</td>';
        productList += '<td class="text-danger fw-bold">' + product.quantity_in_stock + '</td>';
        productList += '<td>' + product.reorder_level + '</td>';
        productList += '<td>' + escapeHtml(product.branch_name || 'N/A') + '</td>';
        productList += '</tr>';
    });
    
    productList += '</tbody></table>';
    productList += '</div>';
    
    Swal.fire({
        title: 'Low Stock Alert',
        html: '<p class="mb-3">The following <strong>' + lowStockCount + '</strong> product(s) are at or below their reorder levels:</p>' + productList,
        icon: 'warning',
        confirmButtonText: 'View Inventory',
        showCancelButton: true,
        cancelButtonText: 'Dismiss',
        width: '800px',
        customClass: {
            popup: 'text-start'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = '<?= BASE_URL ?>modules/inventory/index.php';
        }
    });
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
<?php endif; ?>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
