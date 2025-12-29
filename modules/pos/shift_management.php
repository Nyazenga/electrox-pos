<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('pos.shift_management');

$pageTitle = 'Shift Management';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get users
$users = $db->getRows("SELECT id, first_name, last_name FROM users WHERE status = 'Active' ORDER BY first_name, last_name");
if ($users === false) $users = [];

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedUser = $_GET['user_id'] ?? 'all';

// Build query conditions
$whereConditions = ["DATE(s.opened_at) BETWEEN :start_date AND :end_date"];
$params = [
    ':start_date' => $startDate,
    ':end_date' => $endDate
];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedUser !== 'all' && $selectedUser) {
    $whereConditions[] = "s.opened_by = :user_id";
    $params[':user_id'] = $selectedUser;
}

$whereClause = implode(' AND ', $whereConditions);

// Get shifts with statistics
$shifts = $db->getRows("SELECT 
                        s.*,
                        b.branch_name,
                        u1.first_name as opened_first,
                        u1.last_name as opened_last,
                        u2.first_name as closed_first,
                        u2.last_name as closed_last,
                        COUNT(DISTINCT sa.id) as total_sales,
                        COALESCE(SUM(DISTINCT sa.total_amount), 0) as total_amount,
                        COALESCE(SUM(DISTINCT sa.discount_amount), 0) as total_discount,
                        COALESCE(SUM(DISTINCT sa.tax_amount), 0) as total_tax
                        FROM shifts s
                        LEFT JOIN branches b ON s.branch_id = b.id
                        LEFT JOIN users u1 ON s.opened_by = u1.id
                        LEFT JOIN users u2 ON s.closed_by = u2.id
                        LEFT JOIN sales sa ON s.id = sa.shift_id
                        WHERE $whereClause
                        GROUP BY s.id
                        ORDER BY s.opened_at DESC
                        LIMIT 1000", $params);

if ($shifts === false) {
    $shifts = [];
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clock-history"></i> Shift Management</h2>
    <div>
        <a href="cash.php" class="btn btn-primary">
            <i class="bi bi-cash-stack"></i> Cash Management
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-funnel"></i> Filters</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" id="filterForm">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= escapeHtml($startDate) ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?= escapeHtml($endDate) ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="all">All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>>
                                <?= escapeHtml($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Opened By</label>
                    <select name="user_id" class="form-select">
                        <option value="all">All Users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>" <?= $selectedUser == $user['id'] ? 'selected' : '' ?>>
                                <?= escapeHtml(trim($user['first_name'] . ' ' . $user['last_name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Apply Filters
                    </button>
                    <a href="shift_management.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Shifts Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Shift Reports</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="shiftsTable">
                <thead>
                    <tr>
                        <th>Shift ID</th>
                        <th>Opened Date</th>
                        <th>Closed Date</th>
                        <th>Branch</th>
                        <th>Opened By</th>
                        <th>Closed By</th>
                        <th class="text-end">Total Sales</th>
                        <th class="text-end">Total Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shifts as $shift): 
                        $baseCurrency = getBaseCurrency($db);
                        $statusClass = $shift['closed_at'] ? 'success' : 'warning';
                        $statusText = $shift['closed_at'] ? 'Closed' : 'Open';
                    ?>
                        <tr>
                            <td>#<?= $shift['id'] ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($shift['opened_at'])) ?></td>
                            <td><?= $shift['closed_at'] ? date('d/m/Y H:i', strtotime($shift['closed_at'])) : '-' ?></td>
                            <td><?= escapeHtml($shift['branch_name'] ?? 'N/A') ?></td>
                            <td><?= escapeHtml(trim(($shift['opened_first'] ?? '') . ' ' . ($shift['opened_last'] ?? ''))) ?></td>
                            <td><?= $shift['closed_at'] ? escapeHtml(trim(($shift['closed_first'] ?? '') . ' ' . ($shift['closed_last'] ?? ''))) : '-' ?></td>
                            <td class="text-end"><?= number_format($shift['total_sales']) ?></td>
                            <td class="text-end"><?= formatCurrency($shift['total_amount'] ?? 0) ?></td>
                            <td>
                                <span class="badge bg-<?= $statusClass ?>"><?= $statusText ?></span>
                            </td>
                            <td>
                                <a href="shift_report.php?id=<?= $shift['id'] ?>" 
                                   target="_blank" 
                                   class="btn btn-sm btn-info" 
                                   title="View Report">
                                    <i class="bi bi-eye"></i> View
                                </a>
                                <a href="print_shift_report.php?id=<?= $shift['id'] ?>" 
                                   target="_blank" 
                                   class="btn btn-sm btn-primary" 
                                   title="Download PDF Report">
                                    <i class="bi bi-file-pdf"></i> PDF
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- DataTables Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

<!-- DataTables Buttons JS -->
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    $('#shiftsTable').DataTable({
        order: [[1, 'desc']],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        dom: 'Bfrtip',
        buttons: [
            'copy', 'csv', 'excel', 'pdf', 'print'
        ]
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

