<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('sales.view');

$pageTitle = 'Sales';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';

// Get branches for filter
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($status !== 'all') {
    $whereConditions[] = "s.payment_status = :status";
    $params[':status'] = $status;
}

if ($search) {
    $whereConditions[] = "(s.receipt_number LIKE :search OR c.first_name LIKE :search OR c.last_name LIKE :search)";
    $params[':search'] = "%$search%";
}

$whereClause = implode(' AND ', $whereConditions);

$sales = $db->getRows("SELECT s.*, 
    c.first_name, c.last_name, 
    u.first_name as cashier_first, u.last_name as cashier_last,
    b.branch_name
    FROM sales s 
    LEFT JOIN customers c ON s.customer_id = c.id 
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN branches b ON s.branch_id = b.id
    WHERE $whereClause
    ORDER BY s.sale_date DESC
    LIMIT 500", $params);

if ($sales === false) $sales = [];

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

.sales-table {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Sales</h2>
    <a href="dashboard.php" class="btn btn-outline-primary"><i class="bi bi-graph-up"></i> Dashboard</a>
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
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="refunded" <?= $status === 'refunded' ? 'selected' : '' ?>>Refunded</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control" placeholder="Receipt #, Customer..." value="<?= escapeHtml($search) ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
        </div>
    </form>
</div>

<!-- Sales Table -->
<div class="sales-table">
    <div class="table-responsive">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Cashier</th>
                    <th>Branch</th>
                    <th>Subtotal</th>
                    <th>Discount</th>
                    <th>Tax</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales as $sale): ?>
                    <tr>
                        <td><strong><?= escapeHtml($sale['receipt_number']) ?></strong></td>
                        <td><?= formatDateTime($sale['sale_date']) ?></td>
                        <td><?= escapeHtml(trim(($sale['first_name'] ?? '') . ' ' . ($sale['last_name'] ?? 'Walk-in'))) ?></td>
                        <td><?= escapeHtml(trim(($sale['cashier_first'] ?? '') . ' ' . ($sale['cashier_last'] ?? ''))) ?></td>
                        <td><?= escapeHtml($sale['branch_name'] ?? 'N/A') ?></td>
                        <td><?= formatCurrency($sale['subtotal']) ?></td>
                        <td><?= $sale['discount_amount'] > 0 ? formatCurrency($sale['discount_amount']) : '-' ?></td>
                        <td><?= $sale['tax_amount'] > 0 ? formatCurrency($sale['tax_amount']) : '-' ?></td>
                        <td><strong><?= formatCurrency($sale['total_amount']) ?></strong></td>
                        <td>
                            <?php if ($sale['payment_status'] === 'refunded'): ?>
                                <span class="badge bg-danger">Refunded</span>
                            <?php elseif ($sale['payment_status'] === 'pending'): ?>
                                <span class="badge bg-warning">Pending</span>
                            <?php else: ?>
                                <span class="badge bg-success">Paid</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="<?= BASE_URL ?>modules/pos/receipt.php?id=<?= $sale['id'] ?>" 
                                   class="btn btn-info" 
                                   title="View Receipt">
                                    <i class="bi bi-receipt"></i>
                                </a>
                                <?php if ($sale['payment_status'] === 'paid' && $auth->hasPermission('sales.refund')): ?>
                                    <button onclick="showRefundModal(<?= $sale['id'] ?>)" 
                                            class="btn btn-warning" 
                                            title="Refund">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Refund Modal -->
<div class="modal fade" id="refundModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Process Refund</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="refundModalBody">
                <p>Loading...</p>
            </div>
        </div>
    </div>
</div>

<script>
function showRefundModal(saleId) {
    fetch('<?= BASE_URL ?>ajax/get_sale_for_refund.php?id=' + saleId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const sale = data.sale;
                let html = `
                    <form id="refundForm">
                        <input type="hidden" name="sale_id" value="${sale.id}">
                        <div class="mb-3">
                            <label class="form-label">Receipt #</label>
                            <input type="text" class="form-control" value="${sale.receipt_number}" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Total Amount</label>
                            <input type="text" class="form-control" value="${sale.total_amount}" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Refund Reason</label>
                            <textarea name="reason" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="alert alert-info">
                            <strong>Note:</strong> This will restore stock and adjust cash drawer.
                        </div>
                    </form>
                `;
                document.getElementById('refundModalBody').innerHTML = html;
                new bootstrap.Modal(document.getElementById('refundModal')).show();
            } else {
                Swal.fire('Error', data.message || 'Failed to load sale details', 'error');
            }
        });
}

// Handle refund form submission
document.addEventListener('DOMContentLoaded', function() {
    const refundModal = document.getElementById('refundModal');
    if (refundModal) {
        refundModal.addEventListener('submit', function(e) {
            if (e.target.id === 'refundForm') {
                e.preventDefault();
                const formData = new FormData(e.target);
                
                Swal.fire({
                    title: 'Processing Refund...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });
                
                fetch('<?= BASE_URL ?>ajax/process_refund.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(Object.fromEntries(formData))
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Success', 'Refund processed successfully', 'success').then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Failed to process refund', 'error');
                    }
                });
            }
        });
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


