<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

$pageTitle = 'Dashboard';

$db = Database::getInstance();
$branchId = $_GET['branch_id'] ?? $_SESSION['branch_id'] ?? null;
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
$totalCustomers = $db->getRow("SELECT COUNT(*) as count FROM customers" . ($branchId ? " WHERE branch_id = :branch_id" : ""), 
    $branchId ? [':branch_id' => $branchId] : []) ?: ['count' => 0];

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
if ($startDate === date('Y-m-d') && $endDate === date('Y-m-d')) {
    $hourlyData = $db->getRows("SELECT 
        HOUR(sale_date) as hour,
        COALESCE(SUM(total_amount), 0) as gross_sales,
        COALESCE(SUM(subtotal - discount_amount), 0) as net_sales,
        COALESCE(SUM((SELECT SUM(si2.quantity * p2.cost_price) FROM sale_items si2 INNER JOIN products p2 ON si2.product_id = p2.id WHERE si2.sale_id = s.id)), 0) as cost_of_sales
        FROM sales s
        WHERE DATE(sale_date) = :date" . ($branchId ? " AND branch_id = :branch_id" : "") . "
        GROUP BY HOUR(sale_date)
        ORDER BY hour", 
        array_merge([':date' => $startDate], $branchId ? [':branch_id' => $branchId] : [])) ?: [];
    
    for ($i = 0; $i < 24; $i++) {
        $hourlySales[$i] = [
            'gross_sales' => 0,
            'net_sales' => 0,
            'cost_of_sales' => 0,
            'gross_profit' => 0
        ];
    }
    
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
            }
        }
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
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
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
    font-size: 13px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 28px;
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
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div></div>
    <div class="d-flex gap-2">
        <select class="form-select" id="branchFilter" style="width: auto;">
            <option value="">All shops</option>
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

<!-- Additional Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-md-2">
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
    <div class="col-md-2">
        <div class="stat-card avg-transaction">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Avg Transaction</div>
                    <div class="stat-value" style="font-size: 22px;"><?= formatCurrency($avgTransaction) ?></div>
                </div>
                <div class="stat-icon avg-transaction">
                    <i class="bi bi-calculator"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
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
    <div class="col-md-2">
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
    <div class="col-md-2">
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
    <div class="col-md-2">
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
</div>

<!-- Inventory Value -->
<div class="row g-3 mb-4">
    <div class="col-md-12">
        <div class="stat-card inventory-value">
            <div class="stat-card-header">
                <div>
                    <div class="stat-label">Total Inventory Value</div>
                    <div class="stat-value" style="font-size: 32px;"><?= formatCurrency($inventoryValue['value']) ?></div>
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
                            <div class="stat-value" style="font-size: 24px;"><?= formatCurrency($discount) ?></div>
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
                            <div class="stat-value" style="font-size: 24px;"><?= formatCurrency($cashRefund) ?></div>
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
    if (branch) params.push('branch_id=' + encodeURIComponent(branch));
    if (startDate) params.push('start_date=' + encodeURIComponent(startDate));
    if (endDate) params.push('end_date=' + encodeURIComponent(endDate));
    if (params.length > 0) {
        url += '?' + params.join('&');
    }
    window.location.href = url;
}

// Mini charts for stat cards
const miniChartOptions = {
    responsive: true,
    maintainAspectRatio: true,
    aspectRatio: 4,
    plugins: { legend: { display: false } },
    scales: { x: { display: false }, y: { display: false } },
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
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// Deduction Chart
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
        scales: {
            y: { beginAtZero: true }
        },
        plugins: {
            legend: { display: true }
        }
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
