<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.sales_by_category_branch');

$pageTitle = 'Sales by Category for Branch';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

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

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_sales,
    COUNT(DISTINCT pc.id) as unique_categories,
    COUNT(DISTINCT s.branch_id) as unique_branches,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as net_sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_sales' => 0,
        'unique_categories' => 0,
        'unique_branches' => 0,
        'net_sales' => 0
    ];
}

// Get sales by category and branch
$salesByCategory = $db->getRows("SELECT 
    b.id as branch_id,
    b.branch_name,
    pc.id as category_id,
    pc.name as category_name,
    COUNT(DISTINCT s.id) as sale_count,
    SUM(si.quantity) as total_quantity,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as net_sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN branches b ON s.branch_id = b.id
WHERE $whereClause
GROUP BY b.id, b.branch_name, pc.id, pc.name
ORDER BY b.branch_name, net_sales DESC", $params);

if ($salesByCategory === false) {
    $salesByCategory = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales by Category for Branch</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . $summary['total_sales'] . '</td></tr>';
    $html .= '<tr><td>Unique Categories</td><td style="text-align: right;">' . $summary['unique_categories'] . '</td></tr>';
    $html .= '<tr><td>Unique Branches</td><td style="text-align: right;">' . $summary['unique_branches'] . '</td></tr>';
    $html .= '<tr><td>Net Sales</td><td style="text-align: right;">' . formatCurrency($summary['net_sales']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Sales by Category and Branch</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Branch</th><th>Category</th><th>Sales</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Net Sales</th></tr>';
    foreach ($salesByCategory as $item) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($item['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($item['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . $item['sale_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($item['total_quantity'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['net_sales']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales by Category for Branch', $html, 'Sales_By_Category_Branch_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-shop"></i> Sales by Category for Branch</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-calendar"></i> Start Date</label>
                <input type="date" name="start_date" value="<?= $startDate ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-calendar"></i> End Date</label>
                <input type="date" name="end_date" value="<?= $endDate ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
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
                <h6 class="text-muted mb-2">Total Sales</h6>
                <h3 class="mb-0"><?= $summary['total_sales'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Categories</h6>
                <h3 class="mb-0"><?= $summary['unique_categories'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Branches</h6>
                <h3 class="mb-0"><?= $summary['unique_branches'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Net Sales</h6>
                <h3 class="mb-0 text-success"><?= formatCurrency($summary['net_sales']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="salesByCategoryTable">
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Category</th>
                        <th class="text-end">Sales</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Net Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salesByCategory)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No sales found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($salesByCategory as $item): ?>
                            <tr>
                                <td><span class="fw-bold"><?= escapeHtml($item['branch_name'] ?? 'N/A') ?></span></td>
                                <td><?= escapeHtml($item['category_name'] ?? 'Uncategorized') ?></td>
                                <td class="text-end"><?= $item['sale_count'] ?></td>
                                <td class="text-end"><?= number_format($item['total_quantity'], 2) ?></td>
                                <td class="text-end fw-bold text-success"><?= formatCurrency($item['net_sales']) ?></td>
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
    $('#salesByCategoryTable').DataTable({
        order: [[4, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


