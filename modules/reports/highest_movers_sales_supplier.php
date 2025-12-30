<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.highest_movers_sales_supplier');

$pageTitle = 'Highest Movers by Sales for Supplier';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedSupplier = $_GET['supplier_id'] ?? 'all';
$limit = intval($_GET['limit'] ?? 50);

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get suppliers
$suppliers = $db->getRows("SELECT DISTINCT s.* FROM suppliers s 
                          INNER JOIN products p ON s.id = p.supplier_id
                          INNER JOIN sale_items si ON p.id = si.product_id
                          INNER JOIN sales sa ON si.sale_id = sa.id
                          WHERE DATE(sa.sale_date) BETWEEN :start AND :end
                          ORDER BY s.name", 
                          [':start' => $startDate, ':end' => $endDate]);
if ($suppliers === false) $suppliers = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedSupplier !== 'all' && $selectedSupplier) {
    $whereConditions[] = "p.supplier_id = :supplier_id";
    $params[':supplier_id'] = $selectedSupplier;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT p.supplier_id) as unique_suppliers,
    COUNT(DISTINCT si.product_id) as unique_products,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as total_net_sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'unique_suppliers' => 0,
        'unique_products' => 0,
        'total_net_sales' => 0
    ];
}

// Get highest movers by supplier
$highestMovers = $db->getRows("SELECT 
    p.supplier_id,
    sup.name as supplier_name,
    sup.email as supplier_email,
    sup.phone as supplier_phone,
    COUNT(DISTINCT si.product_id) as unique_products,
    SUM(si.quantity) as total_quantity_sold,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as net_sales,
    COUNT(DISTINCT s.id) as transaction_count
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN suppliers sup ON p.supplier_id = sup.id
WHERE $whereClause AND p.supplier_id IS NOT NULL
GROUP BY p.supplier_id, sup.name, sup.email, sup.phone
ORDER BY net_sales DESC
LIMIT $limit", $params);

if ($highestMovers === false) {
    $highestMovers = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Highest Movers by Sales for Supplier</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Unique Suppliers</td><td style="text-align: right;">' . $summary['unique_suppliers'] . '</td></tr>';
    $html .= '<tr><td>Unique Products</td><td style="text-align: right;">' . $summary['unique_products'] . '</td></tr>';
    $html .= '<tr><td>Total Net Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_net_sales']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Top ' . $limit . ' Suppliers by Sales</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Rank</th><th>Supplier Name</th><th>Email</th><th>Phone</th><th>Unique Products</th><th style="text-align: right;">Qty Sold</th><th style="text-align: right;">Net Sales</th><th>Transactions</th></tr>';
    $rank = 1;
    foreach ($highestMovers as $supplier) {
        $html .= '<tr>';
        $html .= '<td>' . $rank++ . '</td>';
        $html .= '<td>' . escapeHtml($supplier['supplier_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($supplier['supplier_email'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($supplier['supplier_phone'] ?? 'N/A') . '</td>';
        $html .= '<td>' . $supplier['unique_products'] . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($supplier['total_quantity_sold'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($supplier['net_sales']) . '</td>';
        $html .= '<td>' . $supplier['transaction_count'] . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Highest Movers by Sales for Supplier', $html, 'Highest_Movers_Supplier_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-truck"></i> Highest Movers by Sales for Supplier</h2>
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
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Suppliers</h6>
                <h3 class="mb-0"><?= $summary['unique_suppliers'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Products</h6>
                <h3 class="mb-0"><?= $summary['unique_products'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Net Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_net_sales']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="highestMoversTable">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Supplier Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th class="text-end">Unique Products</th>
                        <th class="text-end">Qty Sold</th>
                        <th class="text-end">Net Sales</th>
                        <th class="text-end">Transactions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($highestMovers)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No suppliers found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php $rank = 1; foreach ($highestMovers as $supplier): ?>
                            <tr>
                                <td><span class="badge bg-primary"><?= $rank++ ?></span></td>
                                <td><?= escapeHtml($supplier['supplier_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($supplier['supplier_email'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($supplier['supplier_phone'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $supplier['unique_products'] ?></td>
                                <td class="text-end"><?= number_format($supplier['total_quantity_sold'], 2) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($supplier['net_sales']) ?></td>
                                <td class="text-end"><?= $supplier['transaction_count'] ?></td>
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
    $('#highestMoversTable').DataTable({
        order: [[6, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


