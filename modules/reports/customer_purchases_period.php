<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.customer_purchases_period');

$pageTitle = 'Customer Purchases for Period';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCustomer = $_GET['customer_id'] ?? 'all';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get customers for filter
$customers = $db->getRows("SELECT DISTINCT c.* FROM customers c 
                          INNER JOIN sales s ON c.id = s.customer_id 
                          WHERE s.sale_date BETWEEN :start AND :end 
                          ORDER BY c.first_name, c.last_name", 
                          [':start' => $startDate, ':end' => $endDate]);
if ($customers === false) $customers = [];

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

if ($selectedCustomer !== 'all' && $selectedCustomer) {
    $whereConditions[] = "s.customer_id = :customer_id";
    $params[':customer_id'] = $selectedCustomer;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.customer_id) as unique_customers,
    COUNT(DISTINCT s.id) as total_transactions,
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(AVG(s.total_amount), 0) as avg_transaction
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'unique_customers' => 0,
        'total_transactions' => 0,
        'total_sales' => 0,
        'avg_transaction' => 0
    ];
}

// Get customer purchase details
$customerPurchases = $db->getRows("SELECT 
    c.id as customer_id,
    CONCAT(c.first_name, ' ', c.last_name) as customer_name,
    c.email,
    c.phone,
    COUNT(DISTINCT s.id) as transaction_count,
    COALESCE(SUM(s.total_amount), 0) as total_purchases,
    COALESCE(AVG(s.total_amount), 0) as avg_purchase,
    MIN(s.sale_date) as first_purchase,
    MAX(s.sale_date) as last_purchase
FROM sales s
INNER JOIN customers c ON s.customer_id = c.id
WHERE $whereClause
GROUP BY c.id, c.first_name, c.last_name, c.email, c.phone
ORDER BY total_purchases DESC
LIMIT 1000", $params);

if ($customerPurchases === false) {
    $customerPurchases = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Customer Purchases for Period</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Unique Customers</td><td style="text-align: right;">' . $summary['unique_customers'] . '</td></tr>';
    $html .= '<tr><td>Total Transactions</td><td style="text-align: right;">' . $summary['total_transactions'] . '</td></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_sales']) . '</td></tr>';
    $html .= '<tr><td>Average Transaction</td><td style="text-align: right;">' . formatCurrency($summary['avg_transaction']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Customer Purchase Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Customer Name</th><th>Email</th><th>Phone</th><th>Transactions</th><th style="text-align: right;">Total Purchases</th><th style="text-align: right;">Avg Purchase</th><th>First Purchase</th><th>Last Purchase</th></tr>';
    foreach ($customerPurchases as $purchase) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($purchase['customer_name']) . '</td>';
        $html .= '<td>' . escapeHtml($purchase['email'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($purchase['phone'] ?? 'N/A') . '</td>';
        $html .= '<td>' . $purchase['transaction_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($purchase['total_purchases']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($purchase['avg_purchase']) . '</td>';
        $html .= '<td>' . date('M d, Y', strtotime($purchase['first_purchase'])) . '</td>';
        $html .= '<td>' . date('M d, Y', strtotime($purchase['last_purchase'])) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Customer Purchases for Period', $html, 'Customer_Purchases_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-person-check"></i> Customer Purchases for Period</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> Start Date</label>
                <input type="date" name="start_date" value="<?= $startDate ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> End Date</label>
                <input type="date" name="end_date" value="<?= $endDate ?>" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-person"></i> Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="all" <?= $selectedCustomer === 'all' ? 'selected' : '' ?>>All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= $customer['id'] ?>" <?= $selectedCustomer == $customer['id'] ? 'selected' : '' ?>><?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Customers</h6>
                <h3 class="mb-0"><?= $summary['unique_customers'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Transactions</h6>
                <h3 class="mb-0"><?= $summary['total_transactions'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Avg Transaction</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['avg_transaction']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="customerPurchasesTable">
                <thead>
                    <tr>
                        <th>Customer Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th class="text-end">Transactions</th>
                        <th class="text-end">Total Purchases</th>
                        <th class="text-end">Avg Purchase</th>
                        <th>First Purchase</th>
                        <th>Last Purchase</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customerPurchases)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No customer purchases found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customerPurchases as $purchase): ?>
                            <tr>
                                <td><?= escapeHtml($purchase['customer_name']) ?></td>
                                <td><?= escapeHtml($purchase['email'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($purchase['phone'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $purchase['transaction_count'] ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($purchase['total_purchases']) ?></td>
                                <td class="text-end"><?= formatCurrency($purchase['avg_purchase']) ?></td>
                                <td><?= date('M d, Y', strtotime($purchase['first_purchase'])) ?></td>
                                <td><?= date('M d, Y', strtotime($purchase['last_purchase'])) ?></td>
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
    $('#customerPurchasesTable').DataTable({
        order: [[4, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

