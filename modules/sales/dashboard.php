<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('sales.view');

$pageTitle = 'Sales Dashboard';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Date filters
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');

// Get branches for filter
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

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

// Total Sales
$totalSales = $db->getRow("SELECT 
    COUNT(*) as total_count,
    SUM(s.total_amount) as total_amount,
    SUM(s.subtotal) as total_subtotal,
    SUM(s.discount_amount) as total_discount,
    SUM(s.tax_amount) as total_tax,
    AVG(s.total_amount) as avg_sale
    FROM sales s 
    WHERE $whereClause", $params);
if ($totalSales === false) {
    $totalSales = ['total_count' => 0, 'total_amount' => 0, 'total_subtotal' => 0, 'total_discount' => 0, 'total_tax' => 0, 'avg_sale' => 0];
}

// Refunds
$refunds = $db->getRow("SELECT 
    COUNT(*) as refund_count,
    SUM(r.total_amount) as refund_amount
    FROM refunds r
    INNER JOIN sales s ON r.sale_id = s.id
    WHERE $whereClause", $params);
if ($refunds === false) {
    $refunds = ['refund_count' => 0, 'refund_amount' => 0];
}

// Credit Sales (if enabled)
$creditSalesStats = ['total_credit_sales' => 0, 'total_outstanding' => 0, 'outstanding_count' => 0];
if (getSetting('allow_credit_sales', '0') == '1') {
    $creditSalesWhere = array_merge($whereConditions, ["s.is_credit_sale = 1"]);
    $creditSalesWhereClause = implode(' AND ', $creditSalesWhere);
    
    $creditSales = $db->getRow("SELECT 
        COUNT(*) as total_credit_sales,
        COUNT(CASE WHEN s.account_balance > 0 THEN 1 END) as outstanding_count,
        COALESCE(SUM(s.account_balance), 0) as total_outstanding
        FROM sales s
        WHERE $creditSalesWhereClause", $params);
    if ($creditSales !== false) {
        $creditSalesStats = $creditSales;
    }
}

// Payment methods breakdown (using base amounts)
$paymentMethods = $db->getRows("SELECT 
    sp.payment_method,
    COUNT(*) as count,
    SUM(COALESCE(sp.base_amount, sp.amount)) as total
    FROM sale_payments sp
    INNER JOIN sales s ON sp.sale_id = s.id
    WHERE $whereClause
    GROUP BY sp.payment_method
    ORDER BY total DESC", $params);
if ($paymentMethods === false) $paymentMethods = [];

// Currency breakdown
$currencyBreakdown = [];
$currencies = getActiveCurrencies($db);
foreach ($currencies as $currency) {
    $currencySales = $db->getRow("SELECT 
                                      COUNT(DISTINCT sp.sale_id) as transaction_count,
                                      SUM(COALESCE(sp.base_amount, sp.amount)) as total_base,
                                      SUM(COALESCE(sp.original_amount, sp.amount)) as total_original
                                   FROM sale_payments sp
                                   INNER JOIN sales s ON sp.sale_id = s.id
                                   WHERE $whereClause
                                     AND COALESCE(sp.currency_id, :base_currency_id) = :currency_id", 
                                   array_merge($params, [
                                       ':currency_id' => $currency['id'],
                                       ':base_currency_id' => getBaseCurrency($db)['id'] ?? 1
                                   ]));
    if ($currencySales && ($currencySales['total_base'] > 0 || $currencySales['total_original'] > 0)) {
        $currencyBreakdown[$currency['id']] = [
            'currency' => $currency,
            'transaction_count' => intval($currencySales['transaction_count']),
            'total_base' => floatval($currencySales['total_base']),
            'total_original' => floatval($currencySales['total_original'])
        ];
    }
}

// Payment method and currency combination
$paymentMethodCurrencyBreakdown = $db->getRows("SELECT 
                                                    sp.payment_method,
                                                    COALESCE(sp.currency_id, :base_currency_id) as currency_id,
                                                    COUNT(*) as count,
                                                    SUM(COALESCE(sp.base_amount, sp.amount)) as total_base,
                                                    SUM(COALESCE(sp.original_amount, sp.amount)) as total_original
                                                 FROM sale_payments sp
                                                 INNER JOIN sales s ON sp.sale_id = s.id
                                                 WHERE $whereClause
                                                 GROUP BY sp.payment_method, sp.currency_id
                                                 ORDER BY sp.payment_method, sp.currency_id", 
                                                 array_merge($params, [':base_currency_id' => getBaseCurrency($db)['id'] ?? 1]));
if ($paymentMethodCurrencyBreakdown === false) {
    $paymentMethodCurrencyBreakdown = [];
}

// Daily sales trend (last 30 days)
$dailySales = $db->getRows("SELECT 
    DATE(s.sale_date) as sale_day,
    COUNT(*) as count,
    SUM(s.total_amount) as total
    FROM sales s
    WHERE DATE(s.sale_date) BETWEEN :start_date AND :end_date
    " . ($selectedBranch !== 'all' && $selectedBranch ? "AND s.branch_id = :branch_id" : ($branchId !== null ? "AND s.branch_id = :branch_id" : "")) . "
    GROUP BY DATE(s.sale_date)
    ORDER BY sale_day ASC", $params);
if ($dailySales === false) $dailySales = [];

// Top products
$topProducts = $db->getRows("SELECT 
    si.product_name,
    SUM(si.quantity) as total_quantity,
    SUM(si.total_price) as total_revenue
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    WHERE $whereClause
    GROUP BY si.product_name
    ORDER BY total_revenue DESC
    LIMIT 10", $params);
if ($topProducts === false) $topProducts = [];

// Top customers
$topCustomers = $db->getRows("SELECT 
    c.first_name,
    c.last_name,
    COUNT(s.id) as purchase_count,
    SUM(s.total_amount) as total_spent
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE $whereClause
    GROUP BY s.customer_id, c.first_name, c.last_name
    HAVING purchase_count > 0
    ORDER BY total_spent DESC
    LIMIT 10", $params);
if ($topCustomers === false) $topCustomers = [];

require_once APP_PATH . '/includes/header.php';
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

.stat-card-modern.total-sales::before { background: #3b82f6; }
.stat-card-modern.subtotal::before { background: #10b981; }
.stat-card-modern.discounts::before { background: #f59e0b; }
.stat-card-modern.refunds::before { background: #ef4444; }
.stat-card-modern.tax::before { background: #6366f1; }
.stat-card-modern.avg-sale::before { background: #06b6d4; }
.stat-card-modern.net-sales::before { background: #10b981; }
.stat-card-modern.transactions::before { background: #8b5cf6; }
.stat-card-modern.credit-sales::before { background: #ec4899; }
.stat-card-modern.outstanding::before { background: #f97316; }
.stat-card-modern.outstanding-amount::before { background: #ef4444; }
.stat-card-modern.view-credit::before { background: #3b82f6; }

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

.stat-icon-modern.total-sales { background: #3b82f6; }
.stat-icon-modern.subtotal { background: #10b981; }
.stat-icon-modern.discounts { background: #f59e0b; }
.stat-icon-modern.refunds { background: #ef4444; }
.stat-icon-modern.tax { background: #6366f1; }
.stat-icon-modern.avg-sale { background: #06b6d4; }
.stat-icon-modern.net-sales { background: #10b981; }
.stat-icon-modern.transactions { background: #8b5cf6; }
.stat-icon-modern.credit-sales { background: #ec4899; }
.stat-icon-modern.outstanding { background: #f97316; }
.stat-icon-modern.outstanding-amount { background: #ef4444; }
.stat-icon-modern.view-credit { background: #3b82f6; }

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

.stat-subtext-modern {
    margin-top: 8px;
    font-size: 12px;
    color: var(--text-muted);
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

.table-card-modern {
    background: var(--white);
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
    border: 1px solid var(--border-color);
    height: 100%;
    animation: fadeInUp 0.6s ease-out;
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
        <form method="GET" class="filter-row">
            <div class="filter-group">
                <label class="filter-label">
                    <i class="bi bi-calendar3"></i>
                    <span>Start Date</span>
                </label>
                <div class="filter-input-wrapper">
                    <input type="date" name="start_date" class="form-control" value="<?= escapeHtml($startDate) ?>">
                </div>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">
                    <i class="bi bi-calendar-check"></i>
                    <span>End Date</span>
                </label>
                <div class="filter-input-wrapper">
                    <input type="date" name="end_date" class="form-control" value="<?= escapeHtml($endDate) ?>">
                </div>
            </div>
            
            <?php if (!$branchId): ?>
            <div class="filter-group">
                <label class="filter-label">
                    <i class="bi bi-building"></i>
                    <span>Branch</span>
                </label>
                <div class="filter-input-wrapper">
                    <select name="branch_id" class="form-select">
                        <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>>
                                <?= escapeHtml($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="filter-btn-wrapper">
                <button type="submit" class="btn btn-primary filter-btn">
                    <i class="bi bi-funnel"></i>
                    <span>Apply Filters</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Primary Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern total-sales">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-cash-stack"></i>
                            <span>Total Sales</span>
                        </div>
                        <div class="stat-value-modern"><?= formatCurrency($totalSales['total_amount'] ?? 0) ?></div>
                        <div class="stat-subtext-modern"><?= number_format($totalSales['total_count'] ?? 0) ?> transactions</div>
                    </div>
                    <div class="stat-icon-modern total-sales">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern subtotal">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-receipt"></i>
                            <span>Subtotal</span>
                        </div>
                        <div class="stat-value-modern"><?= formatCurrency($totalSales['total_subtotal'] ?? 0) ?></div>
                        <div class="stat-subtext-modern">Before discounts & tax</div>
                    </div>
                    <div class="stat-icon-modern subtotal">
                        <i class="bi bi-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern discounts">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-tag"></i>
                            <span>Discounts</span>
                        </div>
                        <div class="stat-value-modern" style="color: #f59e0b;"><?= formatCurrency($totalSales['total_discount'] ?? 0) ?></div>
                        <div class="stat-subtext-modern">Total discounts given</div>
                    </div>
                    <div class="stat-icon-modern discounts">
                        <i class="bi bi-tag"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern refunds">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span>Refunds</span>
                        </div>
                        <div class="stat-value-modern" style="color: #ef4444;"><?= formatCurrency($refunds['refund_amount'] ?? 0) ?></div>
                        <div class="stat-subtext-modern"><?= number_format($refunds['refund_count'] ?? 0) ?> refunds</div>
                    </div>
                    <div class="stat-icon-modern refunds">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Secondary Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern tax">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-percent"></i>
                            <span>Tax Collected</span>
                        </div>
                        <div class="stat-value-modern"><?= formatCurrency($totalSales['total_tax'] ?? 0) ?></div>
                    </div>
                    <div class="stat-icon-modern tax">
                        <i class="bi bi-percent"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern avg-sale">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-calculator"></i>
                            <span>Average Sale</span>
                        </div>
                        <div class="stat-value-modern" style="font-size: 22px;"><?= formatCurrency($totalSales['avg_sale'] ?? 0) ?></div>
                    </div>
                    <div class="stat-icon-modern avg-sale">
                        <i class="bi bi-calculator"></i>
                    </div>
                </div>
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
                        <div class="stat-value-modern" style="color: #10b981;"><?= formatCurrency(($totalSales['total_amount'] ?? 0) - ($refunds['refund_amount'] ?? 0)) ?></div>
                        <div class="stat-subtext-modern">After refunds</div>
                    </div>
                    <div class="stat-icon-modern net-sales">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern transactions">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-receipt-cutoff"></i>
                            <span>Transactions</span>
                        </div>
                        <div class="stat-value-modern"><?= number_format($totalSales['total_count'] ?? 0) ?></div>
                        <div class="stat-subtext-modern">Total count</div>
                    </div>
                    <div class="stat-icon-modern transactions">
                        <i class="bi bi-receipt-cutoff"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (getSetting('allow_credit_sales', '0') == '1'): ?>
    <!-- Credit Sales Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern credit-sales">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-credit-card"></i>
                            <span>Credit Sales</span>
                        </div>
                        <div class="stat-value-modern"><?= number_format($creditSalesStats['total_credit_sales'] ?? 0) ?></div>
                        <div class="stat-subtext-modern">Total credit sales</div>
                    </div>
                    <div class="stat-icon-modern credit-sales">
                        <i class="bi bi-credit-card"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern outstanding">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span>Outstanding</span>
                        </div>
                        <div class="stat-value-modern" style="color: #f97316;"><?= number_format($creditSalesStats['outstanding_count'] ?? 0) ?></div>
                        <div class="stat-subtext-modern">Unsettled accounts</div>
                    </div>
                    <div class="stat-icon-modern outstanding">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <div class="stat-card-modern outstanding-amount">
                <div class="stat-card-header-modern">
                    <div class="stat-content-modern">
                        <div class="stat-label-modern">
                            <i class="bi bi-currency-dollar"></i>
                            <span>Outstanding Amount</span>
                        </div>
                        <div class="stat-value-modern" style="color: #ef4444; font-size: 22px;"><?= formatCurrency($creditSalesStats['total_outstanding'] ?? 0) ?></div>
                        <div class="stat-subtext-modern">Total balance due</div>
                    </div>
                    <div class="stat-icon-modern outstanding-amount">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6">
            <a href="credit_sales.php" class="text-decoration-none">
                <div class="stat-card-modern view-credit">
                    <div class="stat-card-header-modern">
                        <div class="stat-content-modern">
                            <div class="stat-label-modern">
                                <i class="bi bi-arrow-right-circle"></i>
                                <span>View Credit Sales</span>
                            </div>
                            <div class="stat-value-modern" style="color: #3b82f6; font-size: 24px;">
                                <i class="bi bi-arrow-right-circle-fill"></i>
                            </div>
                            <div class="stat-subtext-modern">Click to view details</div>
                        </div>
                        <div class="stat-icon-modern view-credit">
                            <i class="bi bi-arrow-right-circle"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Charts Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="chart-card-modern">
                <div class="chart-card-header-modern">
                    <h5 class="chart-title-modern">
                        <i class="bi bi-graph-up"></i>
                        <span>Daily Sales Trend</span>
                    </h5>
                </div>
                <div class="chart-container-modern">
                    <canvas id="dailySalesChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="chart-card-modern">
                <div class="chart-card-header-modern">
                    <h5 class="chart-title-modern">
                        <i class="bi bi-pie-chart-fill"></i>
                        <span>Payment Methods</span>
                    </h5>
                </div>
                <div class="chart-container-modern">
                    <canvas id="paymentMethodsChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($currencyBreakdown)): ?>
    <!-- Currency Breakdown Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="table-card-modern">
                <div class="chart-card-header-modern">
                    <h5 class="chart-title-modern">
                        <i class="bi bi-currency-exchange"></i>
                        <span>Currency Breakdown</span>
                    </h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Currency</th>
                                <th>Transactions</th>
                                <th>Amount</th>
                                <th>Base Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($currencyBreakdown as $breakdown): 
                                $currency = $breakdown['currency'];
                            ?>
                                <tr>
                                    <td><strong><?= escapeHtml($currency['code']) ?></strong></td>
                                    <td><?= $breakdown['transaction_count'] ?></td>
                                    <td><?= formatCurrencyAmount($breakdown['total_original'], $currency['id'], $db) ?></td>
                                    <td><?= formatCurrencyAmount($breakdown['total_base'], getBaseCurrency($db)['id'], $db) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="table-card-modern">
                <div class="chart-card-header-modern">
                    <h5 class="chart-title-modern">
                        <i class="bi bi-list-ul"></i>
                        <span>Payment Method & Currency Split</span>
                    </h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Currency</th>
                                <th>Count</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentMethodCurrencyBreakdown as $breakdown): 
                                $currency = getCurrency($breakdown['currency_id'], $db);
                            ?>
                                <tr>
                                    <td><?= escapeHtml(ucfirst($breakdown['payment_method'])) ?></td>
                                    <td><?= escapeHtml($currency ? $currency['code'] : 'N/A') ?></td>
                                    <td><?= $breakdown['count'] ?></td>
                                    <td><?= formatCurrencyAmount($breakdown['total_original'], $breakdown['currency_id'], $db) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top Products and Customers Row -->
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="table-card-modern">
                <div class="chart-card-header-modern">
                    <h5 class="chart-title-modern">
                        <i class="bi bi-trophy"></i>
                        <span>Top Products</span>
                    </h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProducts as $product): ?>
                                <tr>
                                    <td><?= escapeHtml($product['product_name']) ?></td>
                                    <td><?= number_format($product['total_quantity']) ?></td>
                                    <td><?= formatCurrency($product['total_revenue']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <div class="table-card-modern">
                <div class="chart-card-header-modern">
                    <h5 class="chart-title-modern">
                        <i class="bi bi-people"></i>
                        <span>Top Customers</span>
                    </h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Purchases</th>
                                <th>Total Spent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topCustomers as $customer): ?>
                                <tr>
                                    <td><?= escapeHtml(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? 'Walk-in'))) ?></td>
                                    <td><?= number_format($customer['purchase_count']) ?></td>
                                    <td><?= formatCurrency($customer['total_spent']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Daily Sales Chart
const dailySalesCtx = document.getElementById('dailySalesChart').getContext('2d');
const dailySalesData = <?= json_encode(array_map(function($item) {
    return ['day' => $item['sale_day'], 'total' => floatval($item['total'])];
}, $dailySales)) ?>;

new Chart(dailySalesCtx, {
    type: 'line',
    data: {
        labels: dailySalesData.map(d => d.day),
        datasets: [{
            label: 'Sales',
            data: dailySalesData.map(d => d.total),
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
            legend: { display: false },
            tooltip: {
                bodyFont: { size: 12 },
                titleFont: { size: 13 }
            }
        },
        scales: {
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
                    font: { size: 11 }
                },
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                }
            },
            x: {
                ticks: {
                    font: { size: 10 },
                    maxRotation: 45,
                    minRotation: 45
                }
            }
        }
    }
});

// Payment Methods Chart
const paymentCtx = document.getElementById('paymentMethodsChart').getContext('2d');
const paymentData = <?= json_encode(array_map(function($item) {
    return ['method' => $item['payment_method'], 'total' => floatval($item['total'])];
}, $paymentMethods)) ?>;

new Chart(paymentCtx, {
    type: 'doughnut',
    data: {
        labels: paymentData.map(d => d.method),
        datasets: [{
            data: paymentData.map(d => d.total),
            backgroundColor: [
                '#3b82f6',
                '#10b981',
                '#f59e0b',
                '#ef4444',
                '#8b5cf6',
                '#06b6d4',
                '#ec4899'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { 
                position: 'bottom',
                labels: {
                    font: { size: 11 },
                    padding: 15
                }
            },
            tooltip: {
                bodyFont: { size: 12 },
                titleFont: { size: 13 },
                callbacks: {
                    label: function(context) {
                        let label = context.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed !== null) {
                            label += new Intl.NumberFormat('en-US', {
                                style: 'currency',
                                currency: 'USD'
                            }).format(context.parsed);
                        }
                        return label;
                    }
                }
            }
        }
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
