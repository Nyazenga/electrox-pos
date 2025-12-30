<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.sales_day_by_user');

$pageTitle = 'Sales for Day by User';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedUser = $_GET['user_id'] ?? 'all';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get users for filter
$users = $db->getRows("SELECT DISTINCT u.* FROM users u 
                      INNER JOIN sales s ON u.id = s.user_id 
                      WHERE DATE(s.sale_date) = :date 
                      ORDER BY u.first_name, u.last_name", 
                      [':date' => $selectedDate]);
if ($users === false) $users = [];

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

if ($selectedUser !== 'all' && $selectedUser) {
    $whereConditions[] = "s.user_id = :user_id";
    $params[':user_id'] = $selectedUser;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_receipts,
    COUNT(DISTINCT s.user_id) as unique_users,
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(AVG(s.total_amount), 0) as avg_receipt,
    COALESCE(SUM(s.discount_amount), 0) as total_discount
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_receipts' => 0,
        'unique_users' => 0,
        'total_sales' => 0,
        'avg_receipt' => 0,
        'total_discount' => 0
    ];
}

// Get sales by user
$salesByUser = $db->getRows("SELECT 
    u.id as user_id,
    CONCAT(u.first_name, ' ', u.last_name) as user_name,
    u.email,
    COUNT(DISTINCT s.id) as receipt_count,
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(AVG(s.total_amount), 0) as avg_receipt,
    COALESCE(SUM(s.discount_amount), 0) as total_discount,
    MIN(s.sale_date) as first_sale,
    MAX(s.sale_date) as last_sale
FROM sales s
INNER JOIN users u ON s.user_id = u.id
WHERE $whereClause
GROUP BY u.id, u.first_name, u.last_name, u.email
ORDER BY total_sales DESC", $params);

if ($salesByUser === false) {
    $salesByUser = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales for Day by User</h2>';
    $html .= '<p style="text-align: center; color: #666;">Date: ' . date('M d, Y', strtotime($selectedDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Receipts</td><td style="text-align: right;">' . $summary['total_receipts'] . '</td></tr>';
    $html .= '<tr><td>Unique Users</td><td style="text-align: right;">' . $summary['unique_users'] . '</td></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_sales']) . '</td></tr>';
    $html .= '<tr><td>Average Receipt</td><td style="text-align: right;">' . formatCurrency($summary['avg_receipt']) . '</td></tr>';
    $html .= '<tr><td>Total Discount</td><td style="text-align: right;">' . formatCurrency($summary['total_discount']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Sales by User</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>User Name</th><th>Email</th><th>Receipts</th><th style="text-align: right;">Total Sales</th><th style="text-align: right;">Avg Receipt</th><th style="text-align: right;">Discount</th><th>First Sale</th><th>Last Sale</th></tr>';
    foreach ($salesByUser as $user) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($user['user_name']) . '</td>';
        $html .= '<td>' . escapeHtml($user['email'] ?? 'N/A') . '</td>';
        $html .= '<td>' . $user['receipt_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($user['total_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($user['avg_receipt']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($user['total_discount']) . '</td>';
        $html .= '<td>' . date('H:i', strtotime($user['first_sale'])) . '</td>';
        $html .= '<td>' . date('H:i', strtotime($user['last_sale'])) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales for Day by User', $html, 'Sales_Day_By_User_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-calendar-day"></i> Sales for Day by User</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> Date</label>
                <input type="date" name="date" value="<?= $selectedDate ?>" class="form-control">
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
                <label class="form-label"><i class="bi bi-person-badge"></i> User</label>
                <select name="user_id" class="form-select">
                    <option value="all" <?= $selectedUser === 'all' ? 'selected' : '' ?>>All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $selectedUser == $user['id'] ? 'selected' : '' ?>><?= escapeHtml($user['first_name'] . ' ' . $user['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Receipts</h6>
                <h3 class="mb-0"><?= $summary['total_receipts'] ?></h3>
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
                <h6 class="text-muted mb-2">Total Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Avg Receipt</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['avg_receipt']) ?></h3>
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
                        <th>User Name</th>
                        <th>Email</th>
                        <th class="text-end">Receipts</th>
                        <th class="text-end">Total Sales</th>
                        <th class="text-end">Avg Receipt</th>
                        <th class="text-end">Discount</th>
                        <th>First Sale</th>
                        <th>Last Sale</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salesByUser)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No sales found for the selected date</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($salesByUser as $user): ?>
                            <tr>
                                <td><?= escapeHtml($user['user_name']) ?></td>
                                <td><?= escapeHtml($user['email'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $user['receipt_count'] ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($user['total_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($user['avg_receipt']) ?></td>
                                <td class="text-end text-danger"><?= formatCurrency($user['total_discount']) ?></td>
                                <td><?= date('H:i', strtotime($user['first_sale'])) ?></td>
                                <td><?= date('H:i', strtotime($user['last_sale'])) ?></td>
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
        order: [[3, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


