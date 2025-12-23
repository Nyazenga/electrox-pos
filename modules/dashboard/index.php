<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

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

// Low stock products
$lowStockProducts = $db->getRow("SELECT COUNT(*) as count FROM products WHERE quantity_in_stock <= reorder_level AND status = 'Active'" . ($branchId ? " AND branch_id = :branch_id" : ""), 
    $branchId ? [':branch_id' => $branchId] : []) ?: ['count' => 0];

// Total inventory value
$inventoryValue = $db->getRow("SELECT COALESCE(SUM(quantity_in_stock * cost_price), 0) as value FROM products WHERE status = 'Active'" . ($branchId ? " AND branch_id = :branch_id" : ""), 
    $branchId ? [':branch_id' => $branchId] : []) ?: ['value' => 0];

// Get cash refunds
$refundData = $db->getRow("SELECT COALESCE(SUM(amount), 0) as total_refunds 
    FROM drawer_transactions 
    WHERE transaction_type = 'pay_out' 
    AND DATE(created_at) BETWEEN :start_date AND :end_date" . ($branchId ? " AND EXISTS (SELECT 1 FROM shifts WHERE id = drawer_transactions.shift_id AND branch_id = :branch_id)" : ""), 
    $params) ?: ['total_refunds' => 0];
$cashRefund = floatval($refundData['total_refunds'] ?? 0);

// Get hourly sales for today
$hourlySales = [];
$hourlyDeductions = [];
if ($startDate === date('Y-m-d') && $endDate === date('Y-m-d')) {
    $hourlyData = $db->getRows("SELECT 
        HOUR(sale_date) as hour,
        COALESCE(SUM(total_amount), 0) as gross_sales,
        COALESCE(SUM(subtotal - discount_amount), 0) as net_sales,
        COALESCE(SUM(discount_amount), 0) as discount_amount,
        COALESCE(SUM((SELECT SUM(si2.quantity * p2.cost_price) FROM sale_items si2 INNER JOIN products p2 ON si2.product_id = p2.id WHERE si2.sale_id = s.id)), 0) as cost_of_sales
        FROM sales s
        WHERE DATE(sale_date) = :date" . ($branchId ? " AND branch_id = :branch_id" : "") . "
        GROUP BY HOUR(sale_date)
        ORDER BY hour", 
        array_merge([':date' => $startDate], $branchId ? [':branch_id' => $branchId] : [])) ?: [];
    
    // Get hourly refunds
    $hourlyRefundData = $db->getRows("SELECT 
        HOUR(dt.created_at) as hour,
        COALESCE(SUM(dt.amount), 0) as refund_amount
        FROM drawer_transactions dt
        WHERE dt.transaction_type = 'pay_out'
        AND DATE(dt.created_at) = :date" . ($branchId ? " AND EXISTS (SELECT 1 FROM shifts WHERE id = dt.shift_id AND branch_id = :branch_id)" : "") . "
        GROUP BY HOUR(dt.created_at)
        ORDER BY hour", 
        array_merge([':date' => $startDate], $branchId ? [':branch_id' => $branchId] : [])) ?: [];
    
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

require_once APP_PATH . '/includes/header.php';
?>

<style>
.stat-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    height: 180px;
    max-height: 180px;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
    border-left: 4px solid;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
}

.stat-card.gross-sales { border-left-color: #3b82f6; }
.stat-card.net-sales { border-left-color: #10b981; }
.stat-card.cost-sales { border-left-color: #ef4444; }
.stat-card.gross-profit { border-left-color: #f59e0b; }
.stat-card.total-sales { border-left-color: #8b5cf6; }
.stat-card.avg-transaction { border-left-color: #06b6d4; }
.stat-card.total-customers { border-left-color: #ec4899; }
.stat-card.total-products { border-left-color: #14b8a6; }
.stat-card.total-invoices { border-left-color: #6366f1; }
.stat-card.low-stock { border-left-color: #f97316; }
.stat-card.inventory-value { border-left-color: #0ea5e9; }

.stat-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}

.stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: white;
    flex-shrink: 0;
}

.stat-icon.gross-sales { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
.stat-icon.net-sales { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
.stat-icon.cost-sales { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
.stat-icon.gross-profit { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
.stat-icon.total-sales { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
.stat-icon.avg-transaction { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); }
.stat-icon.total-customers { background: linear-gradient(135deg, #ec4899 0%, #db2777 100%); }
.stat-icon.total-products { background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); }
.stat-icon.total-invoices { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }
.stat-icon.low-stock { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }
.stat-icon.inventory-value { background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); }

.stat-label {
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
    margin-bottom: 6px;
}

.stat-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
    flex-shrink: 0;
    line-height: 1.2;
}

.mini-chart {
    height: 50px;
    max-height: 50px;
    margin-top: auto;
    flex-shrink: 0;
    flex-grow: 0;
}

.chart-container {
    position: relative;
    height: 400px;
}

/* Card header and body responsive */
.card-header {
    padding: 12px 16px;
}

.card-header h5,
.card-header h6 {
    font-size: 14px;
    font-weight: 600;
    margin: 0;
}

.card-body {
    padding: 16px;
}

/* Responsive adjustments for small laptops (1024px - 1366px) */
@media (max-width: 1366px) and (min-width: 1024px) {
    .stat-card {
        padding: 16px;
        height: 150px;
        max-height: 150px;
    }
    
    .stat-value {
        font-size: 16px;
    }
    
    .stat-label {
        font-size: 9px;
        margin-bottom: 4px;
    }
    
    .stat-icon {
        width: 30px;
        height: 30px;
        font-size: 14px;
    }
    
    .chart-container {
        height: 300px;
    }
    
    .card-header h5,
    .card-header h6 {
        font-size: 13px;
    }
    
    .card-body {
        padding: 12px;
    }
    
    .stat-value[style*="font-size: 18px"] {
        font-size: 14px !important;
    }
    
    .stat-value[style*="font-size: 22px"] {
        font-size: 16px !important;
    }
    
    .mini-chart {
        height: 40px;
        max-height: 40px;
    }
    
    .d-flex.justify-content-between h2 {
        font-size: 18px;
    }
    
    .form-select,
    .form-control {
        font-size: 11px;
        padding: 5px 8px;
    }
    
    .btn {
        font-size: 11px;
        padding: 5px 10px;
    }
}

/* Tablet screens (769px - 1023px) */
@media (max-width: 1023px) and (min-width: 769px) {
    .stat-card {
        padding: 14px;
        height: 140px;
        max-height: 140px;
    }
    
    .stat-value {
        font-size: 15px;
    }
    
    .stat-label {
        font-size: 8px;
        margin-bottom: 3px;
    }
    
    .stat-icon {
        width: 28px;
        height: 28px;
        font-size: 13px;
    }
    
    .chart-container {
        height: 280px;
    }
    
    .card-header h5,
    .card-header h6 {
        font-size: 12px;
    }
    
    .card-body {
        padding: 10px;
    }
    
    .stat-value[style*="font-size: 18px"] {
        font-size: 13px !important;
    }
    
    .stat-value[style*="font-size: 22px"] {
        font-size: 15px !important;
    }
    
    .mini-chart {
        height: 35px;
        max-height: 35px;
    }
    
    .d-flex.justify-content-between h2 {
        font-size: 16px;
    }
    
    .form-select,
    .form-control {
        font-size: 10px;
        padding: 4px 6px;
    }
    
    .btn {
        font-size: 10px;
        padding: 4px 8px;
    }
    
    .row.g-3 {
        --bs-gutter-y: 0.5rem;
        --bs-gutter-x: 0.5rem;
    }
}

/* Mobile screens (max-width: 768px) */
@media (max-width: 768px) {
    .stat-card {
        padding: 12px;
        height: 130px;
        max-height: 130px;
    }
    
    .stat-value {
        font-size: 14px;
    }
    
    .stat-label {
        font-size: 8px;
        margin-bottom: 3px;
    }
    
    .stat-icon {
        width: 26px;
        height: 26px;
        font-size: 12px;
    }
    
    .chart-container {
        height: 250px;
    }
    
    .card-header h5,
    .card-header h6 {
        font-size: 11px;
    }
    
    .card-body {
        padding: 10px;
    }
    
    .stat-value[style*="font-size: 18px"] {
        font-size: 12px !important;
    }
    
    .stat-value[style*="font-size: 22px"] {
        font-size: 14px !important;
    }
    
    .mini-chart {
        height: 30px;
        max-height: 30px;
    }
    
    .d-flex.justify-content-between {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start !important;
    }
    
    .d-flex.justify-content-between h2 {
        font-size: 15px;
        margin-bottom: 0;
    }
    
    .d-flex.gap-2 {
        width: 100%;
        flex-wrap: wrap;
    }
    
    .form-select,
    .form-control {
        font-size: 11px;
        padding: 6px 8px;
        flex: 1;
        min-width: 120px;
    }
    
    .btn {
        font-size: 11px;
        padding: 6px 12px;
    }
    
    .row.g-3 {
        --bs-gutter-y: 0.5rem;
        --bs-gutter-x: 0.5rem;
    }
    
    /* Stack cards in single column on mobile */
    .row.g-3 > [class*="col-"] {
        margin-bottom: 0.5rem;
    }
}

/* Small mobile screens (max-width: 480px) */
@media (max-width: 480px) {
    .stat-card {
        padding: 10px;
        height: 120px;
        max-height: 120px;
    }
    
    .stat-value {
        font-size: 13px;
    }
    
    .stat-label {
        font-size: 7px;
        margin-bottom: 2px;
    }
    
    .stat-icon {
        width: 24px;
        height: 24px;
        font-size: 11px;
    }
    
    .chart-container {
        height: 220px;
    }
    
    .card-header h5,
    .card-header h6 {
        font-size: 10px;
    }
    
    .card-body {
        padding: 8px;
    }
    
    .stat-value[style*="font-size: 18px"] {
        font-size: 11px !important;
    }
    
    .stat-value[style*="font-size: 22px"] {
        font-size: 13px !important;
    }
    
    .mini-chart {
        height: 25px;
        max-height: 25px;
    }
    
    .d-flex.justify-content-between h2 {
        font-size: 14px;
    }
    
    .form-select,
    .form-control {
        font-size: 11px;
        padding: 5px 6px;
        width: 100%;
        margin-bottom: 5px;
    }
    
    .btn {
        font-size: 11px;
        padding: 5px 10px;
        width: 100%;
    }
    
    .d-flex.gap-2 {
        flex-direction: column;
        width: 100%;
    }
    
    .row.g-3 {
        --bs-gutter-y: 0.5rem;
        --bs-gutter-x: 0.5rem;
    }
    
    /* Make all columns full width on very small screens */
    .row > [class*="col-"] {
        flex: 0 0 100%;
        max-width: 100%;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div></div>
    <div class="d-flex gap-2">
        <select class="form-select" id="branchFilter" style="min-width: 150px;">
            <option value="" <?= !isset($_GET['branch_id']) || $_GET['branch_id'] === '' ? 'selected' : '' ?>>All shops</option>
            <?php foreach ($branches as $branch): ?>
                <option value="<?= $branch['id'] ?>" <?= $branchId == $branch['id'] ? 'selected' : '' ?>>
                    <?= escapeHtml($branch['branch_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="date" class="form-control form-control-sm" id="startDate" value="<?= $startDate ?>" style="width: auto;">
        <input type="date" class="form-control form-control-sm" id="endDate" value="<?= $endDate ?>" style="width: auto;">
        <button class="btn btn-primary" onclick="applyFilters()">Filter</button>
    </div>
</div>

<!-- Main Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card gross-sales">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Gross Sales</div>
                    <div class="stat-value"><?= formatCurrency($grossSales) ?></div>
                </div>
                <div class="stat-icon gross-sales">
                    <i class="bi bi-cash-stack"></i>
                </div>
            </div>
            <canvas class="mini-chart" id="grossSalesChart"></canvas>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card net-sales">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Net Sales</div>
                    <div class="stat-value"><?= formatCurrency($netSales) ?></div>
                </div>
                <div class="stat-icon net-sales">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
            </div>
            <canvas class="mini-chart" id="netSalesChart"></canvas>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card cost-sales">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Cost Of Sales</div>
                    <div class="stat-value"><?= formatCurrency($costOfSales) ?></div>
                </div>
                <div class="stat-icon cost-sales">
                    <i class="bi bi-cart-dash"></i>
                </div>
            </div>
            <canvas class="mini-chart" id="costSalesChart"></canvas>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card gross-profit">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Gross Profit</div>
                    <div class="stat-value"><?= formatCurrency($grossProfit) ?></div>
                </div>
                <div class="stat-icon gross-profit">
                    <i class="bi bi-trophy"></i>
                </div>
            </div>
            <canvas class="mini-chart" id="grossProfitChart"></canvas>
        </div>
    </div>
</div>

<!-- Additional Stats Row - 3 cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card total-sales">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Total Sales</div>
                    <div class="stat-value"><?= number_format($totalSalesCount['count']) ?></div>
                </div>
                <div class="stat-icon total-sales">
                    <i class="bi bi-receipt-cutoff"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card avg-transaction">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Avg Transaction</div>
                    <div class="stat-value" style="font-size: 18px;"><?= formatCurrency($avgTransaction) ?></div>
                </div>
                <div class="stat-icon avg-transaction">
                    <i class="bi bi-calculator"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card total-customers">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Total Customers</div>
                    <div class="stat-value"><?= number_format($totalCustomers['count']) ?></div>
                </div>
                <div class="stat-icon total-customers">
                    <i class="bi bi-people"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Additional Stats Row - 4 cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card total-products">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Total Products</div>
                    <div class="stat-value"><?= number_format($totalProducts['count']) ?></div>
                </div>
                <div class="stat-icon total-products">
                    <i class="bi bi-box-seam"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card total-invoices">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Total Invoices</div>
                    <div class="stat-value"><?= number_format($totalInvoices['count']) ?></div>
                </div>
                <div class="stat-icon total-invoices">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card low-stock">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Low Stock</div>
                    <div class="stat-value" style="color: <?= $lowStockProducts['count'] > 0 ? '#ef4444' : 'var(--text-dark)' ?>;"><?= number_format($lowStockProducts['count']) ?></div>
                </div>
                <div class="stat-icon low-stock">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card inventory-value">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Total Inventory Value</div>
                    <div class="stat-value" style="font-size: 18px;"><?= formatCurrency($inventoryValue['value']) ?></div>
                </div>
                <div class="stat-icon inventory-value">
                    <i class="bi bi-archive"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Today Sales Summary -->
<?php if ($startDate === date('Y-m-d') && $endDate === date('Y-m-d')): ?>
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Today Sales Summary</h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="todaySalesChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="row">
            <div class="col-12 mb-3">
                <div class="stat-card" style="border-left-color: #a855f7;">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-label">Discount</div>
                            <div class="stat-value" style="font-size: 18px;"><?= formatCurrency($discount) ?></div>
                        </div>
                        <div class="stat-icon" style="background: linear-gradient(135deg, #a855f7 0%, #9333ea 100%);">
                            <i class="bi bi-tag"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 mb-3">
                <div class="stat-card" style="border-left-color: #f43f5e;">
                    <div class="stat-card-header">
                        <div>
                            <div class="stat-label">Cash Refund</div>
                            <div class="stat-value" style="font-size: 18px;"><?= formatCurrency($cashRefund) ?></div>
                        </div>
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Today Sales Deduction</h6>
            </div>
            <div class="card-body">
                <canvas id="deductionChart" height="150"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Today Sales Deduction Chart (Full Width) -->
<?php if ($startDate === date('Y-m-d') && $endDate === date('Y-m-d')): ?>
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Today Sales Deduction</h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="todayDeductionChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Sales Trend -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Sales Trend (Last 30 Days)</h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
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
            tension: 0.4
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
            tension: 0.4
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
            tension: 0.4
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
            tension: 0.4
        }]
    },
    options: miniChartOptions
});

<?php if ($startDate === date('Y-m-d') && $endDate === date('Y-m-d')): ?>
// Today Sales Summary Chart
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
                tension: 0.4
            },
            {
                label: 'Cost Of Sales',
                data: <?= json_encode(array_column($hourlySales, 'cost_of_sales')) ?>,
                type: 'line',
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                tension: 0.4
            },
            {
                label: 'Gross Profit',
                data: <?= json_encode(array_column($hourlySales, 'gross_profit')) ?>,
                type: 'line',
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                tension: 0.4
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

// Deduction Chart (small one in sidebar)
new Chart(document.getElementById('deductionChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(function($i) { return date('g:i A', mktime($i, 0)); }, range(0, 23))) ?>,
        datasets: [{
            label: 'Deductions',
            data: <?= json_encode(array_fill(0, 24, $discount + $cashRefund)) ?>,
            backgroundColor: 'rgba(239, 68, 68, 0.6)'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// Today Sales Deduction Chart (Full Width - col-md-12)
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
            fill: true
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

<?php require_once APP_PATH . '/includes/footer.php'; ?>
