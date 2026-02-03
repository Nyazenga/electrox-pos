<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('sales.laybyes');

$pageTitle = 'Laybyes';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$statusFilter = $_GET['status'] ?? 'all'; // 'all', 'pending', 'in_progress', 'completed', 'cancelled'
$laybyeNumberFilter = trim($_GET['laybye_number'] ?? '');

// Get branches
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query conditions
$whereConditions = ["1=1"];
$params = [];

// By default, only show unsettled laybyes (not completed or cancelled)
// Unless user explicitly filters by status
if ($statusFilter === 'all') {
    $whereConditions[] = "l.status NOT IN ('completed', 'cancelled')";
} elseif ($statusFilter !== 'all') {
    $whereConditions[] = "l.status = :status";
    $params[':status'] = $statusFilter;
} else {
    // Default: exclude completed and cancelled
    $whereConditions[] = "l.status NOT IN ('completed', 'cancelled')";
}

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "l.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "l.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if (!empty($laybyeNumberFilter)) {
    $whereConditions[] = "l.laybye_number LIKE :laybye_number";
    $params[':laybye_number'] = '%' . $laybyeNumberFilter . '%';
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $primaryDb->getRow("SELECT 
    COUNT(*) as total_laybyes,
    COUNT(CASE WHEN l.status = 'pending' THEN 1 END) as pending_laybyes,
    COUNT(CASE WHEN l.status = 'in_progress' THEN 1 END) as in_progress_laybyes,
    COUNT(CASE WHEN l.status = 'completed' THEN 1 END) as completed_laybyes,
    COUNT(CASE WHEN l.status = 'cancelled' THEN 1 END) as cancelled_laybyes,
    COALESCE(SUM(l.total_amount), 0) as total_laybye_amount,
    COALESCE(SUM(l.amount_paid), 0) as total_amount_paid,
    COALESCE(SUM(l.amount_remaining), 0) as total_amount_remaining
FROM laybyes l
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_laybyes' => 0,
        'pending_laybyes' => 0,
        'in_progress_laybyes' => 0,
        'completed_laybyes' => 0,
        'cancelled_laybyes' => 0,
        'total_laybye_amount' => 0,
        'total_amount_paid' => 0,
        'total_amount_remaining' => 0
    ];
}

// Get laybyes
$laybyes = $primaryDb->getRows("SELECT 
    l.*,
    CONCAT(c.first_name, ' ', c.last_name) as customer_name,
    c.phone as customer_phone,
    c.email as customer_email,
    b.branch_name,
    CONCAT(u.first_name, ' ', u.last_name) as created_by_name
FROM laybyes l
LEFT JOIN customers c ON l.customer_id = c.id
LEFT JOIN branches b ON l.branch_id = b.id
LEFT JOIN users u ON l.user_id = u.id
WHERE $whereClause
ORDER BY l.created_at DESC
LIMIT 1000", $params);

if ($laybyes === false) {
    $laybyes = [];
}

// Get base currency for formatting
$baseCurrency = getBaseCurrency($db);

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-bag-check"></i> Laybyes</h2>
    <?php if ($auth->hasPermission('sales.laybyes.create')): ?>
    <a href="<?= BASE_URL ?>modules/sales/laybye_add.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> New Laybye
    </a>
    <?php endif; ?>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Total Laybyes</h6>
                <h3 class="mb-0"><?= number_format($summary['total_laybyes']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-2">In Progress</h6>
                <h3 class="mb-0 text-warning"><?= number_format($summary['in_progress_laybyes']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Total Amount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_laybye_amount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Amount Remaining</h6>
                <h3 class="mb-0 text-danger"><?= formatCurrency($summary['total_amount_remaining']) ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
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
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Laybye Number</label>
                <input type="text" name="laybye_number" class="form-control" value="<?= escapeHtml($laybyeNumberFilter) ?>" placeholder="Search by laybye number">
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Filter
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Laybyes Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Laybye Number</th>
                        <th>Customer</th>
                        <th>Total Amount</th>
                        <th>Amount Paid</th>
                        <th>Amount Remaining</th>
                        <th>Status</th>
                        <th>Next Payment</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($laybyes)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No laybyes found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($laybyes as $laybye): ?>
                            <?php
                            $statusBadge = [
                                'pending' => 'bg-info text-white',
                                'in_progress' => 'bg-warning text-dark',
                                'completed' => 'bg-success text-white',
                                'cancelled' => 'bg-danger text-white'
                            ];
                            $statusClass = $statusBadge[$laybye['status']] ?? 'bg-secondary text-white';
                            ?>
                            <tr>
                                <td><strong><?= escapeHtml($laybye['laybye_number']) ?></strong></td>
                                <td>
                                    <?= escapeHtml($laybye['customer_name'] ?? 'N/A') ?><br>
                                    <small class="text-muted"><?= escapeHtml($laybye['customer_phone'] ?? '') ?></small>
                                </td>
                                <td><?= formatCurrency($laybye['total_amount']) ?></td>
                                <td><?= formatCurrency($laybye['amount_paid']) ?></td>
                                <td><strong class="text-danger"><?= formatCurrency($laybye['amount_remaining']) ?></strong></td>
                                <td><span class="badge <?= $statusClass ?>"><?= ucfirst(str_replace('_', ' ', $laybye['status'])) ?></span></td>
                                <td><?= $laybye['next_payment_date'] ? date('M d, Y', strtotime($laybye['next_payment_date'])) : 'N/A' ?></td>
                                <td><?= date('M d, Y', strtotime($laybye['created_at'])) ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= BASE_URL ?>modules/sales/laybye_view.php?id=<?= $laybye['id'] ?>" class="btn btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($laybye['status'] !== 'completed' && $laybye['status'] !== 'cancelled'): ?>
                                            <?php if ($auth->hasPermission('sales.laybyes.add_payment')): ?>
                                                <button type="button" class="btn btn-outline-success" onclick="addPayment(<?= $laybye['id'] ?>)" title="Add Payment">
                                                    <i class="bi bi-cash-coin"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($laybye['amount_remaining'] <= 0 && $auth->hasPermission('sales.laybyes.complete')): ?>
                                                <button type="button" class="btn btn-outline-primary" onclick="completeLaybye(<?= $laybye['id'] ?>)" title="Complete">
                                                    <i class="bi bi-check-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function addPayment(laybyeId) {
    window.location.href = '<?= BASE_URL ?>modules/sales/laybye_view.php?id=' + laybyeId + '&action=add_payment';
}

function completeLaybye(laybyeId) {
    Swal.fire({
        title: 'Complete Laybye?',
        text: 'This will create a sale and allow you to select specific product items. Continue?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Complete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Preparing Laybye...',
                text: 'Redirecting to POS...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('<?= BASE_URL ?>ajax/complete_laybye.php?id=' + laybyeId)
                .then(async response => {
                    const text = await response.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        console.error('Response text:', text);
                        throw new Error('Invalid JSON response: ' + text.substring(0, 200));
                    }
                })
                .then(data => {
                    if (data.success) {
                        // Redirect to POS payment page to select specific items and process sale
                        if (data.redirect_url) {
                            window.location.href = data.redirect_url;
                        } else {
                            window.location.href = '<?= BASE_URL ?>modules/pos/payment.php?from_laybye=1&laybye_id=' + laybyeId;
                        }
                    } else {
                        Swal.fire({
                            title: 'Error',
                            text: data.message || 'Failed to complete laybye',
                            icon: 'error',
                            width: '600px'
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        title: 'Error',
                        text: 'An error occurred while completing laybye: ' + error.message,
                        icon: 'error',
                        width: '600px'
                    });
                });
        }
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
