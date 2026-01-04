<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
// This page matches sidebar "All Invoices" menu item
$auth->requirePermission('invoicing.view');

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

// Build query - Only show Proforma invoices
$whereConditions = [
    "DATE(i.invoice_date) BETWEEN :start_date AND :end_date",
    "i.invoice_type = 'Proforma'"
];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "i.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "i.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
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

$invoices = $db->getRows("SELECT i.*, c.first_name, c.last_name, b.branch_name,
    CASE 
        WHEN i.status = 'Paid' THEN 'Paid'
        WHEN i.due_date IS NOT NULL AND DATE(i.due_date) < CURDATE() THEN 'Overdue'
        ELSE 'Pending'
    END as calculated_status
    FROM invoices i 
    LEFT JOIN customers c ON i.customer_id = c.id 
    LEFT JOIN branches b ON i.branch_id = b.id 
    WHERE $whereClause
    ORDER BY i.created_at DESC
    LIMIT 500", $params);

if ($invoices === false) $invoices = [];

// Update status based on due_date for non-paid invoices
foreach ($invoices as &$invoice) {
    if ($invoice['status'] !== 'Paid' && !empty($invoice['due_date'])) {
        $dueDate = new DateTime($invoice['due_date']);
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        $dueDate->setTime(0, 0, 0);
        
        if ($dueDate < $today) {
            // Update status to Overdue in database if not already
            if ($invoice['status'] !== 'Overdue') {
                $db->update('invoices', ['status' => 'Overdue'], ['id' => $invoice['id']]);
            }
            $invoice['status'] = 'Overdue';
        } else {
            // Update status to Pending if not already
            if ($invoice['status'] !== 'Pending' && $invoice['status'] !== 'Overdue') {
                $db->update('invoices', ['status' => 'Pending'], ['id' => $invoice['id']]);
            }
            if ($invoice['status'] !== 'Overdue') {
                $invoice['status'] = 'Pending';
            }
        }
    } elseif ($invoice['status'] !== 'Paid' && empty($invoice['due_date'])) {
        // No due date, set to Pending
        if ($invoice['status'] !== 'Pending') {
            $db->update('invoices', ['status' => 'Pending'], ['id' => $invoice['id']]);
        }
        $invoice['status'] = 'Pending';
    }
}
unset($invoice);

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Proforma Invoices</h2>
    <div class="d-flex gap-2 align-items-center">
        <button type="button" class="btn btn-primary" onclick="createInvoice()">
            <i class="bi bi-plus-circle"></i> Create Proforma Invoice
        </button>
    </div>
</div>

<script>
function createInvoice() {
    window.location.href = 'create.php?type=proforma';
}
</script>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body" style="padding: 12px;">
        <form method="GET" class="row g-2">
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
                    <option value="Pending" <?= $status === 'Pending' ? 'selected' : '' ?>>Pending</option>
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
                                'Pending' => 'warning',
                                'Overdue' => 'danger'
                            ];
                            $color = $statusColors[$invoice['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $color ?>"><?= escapeHtml($invoice['status']) ?></span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="print.php?id=<?= $invoice['id'] ?>" class="btn btn-primary" title="View/Print"><i class="bi bi-printer"></i> View</a>
                                <?php if ($invoice['status'] !== 'Paid'): ?>
                                    <?php if ($auth->hasPermission('invoicing.edit')): ?>
                                    <a href="edit.php?id=<?= $invoice['id'] ?>" class="btn btn-warning" title="Edit Invoice"><i class="bi bi-pencil"></i> Edit</a>
                                    <?php endif; ?>
                                    <?php if ($auth->hasPermission('invoicing.create') || $auth->hasPermission('pos.create_sale')): ?>
                                    <button type="button" class="btn btn-success" onclick="convertToSale(<?= $invoice['id'] ?>)" title="Convert to Sale">
                                        <i class="bi bi-cart-check"></i> Convert to Sale
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>


<script>
// Display error and warning messages from session
<?php if (isset($_SESSION['error_message'])): ?>
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: '<?= addslashes($_SESSION['error_message']) ?>',
        confirmButtonColor: '#d33'
    });
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['warning_message'])): ?>
    Swal.fire({
        icon: 'warning',
        title: 'Warning',
        text: '<?= addslashes($_SESSION['warning_message']) ?>',
        confirmButtonColor: '#ffc107'
    });
    <?php unset($_SESSION['warning_message']); ?>
<?php endif; ?>

function convertToSale(invoiceId) {
    Swal.fire({
        title: 'Convert to Sale?',
        html: 'This will convert the proforma invoice to a sale and redirect you to the payment page.<br><br>You will be able to process payment, deduct stock, and fiscalize the transaction.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Convert to Sale',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#28a745'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = '<?= BASE_URL ?>modules/invoicing/convert_to_sale.php?id=' + invoiceId;
        }
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


