<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.sales_period_by_user');

$pageTitle = 'Sales for Period by User';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedUser = $_GET['user_id'] ?? 'all';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get users
$userWhere = "u.status = 'Active'";
$userParams = [];
if ($selectedBranch !== 'all' && $selectedBranch) {
    $userWhere .= " AND EXISTS (SELECT 1 FROM sales s WHERE s.user_id = u.id AND s.branch_id = :branch_id)";
    $userParams[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $userWhere .= " AND EXISTS (SELECT 1 FROM sales s WHERE s.user_id = u.id AND s.branch_id = :branch_id)";
    $userParams[':branch_id'] = $branchId;
}
$users = $db->getRows("SELECT DISTINCT u.* FROM users u WHERE $userWhere ORDER BY u.first_name, u.last_name", $userParams);
if ($users === false) $users = [];

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

if ($selectedUser !== 'all' && $selectedUser) {
    $whereConditions[] = "s.user_id = :user_id";
    $params[':user_id'] = $selectedUser;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_sales,
    COUNT(DISTINCT s.user_id) as unique_users,
    COALESCE(SUM(s.total_amount), 0) as gross_sales,
    COALESCE(SUM(s.discount_amount), 0) as total_discounts,
    COALESCE(SUM(s.total_amount - s.discount_amount), 0) as net_sales
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_sales' => 0,
        'unique_users' => 0,
        'gross_sales' => 0,
        'total_discounts' => 0,
        'net_sales' => 0
    ];
}

// Get sales by user
$salesByUser = $db->getRows("SELECT 
    u.id as user_id,
    u.first_name,
    u.last_name,
    u.email,
    COUNT(DISTINCT s.id) as sale_count,
    COALESCE(SUM(s.total_amount), 0) as gross_sales,
    COALESCE(SUM(s.discount_amount), 0) as total_discounts,
    COALESCE(SUM(s.total_amount - s.discount_amount), 0) as net_sales,
    COALESCE(AVG(s.total_amount - s.discount_amount), 0) as avg_sale,
    COUNT(DISTINCT s.branch_id) as branch_count
FROM sales s
LEFT JOIN users u ON s.user_id = u.id
WHERE $whereClause
GROUP BY u.id, u.first_name, u.last_name, u.email
ORDER BY net_sales DESC", $params);

if ($salesByUser === false) {
    $salesByUser = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales for Period by User</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . $summary['total_sales'] . '</td></tr>';
    $html .= '<tr><td>Unique Users</td><td style="text-align: right;">' . $summary['unique_users'] . '</td></tr>';
    $html .= '<tr><td>Gross Sales</td><td style="text-align: right;">' . formatCurrency($summary['gross_sales']) . '</td></tr>';
    $html .= '<tr><td>Total Discounts</td><td style="text-align: right;">' . formatCurrency($summary['total_discounts']) . '</td></tr>';
    $html .= '<tr><td>Net Sales</td><td style="text-align: right;">' . formatCurrency($summary['net_sales']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Sales by User</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>User</th><th>Email</th><th>Sales Count</th><th>Branches</th><th style="text-align: right;">Gross Sales</th><th style="text-align: right;">Discounts</th><th style="text-align: right;">Net Sales</th><th style="text-align: right;">Avg Sale</th></tr>';
    foreach ($salesByUser as $user) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) . '</td>';
        $html .= '<td>' . escapeHtml($user['email'] ?? 'N/A') . '</td>';
        $html .= '<td>' . $user['sale_count'] . '</td>';
        $html .= '<td>' . $user['branch_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($user['gross_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($user['total_discounts']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($user['net_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($user['avg_sale']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales for Period by User', $html, 'Sales_Period_By_User_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-people"></i> Sales for Period by User</h2>
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
                <label class="form-label"><i class="bi bi-person"></i> User</label>
                <select name="user_id" class="form-select">
                    <option value="all" <?= $selectedUser === 'all' ? 'selected' : '' ?>>All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $selectedUser == $user['id'] ? 'selected' : '' ?>>
                            <?= escapeHtml(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-12 d-flex align-items-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

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
                <h6 class="text-muted mb-2">Unique Users</h6>
                <h3 class="mb-0"><?= $summary['unique_users'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Gross Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['gross_sales']) ?></h3>
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
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="salesByUserTable">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th class="text-end">Sales Count</th>
                        <th class="text-end">Branches</th>
                        <th class="text-end">Gross Sales</th>
                        <th class="text-end">Discounts</th>
                        <th class="text-end">Net Sales</th>
                        <th class="text-end">Avg Sale</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salesByUser)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No sales found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($salesByUser as $user): ?>
                            <tr>
                                <td><span class="fw-bold"><?= escapeHtml(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></span></td>
                                <td><?= escapeHtml($user['email'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $user['sale_count'] ?></td>
                                <td class="text-end"><?= $user['branch_count'] ?></td>
                                <td class="text-end"><?= formatCurrency($user['gross_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($user['total_discounts']) ?></td>
                                <td class="text-end fw-bold text-success"><?= formatCurrency($user['net_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($user['avg_sale']) ?></td>
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
    $('#salesByUserTable').DataTable({
        order: [[6, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


