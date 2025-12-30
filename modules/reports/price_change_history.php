<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.price_change_history');

$pageTitle = 'Price Change History Report';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedProduct = $_GET['product_id'] ?? 'all';
$priceType = $_GET['price_type'] ?? 'all';

// Get branches
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get products for filter
$products = $db->getRows("SELECT id, product_code, product_name, brand, model FROM products ORDER BY product_code");
if ($products === false) $products = [];

// Build query conditions
$whereConditions = ["DATE(pch.changed_at) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "pch.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "pch.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedProduct !== 'all' && $selectedProduct) {
    $whereConditions[] = "pch.product_id = :product_id";
    $params[':product_id'] = $selectedProduct;
}

if ($priceType !== 'all' && $priceType) {
    $whereConditions[] = "pch.price_type = :price_type";
    $params[':price_type'] = $priceType;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $primaryDb->getRow("SELECT 
    COUNT(*) as total_changes,
    COUNT(DISTINCT pch.product_id) as unique_products,
    COUNT(DISTINCT pch.branch_id) as unique_branches,
    COUNT(CASE WHEN pch.price_type = 'cost_price' THEN 1 END) as cost_price_changes,
    COUNT(CASE WHEN pch.price_type = 'selling_price' THEN 1 END) as selling_price_changes
FROM price_change_history pch
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_changes' => 0,
        'unique_products' => 0,
        'unique_branches' => 0,
        'cost_price_changes' => 0,
        'selling_price_changes' => 0
    ];
}

// Get price change history
$priceChanges = $primaryDb->getRows("SELECT 
    pch.*,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    p.product_code,
    b.branch_name,
    CONCAT(u.first_name, ' ', u.last_name) as changed_by_name,
    (pch.new_price - pch.old_price) as price_difference,
    CASE 
        WHEN pch.old_price > 0 THEN ((pch.new_price - pch.old_price) / pch.old_price * 100)
        ELSE 0
    END as percentage_change
FROM price_change_history pch
LEFT JOIN products p ON pch.product_id = p.id
LEFT JOIN branches b ON pch.branch_id = b.id
LEFT JOIN users u ON pch.changed_by = u.id
WHERE $whereClause
ORDER BY pch.changed_at DESC
LIMIT 1000", $params);

if ($priceChanges === false) {
    $priceChanges = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Price Change History Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Changes</td><td style="text-align: right;">' . number_format($summary['total_changes']) . '</td></tr>';
    $html .= '<tr><td>Unique Products</td><td style="text-align: right;">' . number_format($summary['unique_products']) . '</td></tr>';
    $html .= '<tr><td>Cost Price Changes</td><td style="text-align: right;">' . number_format($summary['cost_price_changes']) . '</td></tr>';
    $html .= '<tr><td>Selling Price Changes</td><td style="text-align: right;">' . number_format($summary['selling_price_changes']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Price Change Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Date</th><th>Product</th><th>Code</th><th>Branch</th><th>Price Type</th><th>Old Price</th><th>New Price</th><th>Difference</th><th>% Change</th><th>Changed By</th><th>Reason</th></tr>';
    foreach ($priceChanges as $change) {
        $html .= '<tr>';
        $html .= '<td>' . date('Y-m-d H:i', strtotime($change['changed_at'])) . '</td>';
        $html .= '<td>' . escapeHtml($change['product_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($change['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($change['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . ucfirst(str_replace('_', ' ', $change['price_type'])) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($change['old_price'] ?? 0) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($change['new_price']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($change['price_difference']) . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($change['percentage_change'], 2) . '%</td>';
        $html .= '<td>' . escapeHtml($change['changed_by_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($change['change_reason'] ?? 'N/A') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Price Change History Report', $html, 'Price_Change_History_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-graph-up-arrow"></i> Price Change History Report</h2>
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
                <label class="form-label"><i class="bi bi-box"></i> Product</label>
                <select name="product_id" class="form-select">
                    <option value="all" <?= $selectedProduct === 'all' ? 'selected' : '' ?>>All Products</option>
                    <?php foreach ($products as $product): 
                        $productDisplay = ($product['product_name'] ?: ($product['brand'] . ' ' . $product['model'])) . ' (' . $product['product_code'] . ')';
                    ?>
                        <option value="<?= $product['id'] ?>" <?= $selectedProduct == $product['id'] ? 'selected' : '' ?>><?= escapeHtml($productDisplay) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-tag"></i> Price Type</label>
                <select name="price_type" class="form-select">
                    <option value="all" <?= $priceType === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="cost_price" <?= $priceType === 'cost_price' ? 'selected' : '' ?>>Cost Price</option>
                    <option value="selling_price" <?= $priceType === 'selling_price' ? 'selected' : '' ?>>Selling Price</option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="price_change_history.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Changes</h6>
                <h3 class="mb-0"><?= number_format($summary['total_changes']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Products</h6>
                <h3 class="mb-0"><?= number_format($summary['unique_products']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Cost Price Changes</h6>
                <h3 class="mb-0"><?= number_format($summary['cost_price_changes']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Selling Price Changes</h6>
                <h3 class="mb-0"><?= number_format($summary['selling_price_changes']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Price Change History</h5>
    </div>
    <div class="card-body">
        <?php if (empty($priceChanges)): ?>
            <p class="text-muted mb-0">No price changes found for the selected period.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="priceChangeTable">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Product</th>
                            <th>Code</th>
                            <th>Branch</th>
                            <th>Price Type</th>
                            <th class="text-end">Old Price</th>
                            <th class="text-end">New Price</th>
                            <th class="text-end">Difference</th>
                            <th class="text-end">% Change</th>
                            <th>Changed By</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($priceChanges as $change): 
                            $priceDiff = floatval($change['price_difference'] ?? 0);
                            $diffClass = $priceDiff > 0 ? 'text-success' : ($priceDiff < 0 ? 'text-danger' : '');
                        ?>
                            <tr>
                                <td><?= date('Y-m-d H:i', strtotime($change['changed_at'])) ?></td>
                                <td><?= escapeHtml($change['product_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($change['product_code'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($change['branch_name'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-<?= $change['price_type'] === 'cost_price' ? 'info' : 'primary' ?>"><?= ucfirst(str_replace('_', ' ', $change['price_type'])) ?></span></td>
                                <td class="text-end"><?= formatCurrency($change['old_price'] ?? 0) ?></td>
                                <td class="text-end"><strong><?= formatCurrency($change['new_price']) ?></strong></td>
                                <td class="text-end <?= $diffClass ?>">
                                    <?= $priceDiff > 0 ? '+' : '' ?><?= formatCurrency($priceDiff) ?>
                                </td>
                                <td class="text-end <?= $diffClass ?>">
                                    <?= $change['percentage_change'] > 0 ? '+' : '' ?><?= number_format($change['percentage_change'], 2) ?>%
                                </td>
                                <td><?= escapeHtml($change['changed_by_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($change['change_reason'] ?? 'N/A') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    if ($.fn.DataTable && $('#priceChangeTable').length) {
        $('#priceChangeTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip'
        });
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


