<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.end_of_day_printout');

$pageTitle = 'End of Day Print Out Slip';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedShift = $_GET['shift_id'] ?? 'all';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) = :sale_date"];
$params = [':sale_date' => $selectedDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedShift !== 'all' && $selectedShift) {
    $whereConditions[] = "s.shift_id = :shift_id";
    $params[':shift_id'] = $selectedShift;
}

$whereClause = implode(' AND ', $whereConditions);

// Get shift info
$shiftInfo = $db->getRow("SELECT 
    sh.id,
    sh.shift_date,
    sh.opened_at,
    sh.closed_at,
    sh.opened_by,
    sh.closed_by,
    sh.expected_cash,
    sh.actual_cash,
    (sh.actual_cash - sh.expected_cash) as cash_difference,
    u1.first_name as opened_by_first,
    u1.last_name as opened_by_last,
    u2.first_name as closed_by_first,
    u2.last_name as closed_by_last
FROM shifts sh
LEFT JOIN users u1 ON sh.opened_by = u1.id
LEFT JOIN users u2 ON sh.closed_by = u2.id
WHERE DATE(sh.shift_date) = :date" . ($selectedBranch !== 'all' && $selectedBranch ? " AND sh.branch_id = :branch_id" : ""),
    array_merge([':date' => $selectedDate], ($selectedBranch !== 'all' && $selectedBranch ? [':branch_id' => $selectedBranch] : [])));

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_sales,
    COUNT(DISTINCT s.customer_id) as unique_customers,
    COUNT(DISTINCT s.user_id) as unique_cashiers,
    COALESCE(SUM(s.total_amount), 0) as gross_sales,
    COALESCE(SUM(s.discount_amount), 0) as total_discounts,
    COALESCE(SUM(s.tax_amount), 0) as total_tax,
    COALESCE(SUM(s.total_amount - s.discount_amount), 0) as net_sales,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
    COALESCE(SUM(s.total_amount - s.discount_amount) - SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as gross_profit
FROM sales s
LEFT JOIN sale_items si ON s.id = si.sale_id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_sales' => 0,
        'unique_customers' => 0,
        'unique_cashiers' => 0,
        'gross_sales' => 0,
        'total_discounts' => 0,
        'total_tax' => 0,
        'net_sales' => 0,
        'total_cost' => 0,
        'gross_profit' => 0
    ];
}

// Get payment method breakdown
$payments = $db->getRows("SELECT 
    sp.payment_method,
    COUNT(DISTINCT sp.sale_id) as transaction_count,
    COALESCE(SUM(sp.amount), 0) as total_amount
FROM sale_payments sp
INNER JOIN sales s ON sp.sale_id = s.id
WHERE $whereClause
GROUP BY sp.payment_method
ORDER BY total_amount DESC", $params);

if ($payments === false) {
    $payments = [];
}

// Get top products
$topProducts = $db->getRows("SELECT 
    p.product_code,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    SUM(si.quantity) as total_quantity,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as net_sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause
GROUP BY si.product_id, p.product_code, p.product_name, p.brand, p.model
ORDER BY net_sales DESC
LIMIT 10", $params);

if ($topProducts === false) {
    $topProducts = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $companyName = getSetting('company_name', SYSTEM_NAME);
    $html = '<h2 style="text-align: center; margin-bottom: 10px;">' . escapeHtml($companyName) . '</h2>';
    $html .= '<h3 style="text-align: center; margin-bottom: 20px; color: #666;">End of Day Report</h3>';
    $html .= '<p style="text-align: center; color: #666;">Date: ' . date('M d, Y', strtotime($selectedDate)) . '</p>';
    
    if ($shiftInfo) {
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Shift Information</th><th style="text-align: right;">Value</th></tr>';
        $html .= '<tr><td>Opened At</td><td style="text-align: right;">' . date('H:i:s', strtotime($shiftInfo['opened_at'])) . '</td></tr>';
        if ($shiftInfo['closed_at']) {
            $html .= '<tr><td>Closed At</td><td style="text-align: right;">' . date('H:i:s', strtotime($shiftInfo['closed_at'])) . '</td></tr>';
        }
        $html .= '<tr><td>Expected Cash</td><td style="text-align: right;">' . formatCurrency($shiftInfo['expected_cash']) . '</td></tr>';
        if ($shiftInfo['actual_cash'] !== null) {
            $html .= '<tr><td>Actual Cash</td><td style="text-align: right;">' . formatCurrency($shiftInfo['actual_cash']) . '</td></tr>';
            $html .= '<tr><td>Cash Difference</td><td style="text-align: right; color: ' . ($shiftInfo['cash_difference'] >= 0 ? '#10b981' : '#dc2626') . ';">' . formatCurrency($shiftInfo['cash_difference']) . '</td></tr>';
        }
        $html .= '</table>';
    }
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . $summary['total_sales'] . '</td></tr>';
    $html .= '<tr><td>Unique Customers</td><td style="text-align: right;">' . $summary['unique_customers'] . '</td></tr>';
    $html .= '<tr><td>Unique Cashiers</td><td style="text-align: right;">' . $summary['unique_cashiers'] . '</td></tr>';
    $html .= '<tr><td>Gross Sales</td><td style="text-align: right;">' . formatCurrency($summary['gross_sales']) . '</td></tr>';
    $html .= '<tr><td>Total Discounts</td><td style="text-align: right;">' . formatCurrency($summary['total_discounts']) . '</td></tr>';
    $html .= '<tr><td>Total Tax</td><td style="text-align: right;">' . formatCurrency($summary['total_tax']) . '</td></tr>';
    $html .= '<tr><td>Net Sales</td><td style="text-align: right;">' . formatCurrency($summary['net_sales']) . '</td></tr>';
    $html .= '<tr><td>Total Cost</td><td style="text-align: right;">' . formatCurrency($summary['total_cost']) . '</td></tr>';
    $html .= '<tr><td>Gross Profit</td><td style="text-align: right;">' . formatCurrency($summary['gross_profit']) . '</td></tr>';
    $html .= '</table>';
    
    if (!empty($payments)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Payment Methods</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Payment Method</th><th>Transactions</th><th style="text-align: right;">Total Amount</th></tr>';
        foreach ($payments as $payment) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml(ucfirst($payment['payment_method'])) . '</td>';
            $html .= '<td>' . $payment['transaction_count'] . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($payment['total_amount']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    if (!empty($topProducts)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Top 10 Products</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Product</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Net Sales</th></tr>';
        foreach ($topProducts as $product) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($product['product_code'] ?? 'N/A') . ' - ' . escapeHtml(substr($product['product_name'] ?? 'N/A', 0, 30)) . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($product['total_quantity'], 2) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($product['net_sales']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    $html .= '<p style="text-align: center; margin-top: 30px; color: #666; font-size: 10px;">Generated: ' . date('M d, Y H:i:s') . '</p>';
    
    ReportHelper::generatePDF('End of Day Report', $html, 'End_Of_Day_' . date('Ymd', strtotime($selectedDate)) . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-printer"></i> End of Day Print Out Slip</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-calendar"></i> Date</label>
                <input type="date" name="date" value="<?= $selectedDate ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Generate Report</button>
            </div>
        </form>
    </div>
</div>

<?php if ($shiftInfo): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-clock"></i> Shift Information</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <strong>Opened At:</strong><br>
                <?= date('H:i:s', strtotime($shiftInfo['opened_at'])) ?><br>
                <small class="text-muted">by <?= escapeHtml(trim(($shiftInfo['opened_by_first'] ?? '') . ' ' . ($shiftInfo['opened_by_last'] ?? ''))) ?></small>
            </div>
            <?php if ($shiftInfo['closed_at']): ?>
            <div class="col-md-3">
                <strong>Closed At:</strong><br>
                <?= date('H:i:s', strtotime($shiftInfo['closed_at'])) ?><br>
                <small class="text-muted">by <?= escapeHtml(trim(($shiftInfo['closed_by_first'] ?? '') . ' ' . ($shiftInfo['closed_by_last'] ?? ''))) ?></small>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <strong>Expected Cash:</strong><br>
                <span class="fw-bold"><?= formatCurrency($shiftInfo['expected_cash']) ?></span>
            </div>
            <?php if ($shiftInfo['actual_cash'] !== null): ?>
            <div class="col-md-3">
                <strong>Actual Cash:</strong><br>
                <span class="fw-bold"><?= formatCurrency($shiftInfo['actual_cash']) ?></span><br>
                <strong>Difference:</strong>
                <span class="fw-bold <?= $shiftInfo['cash_difference'] >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= formatCurrency($shiftInfo['cash_difference']) ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Sales</h6>
                <h3 class="mb-0"><?= $summary['total_sales'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Net Sales</h6>
                <h3 class="mb-0 text-success"><?= formatCurrency($summary['net_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Gross Profit</h6>
                <h3 class="mb-0 text-primary"><?= formatCurrency($summary['gross_profit']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Customers</h6>
                <h3 class="mb-0"><?= $summary['unique_customers'] ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-credit-card"></i> Payment Methods</h5>
            </div>
            <div class="card-body">
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
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted">No payments found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?= escapeHtml(ucfirst($payment['payment_method'])) ?></td>
                                        <td class="text-end"><?= $payment['transaction_count'] ?></td>
                                        <td class="text-end fw-bold"><?= formatCurrency($payment['total_amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-trophy"></i> Top 10 Products</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Net Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topProducts)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted">No products found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($topProducts as $product): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= escapeHtml($product['product_code'] ?? 'N/A') ?></div>
                                            <small class="text-muted"><?= escapeHtml(substr($product['product_name'] ?? 'N/A', 0, 30)) ?></small>
                                        </td>
                                        <td class="text-end"><?= number_format($product['total_quantity'], 2) ?></td>
                                        <td class="text-end fw-bold"><?= formatCurrency($product['net_sales']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h5 class="mb-3">Summary</h5>
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr>
                        <td><strong>Gross Sales:</strong></td>
                        <td class="text-end"><?= formatCurrency($summary['gross_sales']) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Discounts:</strong></td>
                        <td class="text-end"><?= formatCurrency($summary['total_discounts']) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Tax:</strong></td>
                        <td class="text-end"><?= formatCurrency($summary['total_tax']) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Net Sales:</strong></td>
                        <td class="text-end fw-bold text-success"><?= formatCurrency($summary['net_sales']) ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm">
                    <tr>
                        <td><strong>Total Cost:</strong></td>
                        <td class="text-end"><?= formatCurrency($summary['total_cost']) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Gross Profit:</strong></td>
                        <td class="text-end fw-bold text-primary"><?= formatCurrency($summary['gross_profit']) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Profit Margin:</strong></td>
                        <td class="text-end">
                            <?= $summary['net_sales'] > 0 ? number_format(($summary['gross_profit'] / $summary['net_sales']) * 100, 2) : '0.00' ?>%
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Unique Cashiers:</strong></td>
                        <td class="text-end"><?= $summary['unique_cashiers'] ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


