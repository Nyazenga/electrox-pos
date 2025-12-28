<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.highest_movers_qty');

$pageTitle = 'Highest Movers by Qty for Branch';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$limit = intval($_GET['limit'] ?? 50);

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
    COUNT(DISTINCT si.product_id) as unique_products,
    COALESCE(SUM(si.quantity), 0) as total_quantity_sold,
    COUNT(DISTINCT s.id) as total_transactions
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'unique_products' => 0,
        'total_quantity_sold' => 0,
        'total_transactions' => 0
    ];
}

// Get highest movers by quantity
$highestMovers = $db->getRows("SELECT 
    si.product_id,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    p.product_code,
    pc.name as category_name,
    b.branch_name,
    SUM(si.quantity) as total_quantity_sold,
    COUNT(DISTINCT s.id) as transaction_count,
    COALESCE(SUM(si.total_price), 0) as total_sales,
    COALESCE(AVG(si.unit_price), 0) as avg_price
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN branches b ON s.branch_id = b.id
WHERE $whereClause
GROUP BY si.product_id, p.id, p.product_name, p.product_code, p.brand, p.model, pc.name, b.branch_name
ORDER BY total_quantity_sold DESC
LIMIT $limit", $params);

if ($highestMovers === false) {
    $highestMovers = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Highest Movers by Qty for Branch</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Unique Products</td><td style="text-align: right;">' . $summary['unique_products'] . '</td></tr>';
    $html .= '<tr><td>Total Quantity Sold</td><td style="text-align: right;">' . number_format($summary['total_quantity_sold'], 2) . '</td></tr>';
    $html .= '<tr><td>Total Transactions</td><td style="text-align: right;">' . $summary['total_transactions'] . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Top ' . $limit . ' Products by Quantity</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Rank</th><th>Product Code</th><th>Product Name</th><th>Category</th><th>Branch</th><th style="text-align: right;">Qty Sold</th><th>Transactions</th><th style="text-align: right;">Total Sales</th><th style="text-align: right;">Avg Price</th></tr>';
    $rank = 1;
    foreach ($highestMovers as $product) {
        $html .= '<tr>';
        $html .= '<td>' . $rank++ . '</td>';
        $html .= '<td>' . escapeHtml($product['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($product['product_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($product['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . escapeHtml($product['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($product['total_quantity_sold'], 2) . '</td>';
        $html .= '<td>' . $product['transaction_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['total_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($product['avg_price']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Highest Movers by Qty for Branch', $html, 'Highest_Movers_Qty_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-up-circle"></i> Highest Movers by Qty for Branch</h2>
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
                <label class="form-label"><i class="bi bi-list-ol"></i> Limit</label>
                <select name="limit" class="form-select">
                    <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>Top 25</option>
                    <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>Top 50</option>
                    <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>Top 100</option>
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
                <h6 class="text-muted mb-2">Unique Products</h6>
                <h3 class="mb-0"><?= $summary['unique_products'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Quantity Sold</h6>
                <h3 class="mb-0"><?= number_format($summary['total_quantity_sold'], 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Transactions</h6>
                <h3 class="mb-0"><?= $summary['total_transactions'] ?></h3>
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
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Branch</th>
                        <th class="text-end">Qty Sold</th>
                        <th class="text-end">Transactions</th>
                        <th class="text-end">Total Sales</th>
                        <th class="text-end">Avg Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($highestMovers)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No products found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php $rank = 1; foreach ($highestMovers as $product): ?>
                            <tr>
                                <td><span class="badge bg-primary"><?= $rank++ ?></span></td>
                                <td><?= escapeHtml($product['product_code'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($product['product_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($product['category_name'] ?? 'Uncategorized') ?></td>
                                <td><?= escapeHtml($product['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end fw-bold"><?= number_format($product['total_quantity_sold'], 2) ?></td>
                                <td class="text-end"><?= $product['transaction_count'] ?></td>
                                <td class="text-end"><?= formatCurrency($product['total_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($product['avg_price']) ?></td>
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
        order: [[5, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

