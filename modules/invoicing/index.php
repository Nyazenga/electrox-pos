<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('invoices.view');

$pageTitle = 'Invoices';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$invoiceType = $_GET['type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Get branches for filter
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query
$whereConditions = ["DATE(i.invoice_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "i.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "i.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($invoiceType !== 'all') {
    $whereConditions[] = "i.invoice_type = :type";
    $params[':type'] = $invoiceType;
}

if ($status !== 'all') {
    $whereConditions[] = "i.status = :status";
    $params[':status'] = $status;
}

if ($search) {
    $whereConditions[] = "(i.invoice_number LIKE :search1 OR c.first_name LIKE :search2 OR c.last_name LIKE :search3 OR c.company_name LIKE :search4)";
    $searchTerm = "%$search%";
    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    $params[':search3'] = $searchTerm;
    $params[':search4'] = $searchTerm;
}

$whereClause = implode(' AND ', $whereConditions);

$invoices = $db->getRows("SELECT i.*, c.first_name, c.last_name, b.branch_name 
    FROM invoices i 
    LEFT JOIN customers c ON i.customer_id = c.id 
    LEFT JOIN branches b ON i.branch_id = b.id 
    WHERE $whereClause
    ORDER BY i.created_at DESC
    LIMIT 500", $params);

if ($invoices === false) $invoices = [];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Invoices</h2>
    <div class="d-flex gap-2 align-items-center">
        <select id="invoiceTypeSelect" class="form-select" style="width: auto;">
            <option value="proforma">Proforma Invoice</option>
            <option value="tax">Tax Invoice</option>
            <option value="quote">Quote</option>
            <option value="credit">Credit Note</option>
        </select>
        <button type="button" class="btn btn-primary" onclick="createInvoice()">
            <i class="bi bi-plus-circle"></i> Create Invoice
        </button>
    </div>
</div>

<script>
function createInvoice() {
    const type = document.getElementById('invoiceTypeSelect').value;
    window.location.href = 'create.php?type=' + type;
}
</script>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
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
                <label class="form-label">Type</label>
                <select name="type" class="form-select">
                    <option value="all" <?= $invoiceType === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="Proforma" <?= $invoiceType === 'Proforma' ? 'selected' : '' ?>>Proforma Invoice</option>
                    <option value="TaxInvoice" <?= $invoiceType === 'TaxInvoice' ? 'selected' : '' ?>>Tax Invoice</option>
                    <option value="Quote" <?= $invoiceType === 'Quote' ? 'selected' : '' ?>>Quote</option>
                    <option value="CreditNote" <?= $invoiceType === 'CreditNote' ? 'selected' : '' ?>>Credit Note</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="Draft" <?= $status === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Sent" <?= $status === 'Sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="Paid" <?= $status === 'Paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="Overdue" <?= $status === 'Overdue' ? 'selected' : '' ?>>Overdue</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Invoice #, Customer..." value="<?= escapeHtml($search) ?>">
            </div>
            <div class="col-md-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Apply Filters</button>
                <a href="index.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Type</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Branch</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td><strong><?= escapeHtml($invoice['invoice_number']) ?></strong></td>
                        <td><span class="badge bg-info"><?= escapeHtml($invoice['invoice_type']) ?></span></td>
                        <td><?= formatDate($invoice['invoice_date']) ?></td>
                        <td><?= escapeHtml(trim(($invoice['first_name'] ?? '') . ' ' . ($invoice['last_name'] ?? 'Walk-in'))) ?></td>
                        <td><?= escapeHtml($invoice['branch_name'] ?? 'N/A') ?></td>
                        <td><strong><?= formatCurrency($invoice['total_amount']) ?></strong></td>
                        <td>
                            <?php
                            $statusColors = [
                                'Paid' => 'success',
                                'Sent' => 'primary',
                                'Draft' => 'secondary',
                                'Overdue' => 'danger',
                                'Void' => 'dark',
                                'Viewed' => 'info'
                            ];
                            $color = $statusColors[$invoice['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $color ?>"><?= escapeHtml($invoice['status']) ?></span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="print.php?id=<?= $invoice['id'] ?>" class="btn btn-primary" title="View/Print"><i class="bi bi-printer"></i> View/Print</a>
                                <button type="button" class="btn btn-warning" onclick='window.showStatusModal(<?= $invoice['id'] ?>, <?= json_encode($invoice['status'] ?? '') ?>, <?= json_encode($invoice['invoice_number'] ?? '') ?>)' title="Change Status">
                                    <i class="bi bi-pencil"></i> Status
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- Status Change Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Invoice Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>Invoice:</strong> <span id="statusInvoiceNumber"></span></p>
                <p><strong>Current Status:</strong> <span id="statusCurrentStatus"></span></p>
                <div class="mb-3">
                    <label class="form-label">New Status</label>
                    <select id="newStatusSelect" class="form-select">
                        <option value="Draft">Draft</option>
                        <option value="Sent">Sent</option>
                        <option value="Viewed">Viewed</option>
                        <option value="Paid">Paid</option>
                        <option value="Overdue">Overdue</option>
                        <option value="Void">Void</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="window.updateInvoiceStatus()">Update Status</button>
            </div>
        </div>
    </div>
</div>

<script>
const BASE_URL_INVOICE = <?= json_encode(BASE_URL) ?>;
let currentInvoiceId = null;

window.showStatusModal = function(invoiceId, currentStatus, invoiceNumber) {
    currentInvoiceId = invoiceId;
    const invoiceNumberEl = document.getElementById('statusInvoiceNumber');
    const currentStatusEl = document.getElementById('statusCurrentStatus');
    const statusSelect = document.getElementById('newStatusSelect');
    const modalEl = document.getElementById('statusModal');
    
    if (invoiceNumberEl) invoiceNumberEl.textContent = invoiceNumber;
    if (currentStatusEl) currentStatusEl.textContent = currentStatus;
    if (statusSelect) statusSelect.value = currentStatus;
    
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
};

window.updateInvoiceStatus = function() {
    if (!currentInvoiceId) return;
    
    const statusSelect = document.getElementById('newStatusSelect');
    if (!statusSelect) return;
    
    const newStatus = statusSelect.value;
    
    fetch(BASE_URL_INVOICE + 'ajax/update_invoice_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            invoice_id: currentInvoiceId,
            status: newStatus
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'Invoice status updated successfully',
                confirmButtonColor: '#1e3a8a'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: data.message || 'Failed to update invoice status',
                confirmButtonColor: '#d33'
            });
        }
    })
    .catch(error => {
        console.error('Status update error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'An error occurred while updating the status',
            confirmButtonColor: '#d33'
        });
    });
};
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


