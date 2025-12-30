<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.purchases_by_supplier');

$pageTitle = 'Purchases by Supplier';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedSupplier = $_GET['supplier_id'] ?? 'all';

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

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT g.supplier_id) as unique_suppliers,
    COUNT(DISTINCT g.id) as total_grns,
    COALESCE(SUM(g.total_value), 0) as total_amount,
    COALESCE(SUM(CASE WHEN g.status = 'Approved' THEN g.total_value ELSE 0 END), 0) as approved_amount
FROM goods_received_notes g
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'unique_suppliers' => 0,
        'total_grns' => 0,
        'total_amount' => 0,
        'approved_amount' => 0
    ];
}

// Get purchases by supplier
$purchasesBySupplier = $db->getRows("SELECT 
    s.id as supplier_id,
    s.name as supplier_name,
    s.email as supplier_email,
    s.phone as supplier_phone,
    COUNT(DISTINCT g.id) as grn_count,
    COALESCE(SUM(g.total_value), 0) as total_amount,
    COALESCE(SUM(CASE WHEN g.status = 'Approved' THEN g.total_value ELSE 0 END), 0) as approved_amount,
    COALESCE(SUM(CASE WHEN g.status = 'Pending' THEN g.total_value ELSE 0 END), 0) as pending_amount,
    COUNT(DISTINCT g.branch_id) as branch_count
FROM goods_received_notes g
LEFT JOIN suppliers s ON g.supplier_id = s.id
WHERE $whereClause AND g.supplier_id IS NOT NULL
GROUP BY s.id, s.name, s.email, s.phone
ORDER BY total_amount DESC", $params);

if ($purchasesBySupplier === false) {
    $purchasesBySupplier = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Purchases by Supplier</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Unique Suppliers</td><td style="text-align: right;">' . $summary['unique_suppliers'] . '</td></tr>';
    $html .= '<tr><td>Total GRNs</td><td style="text-align: right;">' . $summary['total_grns'] . '</td></tr>';
    $html .= '<tr><td>Total Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_amount']) . '</td></tr>';
    $html .= '<tr><td>Approved Amount</td><td style="text-align: right;">' . formatCurrency($summary['approved_amount']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Purchases by Supplier</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Supplier</th><th>Email</th><th>Phone</th><th>GRNs</th><th>Branches</th><th style="text-align: right;">Total Amount</th><th style="text-align: right;">Approved</th><th style="text-align: right;">Pending</th></tr>';
    foreach ($purchasesBySupplier as $supplier) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($supplier['supplier_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($supplier['supplier_email'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($supplier['supplier_phone'] ?? 'N/A') . '</td>';
        $html .= '<td>' . $supplier['grn_count'] . '</td>';
        $html .= '<td>' . $supplier['branch_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($supplier['total_amount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($supplier['approved_amount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($supplier['pending_amount']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Purchases by Supplier', $html, 'Purchases_By_Supplier_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-truck"></i> Purchases by Supplier</h2>
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
                <label class="form-label"><i class="bi bi-truck"></i> Supplier</label>
                <select name="supplier_id" class="form-select">
                    <option value="all" <?= $selectedSupplier === 'all' ? 'selected' : '' ?>>All Suppliers</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= $supplier['id'] ?>" <?= $selectedSupplier == $supplier['id'] ? 'selected' : '' ?>><?= escapeHtml($supplier['name']) ?></option>
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
                <h6 class="text-muted mb-2">Unique Suppliers</h6>
                <h3 class="mb-0"><?= $summary['unique_suppliers'] ?></h3>
            </div>
        </div>
    </div>
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
            <table class="table table-striped table-hover" id="suppliersTable">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th class="text-end">GRNs</th>
                        <th class="text-end">Branches</th>
                        <th class="text-end">Total Amount</th>
                        <th class="text-end">Approved</th>
                        <th class="text-end">Pending</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchasesBySupplier)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No purchases found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchasesBySupplier as $supplier): ?>
                            <tr>
                                <td><span class="fw-bold"><?= escapeHtml($supplier['supplier_name'] ?? 'N/A') ?></span></td>
                                <td><?= escapeHtml($supplier['supplier_email'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($supplier['supplier_phone'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $supplier['grn_count'] ?></td>
                                <td class="text-end"><?= $supplier['branch_count'] ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($supplier['total_amount']) ?></td>
                                <td class="text-end text-success"><?= formatCurrency($supplier['approved_amount']) ?></td>
                                <td class="text-end text-warning"><?= formatCurrency($supplier['pending_amount']) ?></td>
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
    $('#suppliersTable').DataTable({
        order: [[5, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


