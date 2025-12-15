<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Currency Reports';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCurrency = $_GET['currency_id'] ?? 'all';

// Get branches and currencies for filters
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

$currencies = getActiveCurrencies($db);
$baseCurrency = getBaseCurrency($db);

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

// Currency-wise sales breakdown
$currencySales = [];
foreach ($currencies as $currency) {
    if ($selectedCurrency !== 'all' && $selectedCurrency != $currency['id']) {
        continue;
    }
    
    $sales = $db->getRow("SELECT 
                              COUNT(DISTINCT sp.sale_id) as transaction_count,
                              SUM(COALESCE(sp.base_amount, sp.amount)) as total_base,
                              SUM(COALESCE(sp.original_amount, sp.amount)) as total_original,
                              AVG(COALESCE(sp.base_amount, sp.amount)) as avg_base
                           FROM sale_payments sp
                           INNER JOIN sales s ON sp.sale_id = s.id
                           WHERE $whereClause
                             AND COALESCE(sp.currency_id, :base_currency_id) = :currency_id", 
                           array_merge($params, [
                               ':currency_id' => $currency['id'],
                               ':base_currency_id' => $baseCurrency['id'] ?? 1
                           ]));
    
    if ($sales && ($sales['total_base'] > 0 || $sales['total_original'] > 0)) {
        $currencySales[$currency['id']] = [
            'currency' => $currency,
            'transaction_count' => intval($sales['transaction_count']),
            'total_base' => floatval($sales['total_base']),
            'total_original' => floatval($sales['total_original']),
            'avg_base' => floatval($sales['avg_base'])
        ];
    }
}

// Payment method breakdown by currency
$paymentMethodCurrency = $db->getRows("SELECT 
                                          sp.payment_method,
                                          COALESCE(sp.currency_id, :base_currency_id) as currency_id,
                                          COUNT(*) as transaction_count,
                                          SUM(COALESCE(sp.base_amount, sp.amount)) as total_base,
                                          SUM(COALESCE(sp.original_amount, sp.amount)) as total_original
                                       FROM sale_payments sp
                                       INNER JOIN sales s ON sp.sale_id = s.id
                                       WHERE $whereClause
                                       GROUP BY sp.payment_method, sp.currency_id
                                       ORDER BY sp.payment_method, sp.currency_id", 
                                       array_merge($params, [':base_currency_id' => $baseCurrency['id'] ?? 1]));
if ($paymentMethodCurrency === false) {
    $paymentMethodCurrency = [];
}

// Daily currency breakdown
$dailyCurrencyBreakdown = $db->getRows("SELECT 
                                           DATE(s.sale_date) as sale_day,
                                           COALESCE(sp.currency_id, :base_currency_id) as currency_id,
                                           SUM(COALESCE(sp.base_amount, sp.amount)) as total_base,
                                           SUM(COALESCE(sp.original_amount, sp.amount)) as total_original
                                        FROM sale_payments sp
                                        INNER JOIN sales s ON sp.sale_id = s.id
                                        WHERE $whereClause
                                        GROUP BY DATE(s.sale_date), sp.currency_id
                                        ORDER BY sale_day ASC, currency_id ASC", 
                                        array_merge($params, [':base_currency_id' => $baseCurrency['id'] ?? 1]));
if ($dailyCurrencyBreakdown === false) {
    $dailyCurrencyBreakdown = [];
}

require_once APP_PATH . '/includes/header.php';
?>

<style>
.filter-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.report-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Currency Reports</h2>
</div>

<!-- Filters -->
<div class="filter-card">
    <form method="GET" class="row g-3">
        <div class="col-md-2">
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= escapeHtml($startDate) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= escapeHtml($endDate) ?>">
        </div>
        <?php if (!$branchId): ?>
        <div class="col-md-2">
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
        <div class="col-md-2">
            <label class="form-label">Currency</label>
            <select name="currency_id" class="form-select">
                <option value="all" <?= $selectedCurrency === 'all' ? 'selected' : '' ?>>All Currencies</option>
                <?php foreach ($currencies as $currency): ?>
                    <option value="<?= $currency['id'] ?>" <?= $selectedCurrency == $currency['id'] ? 'selected' : '' ?>>
                        <?= escapeHtml($currency['code']) ?> - <?= escapeHtml($currency['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply</button>
        </div>
    </form>
</div>

<!-- Currency Sales Summary -->
<div class="report-card">
    <h5 class="mb-3">Currency Sales Summary</h5>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Currency</th>
                    <th>Transactions</th>
                    <th>Total Sales (Original)</th>
                    <th>Total Sales (Base)</th>
                    <th>Average Sale (Base)</th>
                    <th>Exchange Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($currencySales)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">No sales found for selected criteria</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($currencySales as $sales): 
                        $currency = $sales['currency'];
                    ?>
                        <tr>
                            <td><strong><?= escapeHtml($currency['code']) ?> - <?= escapeHtml($currency['name']) ?></strong></td>
                            <td><?= $sales['transaction_count'] ?></td>
                            <td><?= formatCurrencyAmount($sales['total_original'], $currency['id'], $db) ?></td>
                            <td><?= formatCurrencyAmount($sales['total_base'], $baseCurrency['id'], $db) ?></td>
                            <td><?= formatCurrencyAmount($sales['avg_base'], $baseCurrency['id'], $db) ?></td>
                            <td>
                                <?php if ($currency['id'] != $baseCurrency['id']): ?>
                                    1 <?= escapeHtml($baseCurrency['code']) ?> = <?= number_format($currency['exchange_rate'], 6) ?> <?= escapeHtml($currency['code']) ?>
                                <?php else: ?>
                                    <span class="badge bg-primary">Base Currency</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Payment Method & Currency Breakdown -->
<?php if (!empty($paymentMethodCurrency)): ?>
<div class="report-card">
    <h5 class="mb-3">Payment Method & Currency Breakdown</h5>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Payment Method</th>
                    <th>Currency</th>
                    <th>Transactions</th>
                    <th>Total (Original)</th>
                    <th>Total (Base)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paymentMethodCurrency as $breakdown): 
                    $currency = getCurrency($breakdown['currency_id'], $db);
                ?>
                    <tr>
                        <td><?= escapeHtml(ucfirst($breakdown['payment_method'])) ?></td>
                        <td><?= escapeHtml($currency ? $currency['code'] : 'N/A') ?></td>
                        <td><?= $breakdown['transaction_count'] ?></td>
                        <td><?= formatCurrencyAmount($breakdown['total_original'], $breakdown['currency_id'], $db) ?></td>
                        <td><?= formatCurrencyAmount($breakdown['total_base'], $baseCurrency['id'], $db) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Daily Currency Trend -->
<?php if (!empty($dailyCurrencyBreakdown)): ?>
<div class="report-card">
    <h5 class="mb-3">Daily Currency Trend</h5>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Date</th>
                    <?php foreach ($currencies as $currency): ?>
                        <th><?= escapeHtml($currency['code']) ?> (Base)</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php 
                $dailyTotals = [];
                foreach ($dailyCurrencyBreakdown as $day) {
                    $date = $day['sale_day'];
                    if (!isset($dailyTotals[$date])) {
                        $dailyTotals[$date] = [];
                    }
                    $dailyTotals[$date][$day['currency_id']] = floatval($day['total_base']);
                }
                foreach ($dailyTotals as $date => $currencyTotals): ?>
                    <tr>
                        <td><strong><?= escapeHtml($date) ?></strong></td>
                        <?php foreach ($currencies as $currency): ?>
                            <td><?= formatCurrencyAmount($currencyTotals[$currency['id']] ?? 0, $baseCurrency['id'], $db) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

