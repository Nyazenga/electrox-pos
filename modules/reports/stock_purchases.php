<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.stock_purchases');

$pageTitle = 'View Stock Purchases';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedSupplier = $_GET['supplier_id'] ?? 'all';
$selectedStatus = $_GET['status'] ?? 'all';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get suppliers
$suppliers = $db->getRows("SELECT * FROM suppliers ORDER BY name");
if ($suppliers === false) $suppliers = [];

// Build query conditions
$whereConditions = ["DATE(g.created_at) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "g.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "g.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedSupplier !== 'all' && $selectedSupplier) {
    $whereConditions[] = "g.supplier_id = :supplier_id";
    $params[':supplier_id'] = $selectedSupplier;
}

if ($selectedStatus !== 'all' && $selectedStatus) {
    $whereConditions[] = "g.status = :status";
    $params[':status'] = $selectedStatus;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT g.id) as total_grns,
    COUNT(DISTINCT g.supplier_id) as unique_suppliers,
    COALESCE(SUM(g.total_value), 0) as total_amount,
    COALESCE(SUM(CASE WHEN g.status = 'Approved' THEN g.total_value ELSE 0 END), 0) as approved_amount
FROM goods_received_notes g
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_grns' => 0,
        'unique_suppliers' => 0,
        'total_amount' => 0,
        'approved_amount' => 0
    ];
}

// Get GRNs
$grns = $db->getRows("SELECT 
    g.id,
    g.grn_number,
    g.created_at,
    g.received_date,
    g.status,
    g.total_value as total_amount,
    g.notes,
    s.name as supplier_name,
    s.email as supplier_email,
    s.phone as supplier_phone,
    b.branch_name,
    u.first_name,
    u.last_name,
    (SELECT COUNT(*) FROM grn_items gi WHERE gi.grn_id = g.id) as item_count
FROM goods_received_notes g
LEFT JOIN suppliers s ON g.supplier_id = s.id
LEFT JOIN branches b ON g.branch_id = b.id
LEFT JOIN users u ON g.created_by = u.id
WHERE $whereClause
ORDER BY g.created_at DESC
LIMIT 1000", $params);

if ($grns === false) {
    $grns = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Stock Purchases Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total GRNs</td><td style="text-align: right;">' . $summary['total_grns'] . '</td></tr>';
    $html .= '<tr><td>Unique Suppliers</td><td style="text-align: right;">' . $summary['unique_suppliers'] . '</td></tr>';
    $html .= '<tr><td>Total Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_amount']) . '</td></tr>';
    $html .= '<tr><td>Approved Amount</td><td style="text-align: right;">' . formatCurrency($summary['approved_amount']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Purchase Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>GRN Number</th><th>Date</th><th>Supplier</th><th>Branch</th><th>Status</th><th>Items</th><th style="text-align: right;">Total Amount</th><th>Created By</th></tr>';
    foreach ($grns as $grn) {
        $statusColor = $grn['status'] == 'Approved' ? '#10b981' : ($grn['status'] == 'Pending' ? '#f59e0b' : '#6b7280');
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($grn['grn_number']) . '</td>';
        $html .= '<td>' . date('M d, Y', strtotime($grn['received_date'] ?? $grn['created_at'])) . '</td>';
        $html .= '<td>' . escapeHtml($grn['supplier_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($grn['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="color: ' . $statusColor . '; font-weight: bold;">' . escapeHtml($grn['status']) . '</td>';
        $html .= '<td>' . $grn['item_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($grn['total_amount']) . '</td>';
        $html .= '<td>' . escapeHtml(trim(($grn['first_name'] ?? '') . ' ' . ($grn['last_name'] ?? ''))) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Stock Purchases Report', $html, 'Stock_Purchases_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cart-check"></i> View Stock Purchases</h2>
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
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-truck"></i> Supplier</label>
                <select name="supplier_id" class="form-select">
                    <option value="all" <?= $selectedSupplier === 'all' ? 'selected' : '' ?>>All Suppliers</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= $supplier['id'] ?>" <?= $selectedSupplier == $supplier['id'] ? 'selected' : '' ?>><?= escapeHtml($supplier['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-check-circle"></i> Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="Pending" <?= $selectedStatus == 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Approved" <?= $selectedStatus == 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Rejected" <?= $selectedStatus == 'Rejected' ? 'selected' : '' ?>>Rejected</option>
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
                <h6 class="text-muted mb-2">Total GRNs</h6>
                <h3 class="mb-0"><?= $summary['total_grns'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Suppliers</h6>
                <h3 class="mb-0"><?= $summary['unique_suppliers'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Amount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_amount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Approved Amount</h6>
                <h3 class="mb-0 text-success"><?= formatCurrency($summary['approved_amount']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="purchasesTable">
                <thead>
                    <tr>
                        <th>GRN Number</th>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th class="text-end">Items</th>
                        <th class="text-end">Total Amount</th>
                        <th>Created By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grns)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No purchases found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($grns as $grn): ?>
                            <tr>
                                <td><span class="fw-bold"><?= escapeHtml($grn['grn_number']) ?></span></td>
                                <td><?= date('M d, Y', strtotime($grn['received_date'] ?? $grn['created_at'])) ?></td>
                                <td>
                                    <div><?= escapeHtml($grn['supplier_name'] ?? 'N/A') ?></div>
                                    <?php if (!empty($grn['supplier_phone'])): ?>
                                        <small class="text-muted"><?= escapeHtml($grn['supplier_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= escapeHtml($grn['branch_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?php if ($grn['status'] == 'Approved'): ?>
                                        <span class="badge bg-success">Approved</span>
                                    <?php elseif ($grn['status'] == 'Pending'): ?>
                                        <span class="badge bg-warning">Pending</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= escapeHtml($grn['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= $grn['item_count'] ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($grn['total_amount']) ?></td>
                                <td><?= escapeHtml(trim(($grn['first_name'] ?? '') . ' ' . ($grn['last_name'] ?? ''))) ?></td>
                                <td>
                                    <a href="<?= BASE_URL ?>modules/inventory/grn_view.php?id=<?= $grn['id'] ?>" class="btn btn-sm btn-info" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
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
$(document).ready(function() {
    $('#purchasesTable').DataTable({
        order: [[1, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

