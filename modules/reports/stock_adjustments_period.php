<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.stock_adjustments_period');

$pageTitle = 'Stock Adjustments for Period';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedMovementType = $_GET['movement_type'] ?? 'all';
$selectedProduct = $_GET['product_id'] ?? 'all';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get products
$products = $db->getRows("SELECT id, product_code, product_name, brand, model FROM products WHERE status = 'Active' ORDER BY product_code LIMIT 500");
if ($products === false) $products = [];

// Movement types
$movementTypes = ['Purchase', 'Sale', 'Adjustment', 'Transfer In', 'Transfer Out', 'Return', 'Damage', 'Expired', 'GRN'];

// Build query conditions
$whereConditions = ["DATE(sm.created_at) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "sm.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "sm.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedMovementType !== 'all' && $selectedMovementType) {
    $whereConditions[] = "sm.movement_type = :movement_type";
    $params[':movement_type'] = $selectedMovementType;
}

if ($selectedProduct !== 'all' && $selectedProduct) {
    $whereConditions[] = "sm.product_id = :product_id";
    $params[':product_id'] = $selectedProduct;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT sm.id) as total_adjustments,
    COUNT(DISTINCT sm.product_id) as unique_products,
    SUM(CASE WHEN sm.quantity > 0 THEN sm.quantity ELSE 0 END) as total_increases,
    SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as total_decreases,
    COUNT(DISTINCT sm.movement_type) as movement_types_count
FROM stock_movements sm
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_adjustments' => 0,
        'unique_products' => 0,
        'total_increases' => 0,
        'total_decreases' => 0,
        'movement_types_count' => 0
    ];
}

// Get stock adjustments
$adjustments = $db->getRows("SELECT 
    sm.id,
    sm.created_at,
    sm.movement_type,
    sm.quantity,
    sm.previous_quantity,
    sm.new_quantity,
    sm.notes,
    sm.reference_id,
    sm.reference_type,
    p.product_code,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    b.branch_name,
    u.first_name,
    u.last_name
FROM stock_movements sm
LEFT JOIN products p ON sm.product_id = p.id
LEFT JOIN branches b ON sm.branch_id = b.id
LEFT JOIN users u ON sm.user_id = u.id
WHERE $whereClause
ORDER BY sm.created_at DESC
LIMIT 2000", $params);

if ($adjustments === false) {
    $adjustments = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Stock Adjustments for Period</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Adjustments</td><td style="text-align: right;">' . $summary['total_adjustments'] . '</td></tr>';
    $html .= '<tr><td>Unique Products</td><td style="text-align: right;">' . $summary['unique_products'] . '</td></tr>';
    $html .= '<tr><td>Total Increases</td><td style="text-align: right;">' . number_format($summary['total_increases'], 2) . '</td></tr>';
    $html .= '<tr><td>Total Decreases</td><td style="text-align: right;">' . number_format($summary['total_decreases'], 2) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Adjustment Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Date</th><th>Product</th><th>Branch</th><th>Type</th><th>Previous Qty</th><th>Change</th><th>New Qty</th><th>User</th><th>Notes</th></tr>';
    foreach ($adjustments as $adj) {
        $qtyColor = $adj['quantity'] > 0 ? '#10b981' : '#dc2626';
        $html .= '<tr>';
        $html .= '<td>' . date('M d, Y H:i', strtotime($adj['created_at'])) . '</td>';
        $html .= '<td>' . escapeHtml($adj['product_code'] ?? 'N/A') . ' - ' . escapeHtml(substr($adj['product_name'] ?? 'N/A', 0, 20)) . '</td>';
        $html .= '<td>' . escapeHtml($adj['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($adj['movement_type']) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($adj['previous_quantity'], 2) . '</td>';
        $html .= '<td style="text-align: right; color: ' . $qtyColor . ';">' . ($adj['quantity'] > 0 ? '+' : '') . number_format($adj['quantity'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($adj['new_quantity'], 2) . '</td>';
        $html .= '<td>' . escapeHtml(trim(($adj['first_name'] ?? '') . ' ' . ($adj['last_name'] ?? ''))) . '</td>';
        $html .= '<td>' . escapeHtml(substr($adj['notes'] ?? '', 0, 30)) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Stock Adjustments for Period', $html, 'Stock_Adjustments_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-left-right"></i> Stock Adjustments for Period</h2>
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
                <label class="form-label"><i class="bi bi-tags"></i> Movement Type</label>
                <select name="movement_type" class="form-select">
                    <option value="all" <?= $selectedMovementType === 'all' ? 'selected' : '' ?>>All Types</option>
                    <?php foreach ($movementTypes as $type): ?>
                        <option value="<?= $type ?>" <?= $selectedMovementType == $type ? 'selected' : '' ?>><?= escapeHtml($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Adjustments</h6>
                <h3 class="mb-0"><?= $summary['total_adjustments'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Products</h6>
                <h3 class="mb-0"><?= $summary['unique_products'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Increases</h6>
                <h3 class="mb-0 text-success">+<?= number_format($summary['total_increases'], 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Decreases</h6>
                <h3 class="mb-0 text-danger">-<?= number_format($summary['total_decreases'], 2) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="adjustmentsTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Branch</th>
                        <th>Type</th>
                        <th class="text-end">Previous Qty</th>
                        <th class="text-end">Change</th>
                        <th class="text-end">New Qty</th>
                        <th>User</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($adjustments)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No stock adjustments found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($adjustments as $adj): ?>
                            <tr>
                                <td><?= date('M d, Y H:i', strtotime($adj['created_at'])) ?></td>
                                <td>
                                    <div class="fw-bold"><?= escapeHtml($adj['product_code'] ?? 'N/A') ?></div>
                                    <small class="text-muted"><?= escapeHtml(substr($adj['product_name'] ?? 'N/A', 0, 30)) ?></small>
                                </td>
                                <td><?= escapeHtml($adj['branch_name'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-info"><?= escapeHtml($adj['movement_type']) ?></span></td>
                                <td class="text-end"><?= number_format($adj['previous_quantity'], 2) ?></td>
                                <td class="text-end">
                                    <span class="fw-bold <?= $adj['quantity'] > 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= $adj['quantity'] > 0 ? '+' : '' ?><?= number_format($adj['quantity'], 2) ?>
                                    </span>
                                </td>
                                <td class="text-end fw-bold"><?= number_format($adj['new_quantity'], 2) ?></td>
                                <td><?= escapeHtml(trim(($adj['first_name'] ?? '') . ' ' . ($adj['last_name'] ?? ''))) ?></td>
                                <td><small><?= escapeHtml(substr($adj['notes'] ?? '', 0, 40)) ?></small></td>
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
    $('#adjustmentsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


