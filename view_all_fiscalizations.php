<?php
/**
 * View All Fiscalizations Sent to ZIMRA
 * Searchable, filterable, paginated data table with links to receipts
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

$pageTitle = 'All Fiscalizations - ZIMRA';
$primaryDb = Database::getPrimaryInstance();

// Get filter parameters
$deviceFilter = $_GET['device_id'] ?? '';
$branchFilter = $_GET['branch_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build query with filters
$whereConditions = [];
$params = [];

if ($deviceFilter) {
    $whereConditions[] = "fr.device_id = :device_id";
    $params[':device_id'] = $deviceFilter;
}

if ($branchFilter) {
    $whereConditions[] = "fr.branch_id = :branch_id";
    $params[':branch_id'] = $branchFilter;
}

if ($statusFilter) {
    $whereConditions[] = "fr.submission_status = :status";
    $params[':status'] = $statusFilter;
}

if ($typeFilter) {
    if ($typeFilter === 'invoice') {
        $whereConditions[] = "fr.invoice_id IS NOT NULL";
    } elseif ($typeFilter === 'sale') {
        $whereConditions[] = "fr.sale_id IS NOT NULL";
    }
}

if ($dateFrom) {
    $whereConditions[] = "DATE(fr.receipt_date) >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = "DATE(fr.receipt_date) <= :date_to";
    $params[':date_to'] = $dateTo;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get all fiscal receipts
$fiscalReceipts = $primaryDb->getRows("
    SELECT 
        fr.*,
        fd.device_id,
        fd.device_serial_no,
        b.branch_name,
        b.branch_code,
        CASE 
            WHEN fr.invoice_id IS NOT NULL THEN 'Invoice'
            WHEN fr.sale_id IS NOT NULL THEN 'Sale'
            ELSE 'Other'
        END as receipt_source
    FROM fiscal_receipts fr
    LEFT JOIN fiscal_devices fd ON fr.device_id = fd.device_id
    LEFT JOIN branches b ON fr.branch_id = b.id
    $whereClause
    ORDER BY fr.submitted_at DESC
", $params);

// Get filter options
$devices = $primaryDb->getRows("SELECT DISTINCT device_id FROM fiscal_devices ORDER BY device_id");
$branches = $primaryDb->getRows("SELECT id, branch_name FROM branches ORDER BY branch_name");

require_once APP_PATH . '/includes/header.php';
?>

<style>
    .filters-card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .fiscal-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
    }
    .badge-invoice { background: #10b981; color: white; }
    .badge-sale { background: #3b82f6; color: white; }
    .badge-submitted { background: #10b981; color: white; }
    .badge-pending { background: #f59e0b; color: white; }
    .badge-failed { background: #ef4444; color: white; }
    .qr-code-small {
        max-width: 60px;
        max-height: 60px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
</style>

<div class="content-wrapper">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <h1 class="h3 mb-0">All Fiscalizations - ZIMRA</h1>
                <p class="text-muted">Total: <strong><?= count($fiscalReceipts) ?></strong> fiscal receipts</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Device ID</label>
                    <select name="device_id" class="form-select form-select-sm">
                        <option value="">All Devices</option>
                        <?php foreach ($devices as $device): ?>
                            <option value="<?= $device['device_id'] ?>" <?= $deviceFilter == $device['device_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($device['device_id']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $branchFilter == $branch['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="Submitted" <?= $statusFilter == 'Submitted' ? 'selected' : '' ?>>Submitted</option>
                        <option value="Pending" <?= $statusFilter == 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Failed" <?= $statusFilter == 'Failed' ? 'selected' : '' ?>>Failed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="invoice" <?= $typeFilter == 'invoice' ? 'selected' : '' ?>>Invoice</option>
                        <option value="sale" <?= $typeFilter == 'sale' ? 'selected' : '' ?>>Sale</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel"></i> Apply Filters
                    </button>
                    <a href="view_all_fiscalizations.php" class="btn btn-secondary btn-sm">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="card">
            <div class="card-body">
                <table id="fiscalizationsTable" class="table table-striped table-hover data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Receipt/Invoice</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Device</th>
                            <th>Branch</th>
                            <th>Fiscal Day</th>
                            <th>Global No</th>
                            <th>Verification Code</th>
                            <th>Status</th>
                            <th>QR Code</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fiscalReceipts)): ?>
                            <tr>
                                <td colspan="13" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox" style="font-size: 48px;"></i>
                                    <p class="mt-2">No fiscalizations found</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fiscalReceipts as $receipt): ?>
                                <tr>
                                    <td><?= htmlspecialchars($receipt['id']) ?></td>
                                    <td>
                                        <span class="fiscal-badge badge-<?= strtolower($receipt['receipt_source']) ?>">
                                            <?= htmlspecialchars($receipt['receipt_source']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($receipt['invoice_id']): ?>
                                            <a href="<?= BASE_URL ?>modules/invoicing/print.php?id=<?= $receipt['invoice_id'] ?>&pdf=1" target="_blank">
                                                Invoice #<?= $receipt['invoice_id'] ?>
                                            </a>
                                        <?php elseif ($receipt['sale_id']): ?>
                                            <a href="<?= BASE_URL ?>modules/pos/receipt.php?id=<?= $receipt['sale_id'] ?>&print=1" target="_blank">
                                                Sale #<?= $receipt['sale_id'] ?>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($receipt['invoice_no'] ?? 'N/A') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($receipt['receipt_date'] ?? 'N/A') ?></td>
                                    <td><?= number_format($receipt['receipt_total'] ?? 0, 2) ?> <?= htmlspecialchars($receipt['receipt_currency'] ?? 'USD') ?></td>
                                    <td>
                                        <small>
                                            ID: <?= htmlspecialchars($receipt['device_id'] ?? 'N/A') ?><br>
                                            <?= htmlspecialchars($receipt['device_serial_no'] ?? 'N/A') ?>
                                        </small>
                                    </td>
                                    <td><?= htmlspecialchars($receipt['branch_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($receipt['fiscal_day_no'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($receipt['receipt_global_no'] ?? 'N/A') ?></td>
                                    <td><strong><?= htmlspecialchars($receipt['receipt_verification_code'] ?? 'N/A') ?></strong></td>
                                    <td>
                                        <span class="fiscal-badge badge-<?= strtolower($receipt['submission_status'] ?? 'pending') ?>">
                                            <?= htmlspecialchars($receipt['submission_status'] ?? 'Pending') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($receipt['receipt_qr_code']): ?>
                                            <?php 
                                            $qrData = base64_decode($receipt['receipt_qr_code']);
                                            if ($qrData): ?>
                                                <img src="data:image/png;base64,<?= base64_encode($qrData) ?>" class="qr-code-small" alt="QR Code">
                                            <?php else: ?>
                                                <span class="text-muted">Invalid</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No QR</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($receipt['invoice_id']): ?>
                                            <a href="<?= BASE_URL ?>modules/invoicing/print.php?id=<?= $receipt['invoice_id'] ?>&pdf=1" 
                                               class="btn btn-sm btn-outline-primary" target="_blank" title="View Invoice">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                            </a>
                                        <?php elseif ($receipt['sale_id']): ?>
                                            <a href="<?= BASE_URL ?>modules/pos/receipt.php?id=<?= $receipt['sale_id'] ?>&print=1" 
                                               class="btn btn-sm btn-outline-primary" target="_blank" title="View Receipt">
                                                <i class="bi bi-receipt"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    if ($.fn.DataTable) {
        $('#fiscalizationsTable').DataTable({
            order: [[0, 'desc']], // Sort by ID descending
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            autoWidth: false,
            columnDefs: [
                { orderable: false, targets: [11, 12] } // QR Code and Actions columns
            ],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "No entries to show",
                infoFiltered: "(filtered from _MAX_ total entries)",
                paginate: {
                    first: "First",
                    last: "Last",
                    next: "Next",
                    previous: "Previous"
                }
            }
        });
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
