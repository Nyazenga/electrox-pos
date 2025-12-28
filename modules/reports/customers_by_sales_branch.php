<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.customers_by_sales_branch');

$pageTitle = 'Customers List by Sales for Branch';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$sortBy = $_GET['sort_by'] ?? 'total_sales'; // 'total_sales', 'transaction_count', 'last_purchase'
$search = $_GET['search'] ?? '';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query conditions
$whereConditions = [];
$params = [];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if (!empty($search)) {
    $whereConditions[] = "(c.first_name LIKE :search OR c.last_name LIKE :search OR c.email LIKE :search OR c.phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT c.id) as total_customers,
    COUNT(DISTINCT s.id) as total_transactions,
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(AVG(s.total_amount), 0) as avg_transaction
FROM customers c
INNER JOIN sales s ON c.id = s.customer_id
$whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_customers' => 0,
        'total_transactions' => 0,
        'total_sales' => 0,
        'avg_transaction' => 0
    ];
}

// Determine sort order
$orderBy = 'total_sales DESC';
if ($sortBy === 'transaction_count') {
    $orderBy = 'transaction_count DESC';
} elseif ($sortBy === 'last_purchase') {
    $orderBy = 'last_purchase DESC';
}

// Get customers by sales
$customers = $db->getRows("SELECT 
    c.id,
    CONCAT(c.first_name, ' ', c.last_name) as customer_name,
    c.email,
    c.phone,
    COUNT(DISTINCT s.id) as transaction_count,
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(AVG(s.total_amount), 0) as avg_transaction,
    MIN(s.sale_date) as first_purchase,
    MAX(s.sale_date) as last_purchase
FROM customers c
INNER JOIN sales s ON c.id = s.customer_id
$whereClause
GROUP BY c.id, c.first_name, c.last_name, c.email, c.phone
ORDER BY $orderBy
LIMIT 1000", $params);

if ($customers === false) {
    $customers = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Customers List by Sales for Branch</h2>';
    $branchName = $selectedBranch !== 'all' && $selectedBranch ? 
        ($db->getRow("SELECT branch_name FROM branches WHERE id = :id", [':id' => $selectedBranch])['branch_name'] ?? 'All Branches') : 
        'All Branches';
    $html .= '<p style="text-align: center; color: #666;">Branch: ' . escapeHtml($branchName) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Customers</td><td style="text-align: right;">' . $summary['total_customers'] . '</td></tr>';
    $html .= '<tr><td>Total Transactions</td><td style="text-align: right;">' . $summary['total_transactions'] . '</td></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_sales']) . '</td></tr>';
    $html .= '<tr><td>Average Transaction</td><td style="text-align: right;">' . formatCurrency($summary['avg_transaction']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Customers by Sales</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Customer Name</th><th>Email</th><th>Phone</th><th>Transactions</th><th style="text-align: right;">Total Sales</th><th style="text-align: right;">Avg Transaction</th><th>First Purchase</th><th>Last Purchase</th></tr>';
    foreach ($customers as $customer) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($customer['customer_name']) . '</td>';
        $html .= '<td>' . escapeHtml($customer['email'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($customer['phone'] ?? 'N/A') . '</td>';
        $html .= '<td>' . $customer['transaction_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($customer['total_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($customer['avg_transaction']) . '</td>';
        $html .= '<td>' . ($customer['first_purchase'] ? date('M d, Y', strtotime($customer['first_purchase'])) : 'N/A') . '</td>';
        $html .= '<td>' . ($customer['last_purchase'] ? date('M d, Y', strtotime($customer['last_purchase'])) : 'N/A') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Customers List by Sales for Branch', $html, 'Customers_By_Sales_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-person-lines-fill"></i> Customers List by Sales for Branch</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-sort-down"></i> Sort By</label>
                <select name="sort_by" class="form-select">
                    <option value="total_sales" <?= $sortBy === 'total_sales' ? 'selected' : '' ?>>Total Sales</option>
                    <option value="transaction_count" <?= $sortBy === 'transaction_count' ? 'selected' : '' ?>>Transaction Count</option>
                    <option value="last_purchase" <?= $sortBy === 'last_purchase' ? 'selected' : '' ?>>Last Purchase</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-search"></i> Search</label>
                <input type="text" name="search" value="<?= escapeHtml($search) ?>" class="form-control" placeholder="Search customers...">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Customers</h6>
                <h3 class="mb-0"><?= $summary['total_customers'] ?></h3>
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
            <table class="table table-striped table-hover" id="customersTable">
                <thead>
                    <tr>
                        <th>Customer Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th class="text-end">Transactions</th>
                        <th class="text-end">Total Sales</th>
                        <th class="text-end">Avg Transaction</th>
                        <th>First Purchase</th>
                        <th>Last Purchase</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No customers found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><?= escapeHtml($customer['customer_name']) ?></td>
                                <td><?= escapeHtml($customer['email'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($customer['phone'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $customer['transaction_count'] ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($customer['total_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($customer['avg_transaction']) ?></td>
                                <td><?= $customer['first_purchase'] ? date('M d, Y', strtotime($customer['first_purchase'])) : 'N/A' ?></td>
                                <td><?= $customer['last_purchase'] ? date('M d, Y', strtotime($customer['last_purchase'])) : 'N/A' ?></td>
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
    $('#customersTable').DataTable({
        order: [[4, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

