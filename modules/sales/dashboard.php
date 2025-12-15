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
.stats-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.stats-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--primary-blue);
    margin: 10px 0;
}

.stats-label {
    color: #6b7280;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.chart-container {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Sales Dashboard</h2>
</div>

<!-- Filters -->
<div class="filter-card">
    <form method="GET" class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= escapeHtml($startDate) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= escapeHtml($endDate) ?>">
        </div>
        <?php if (!$branchId): ?>
        <div class="col-md-3">
            <label class="form-label">Branch</label>
            <select name="branch_id" class="form-select">
                <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>>
                        <?= escapeHtml($branch['branch_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply Filters</button>
        </div>
    </form>
</div>

<!-- Stats Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-label">Total Sales</div>
            <div class="stats-value"><?= formatCurrency($totalSales['total_amount'] ?? 0) ?></div>
            <small class="text-muted"><?= number_format($totalSales['total_count'] ?? 0) ?> transactions</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-label">Subtotal</div>
            <div class="stats-value"><?= formatCurrency($totalSales['total_subtotal'] ?? 0) ?></div>
            <small class="text-muted">Before discounts & tax</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-label">Discounts</div>
            <div class="stats-value text-warning"><?= formatCurrency($totalSales['total_discount'] ?? 0) ?></div>
            <small class="text-muted">Total discounts given</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-label">Refunds</div>
            <div class="stats-value text-danger"><?= formatCurrency($refunds['refund_amount'] ?? 0) ?></div>
            <small class="text-muted"><?= number_format($refunds['refund_count'] ?? 0) ?> refunds</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-label">Tax Collected</div>
            <div class="stats-value"><?= formatCurrency($totalSales['total_tax'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-label">Average Sale</div>
            <div class="stats-value"><?= formatCurrency($totalSales['avg_sale'] ?? 0) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-label">Net Sales</div>
            <div class="stats-value text-success"><?= formatCurrency(($totalSales['total_amount'] ?? 0) - ($refunds['refund_amount'] ?? 0)) ?></div>
            <small class="text-muted">After refunds</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-label">Transactions</div>
            <div class="stats-value"><?= number_format($totalSales['total_count'] ?? 0) ?></div>
            <small class="text-muted">Total count</small>
        </div>
    </div>
</div>

<!-- Charts and Tables -->
<div class="row">
    <div class="col-md-8">
        <div class="chart-container">
            <h5 class="mb-3">Daily Sales Trend</h5>
            <canvas id="dailySalesChart" height="80"></canvas>
        </div>
    </div>
    <div class="col-md-4">
        <div class="chart-container">
            <h5 class="mb-3">Payment Methods</h5>
            <canvas id="paymentMethodsChart"></canvas>
        </div>
    </div>
</div>

<?php if (!empty($currencyBreakdown)): ?>
<div class="row">
    <div class="col-md-6">
        <div class="chart-container">
            <h5 class="mb-3">Currency Breakdown</h5>
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
    <div class="col-md-6">
        <div class="chart-container">
            <h5 class="mb-3">Payment Method & Currency Split</h5>
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

<div class="row">
    <div class="col-md-6">
        <div class="chart-container">
            <h5 class="mb-3">Top Products</h5>
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
    <div class="col-md-6">
        <div class="chart-container">
            <h5 class="mb-3">Top Customers</h5>
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
            borderColor: 'rgb(30, 58, 138)',
            backgroundColor: 'rgba(30, 58, 138, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toFixed(0);
                    }
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
                'rgb(30, 58, 138)',
                'rgb(59, 130, 246)',
                'rgb(96, 165, 250)',
                'rgb(147, 197, 253)',
                'rgb(191, 219, 254)'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


