<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.stock_purchases_product');

$pageTitle = 'View Stock Purchases for Product';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedProduct = $_GET['product_id'] ?? '';
$search = $_GET['search'] ?? '';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get products
$productWhere = "p.status = 'Active'";
$productParams = [];
if (!empty($search)) {
    $productWhere .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";
    $productParams[':search'] = '%' . $search . '%';
}
$products = $db->getRows("SELECT id, product_code, product_name, brand, model FROM products p WHERE $productWhere ORDER BY p.product_code LIMIT 500", $productParams);
if ($products === false) $products = [];

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

if (!empty($selectedProduct)) {
    $whereConditions[] = "gi.product_id = :product_id";
    $params[':product_id'] = $selectedProduct;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT g.id) as total_grns,
    COUNT(DISTINCT gi.product_id) as unique_products,
    COALESCE(SUM(gi.quantity), 0) as total_quantity,
    COALESCE(SUM(gi.quantity * gi.cost_price), 0) as total_cost
FROM grn_items gi
INNER JOIN goods_received_notes g ON gi.grn_id = g.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_grns' => 0,
        'unique_products' => 0,
        'total_quantity' => 0,
        'total_cost' => 0
    ];
}

// Get purchase items
$purchases = $db->getRows("SELECT 
    gi.id,
    gi.quantity,
    gi.cost_price,
    gi.selling_price,
    (gi.quantity * gi.cost_price) as total_cost,
    g.grn_number,
    g.created_at,
    g.received_date,
    g.status,
    p.product_code,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    s.name as supplier_name,
    b.branch_name,
    u.first_name,
    u.last_name
FROM grn_items gi
INNER JOIN goods_received_notes g ON gi.grn_id = g.id
LEFT JOIN products p ON gi.product_id = p.id
LEFT JOIN suppliers s ON g.supplier_id = s.id
LEFT JOIN branches b ON g.branch_id = b.id
LEFT JOIN users u ON g.created_by = u.id
WHERE $whereClause
ORDER BY g.created_at DESC, p.product_code
LIMIT 2000", $params);

if ($purchases === false) {
    $purchases = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Stock Purchases for Product</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total GRNs</td><td style="text-align: right;">' . $summary['total_grns'] . '</td></tr>';
    $html .= '<tr><td>Unique Products</td><td style="text-align: right;">' . $summary['unique_products'] . '</td></tr>';
    $html .= '<tr><td>Total Quantity</td><td style="text-align: right;">' . number_format($summary['total_quantity'], 2) . '</td></tr>';
    $html .= '<tr><td>Total Cost</td><td style="text-align: right;">' . formatCurrency($summary['total_cost']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Purchase Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Date</th><th>GRN Number</th><th>Product</th><th>Supplier</th><th>Branch</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Cost Price</th><th style="text-align: right;">Selling Price</th><th style="text-align: right;">Total Cost</th><th>Status</th></tr>';
    foreach ($purchases as $purchase) {
        $html .= '<tr>';
        $html .= '<td>' . date('M d, Y', strtotime($purchase['received_date'] ?? $purchase['created_at'])) . '</td>';
        $html .= '<td>' . escapeHtml($purchase['grn_number']) . '</td>';
        $html .= '<td>' . escapeHtml($purchase['product_code'] ?? 'N/A') . ' - ' . escapeHtml(substr($purchase['product_name'] ?? 'N/A', 0, 20)) . '</td>';
        $html .= '<td>' . escapeHtml($purchase['supplier_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($purchase['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($purchase['quantity'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($purchase['cost_price']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($purchase['selling_price']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($purchase['total_cost']) . '</td>';
        $html .= '<td>' . escapeHtml($purchase['status']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Stock Purchases for Product', $html, 'Stock_Purchases_Product_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cart-check"></i> View Stock Purchases for Product</h2>
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
                <select name="product_id" class="form-select" id="productSelect">
                    <option value="">All Products</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['id'] ?>" <?= $selectedProduct == $product['id'] ? 'selected' : '' ?>>
                            <?= escapeHtml($product['product_code'] ?? 'N/A') ?> - <?= escapeHtml(substr($product['product_name'] ?? ($product['brand'] . ' ' . $product['model']), 0, 30)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-search"></i> Search</label>
                <input type="text" name="search" value="<?= escapeHtml($search) ?>" class="form-control" placeholder="Search products...">
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
                <h6 class="text-muted mb-2">Unique Products</h6>
                <h3 class="mb-0"><?= $summary['unique_products'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Quantity</h6>
                <h3 class="mb-0"><?= number_format($summary['total_quantity'], 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Cost</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_cost']) ?></h3>
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
                        <th>Date</th>
                        <th>GRN Number</th>
                        <th>Product</th>
                        <th>Supplier</th>
                        <th>Branch</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Cost Price</th>
                        <th class="text-end">Selling Price</th>
                        <th class="text-end">Total Cost</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchases)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">No purchases found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchases as $purchase): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($purchase['received_date'] ?? $purchase['created_at'])) ?></td>
                                <td><span class="fw-bold"><?= escapeHtml($purchase['grn_number']) ?></span></td>
                                <td>
                                    <div class="fw-bold"><?= escapeHtml($purchase['product_code'] ?? 'N/A') ?></div>
                                    <small class="text-muted"><?= escapeHtml(substr($purchase['product_name'] ?? 'N/A', 0, 30)) ?></small>
                                </td>
                                <td><?= escapeHtml($purchase['supplier_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($purchase['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= number_format($purchase['quantity'], 2) ?></td>
                                <td class="text-end"><?= formatCurrency($purchase['cost_price']) ?></td>
                                <td class="text-end"><?= formatCurrency($purchase['selling_price']) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($purchase['total_cost']) ?></td>
                                <td>
                                    <?php if ($purchase['status'] == 'Approved'): ?>
                                        <span class="badge bg-success">Approved</span>
                                    <?php elseif ($purchase['status'] == 'Pending'): ?>
                                        <span class="badge bg-warning">Pending</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= escapeHtml($purchase['status']) ?></span>
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

<script>
$(document).ready(function() {
    $('#purchasesTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


