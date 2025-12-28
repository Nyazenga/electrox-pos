<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.current_customers_branch');

$pageTitle = 'Current Customers List for Branch';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$search = $_GET['search'] ?? '';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query conditions
$whereConditions = ["c.status = 'Active'"];
$params = [];

if ($selectedBranch !== 'all' && $selectedBranch) {
    // Get customers who have made purchases at this branch
    $whereConditions[] = "EXISTS (SELECT 1 FROM sales s WHERE s.customer_id = c.id AND s.branch_id = :branch_id)";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "EXISTS (SELECT 1 FROM sales s WHERE s.customer_id = c.id AND s.branch_id = :branch_id)";
    $params[':branch_id'] = $branchId;
}

if (!empty($search)) {
    $whereConditions[] = "(c.first_name LIKE :search OR c.last_name LIKE :search OR c.email LIKE :search OR c.phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT c.id) as total_customers,
    COUNT(DISTINCT CASE WHEN EXISTS (SELECT 1 FROM sales s WHERE s.customer_id = c.id AND DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) THEN c.id END) as active_30_days,
    COUNT(DISTINCT CASE WHEN EXISTS (SELECT 1 FROM sales s WHERE s.customer_id = c.id AND DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) THEN c.id END) as active_90_days
FROM customers c
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_customers' => 0,
        'active_30_days' => 0,
        'active_90_days' => 0
    ];
}

// Get customers
$customers = $db->getRows("SELECT 
    c.*,
    COUNT(DISTINCT s.id) as total_purchases,
    COALESCE(SUM(s.total_amount), 0) as total_spent,
    MAX(s.sale_date) as last_purchase_date
FROM customers c
LEFT JOIN sales s ON c.id = s.customer_id
WHERE $whereClause
GROUP BY c.id
ORDER BY c.first_name, c.last_name
LIMIT 1000", $params);

if ($customers === false) {
    $customers = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Current Customers List for Branch</h2>';
    $branchName = $selectedBranch !== 'all' && $selectedBranch ? 
        ($db->getRow("SELECT branch_name FROM branches WHERE id = :id", [':id' => $selectedBranch])['branch_name'] ?? 'All Branches') : 
        'All Branches';
    $html .= '<p style="text-align: center; color: #666;">Branch: ' . escapeHtml($branchName) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Customers</td><td style="text-align: right;">' . $summary['total_customers'] . '</td></tr>';
    $html .= '<tr><td>Active (30 days)</td><td style="text-align: right;">' . $summary['active_30_days'] . '</td></tr>';
    $html .= '<tr><td>Active (90 days)</td><td style="text-align: right;">' . $summary['active_90_days'] . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Customers</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Name</th><th>Email</th><th>Phone</th><th>Purchases</th><th style="text-align: right;">Total Spent</th><th>Last Purchase</th></tr>';
    foreach ($customers as $customer) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) . '</td>';
        $html .= '<td>' . escapeHtml($customer['email'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($customer['phone'] ?? 'N/A') . '</td>';
        $html .= '<td>' . $customer['total_purchases'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($customer['total_spent']) . '</td>';
        $html .= '<td>' . ($customer['last_purchase_date'] ? date('M d, Y', strtotime($customer['last_purchase_date'])) : 'Never') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Current Customers List for Branch', $html, 'Current_Customers_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-people"></i> Current Customers List for Branch</h2>
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
            <div class="col-md-6">
                <label class="form-label"><i class="bi bi-search"></i> Search</label>
                <input type="text" name="search" value="<?= escapeHtml($search) ?>" class="form-control" placeholder="Search by name, email, or phone">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Customers</h6>
                <h3 class="mb-0"><?= $summary['total_customers'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Active (30 days)</h6>
                <h3 class="mb-0 text-success"><?= $summary['active_30_days'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Active (90 days)</h6>
                <h3 class="mb-0 text-info"><?= $summary['active_90_days'] ?></h3>
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
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th class="text-end">Purchases</th>
                        <th class="text-end">Total Spent</th>
                        <th>Last Purchase</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No customers found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><?= escapeHtml(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?></td>
                                <td><?= escapeHtml($customer['email'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($customer['phone'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $customer['total_purchases'] ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($customer['total_spent']) ?></td>
                                <td><?= $customer['last_purchase_date'] ? date('M d, Y', strtotime($customer['last_purchase_date'])) : 'Never' ?></td>
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

