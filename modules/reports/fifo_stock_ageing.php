<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.fifo_stock_ageing');

$pageTitle = 'FIFO Stock Ageing Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCategory = $_GET['category_id'] ?? 'all';
$ageFilter = $_GET['age_filter'] ?? 'all'; // all, 0-30, 31-60, 61-90, 90+
$search = $_GET['search'] ?? '';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Build query conditions
$whereConditions = ["p.status = 'Active'", "p.quantity_in_stock > 0"];
$params = [];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedCategory !== 'all' && $selectedCategory) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $selectedCategory;
}

if (!empty($search)) {
    $whereConditions[] = "(p.product_name LIKE :search OR p.product_code LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$whereClause = implode(' AND ', $whereConditions);

// Get stock ageing data - using last purchase date or created date as proxy for FIFO
$stockAgeing = $db->getRows("SELECT 
    p.id,
    p.product_code,
    COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
    pc.name as category_name,
    b.branch_name,
    p.quantity_in_stock,
    p.cost_price,
    (p.quantity_in_stock * p.cost_price) as stock_value,
    COALESCE(
        (SELECT MAX(g.received_date) FROM goods_received_notes g 
         INNER JOIN grn_items gi ON g.id = gi.grn_id 
         WHERE gi.product_id = p.id AND g.status = 'Approved'),
        p.created_at
    ) as last_purchase_date,
    DATEDIFF(CURDATE(), COALESCE(
        (SELECT MAX(g.received_date) FROM goods_received_notes g 
         INNER JOIN grn_items gi ON g.id = gi.grn_id 
         WHERE gi.product_id = p.id AND g.status = 'Approved'),
        p.created_at
    )) as days_old
FROM products p
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN branches b ON p.branch_id = b.id
WHERE $whereClause
ORDER BY days_old DESC, p.product_code
LIMIT 2000", $params);

if ($stockAgeing === false) {
    $stockAgeing = [];
}

// Filter by age if specified
if ($ageFilter !== 'all') {
    $filteredStock = [];
    foreach ($stockAgeing as $item) {
        $days = $item['days_old'];
        if ($ageFilter === '0-30' && $days >= 0 && $days <= 30) {
            $filteredStock[] = $item;
        } elseif ($ageFilter === '31-60' && $days >= 31 && $days <= 60) {
            $filteredStock[] = $item;
        } elseif ($ageFilter === '61-90' && $days >= 61 && $days <= 90) {
            $filteredStock[] = $item;
        } elseif ($ageFilter === '90+' && $days > 90) {
            $filteredStock[] = $item;
        }
    }
    $stockAgeing = $filteredStock;
}

// Calculate age buckets
$ageBuckets = [
    '0-30' => ['count' => 0, 'quantity' => 0, 'value' => 0],
    '31-60' => ['count' => 0, 'quantity' => 0, 'value' => 0],
    '61-90' => ['count' => 0, 'quantity' => 0, 'value' => 0],
    '90+' => ['count' => 0, 'quantity' => 0, 'value' => 0]
];

foreach ($stockAgeing as $item) {
    $days = $item['days_old'];
    if ($days >= 0 && $days <= 30) {
        $ageBuckets['0-30']['count']++;
        $ageBuckets['0-30']['quantity'] += $item['quantity_in_stock'];
        $ageBuckets['0-30']['value'] += $item['stock_value'];
    } elseif ($days >= 31 && $days <= 60) {
        $ageBuckets['31-60']['count']++;
        $ageBuckets['31-60']['quantity'] += $item['quantity_in_stock'];
        $ageBuckets['31-60']['value'] += $item['stock_value'];
    } elseif ($days >= 61 && $days <= 90) {
        $ageBuckets['61-90']['count']++;
        $ageBuckets['61-90']['quantity'] += $item['quantity_in_stock'];
        $ageBuckets['61-90']['value'] += $item['stock_value'];
    } else {
        $ageBuckets['90+']['count']++;
        $ageBuckets['90+']['quantity'] += $item['quantity_in_stock'];
        $ageBuckets['90+']['value'] += $item['stock_value'];
    }
}

// Get summary stats
$summary = [
    'total_products' => count($stockAgeing),
    'total_quantity' => array_sum(array_column($stockAgeing, 'quantity_in_stock')),
    'total_value' => array_sum(array_column($stockAgeing, 'stock_value'))
];

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">FIFO Stock Ageing Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Generated: ' . date('M d, Y H:i') . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Age Bucket</th><th style="text-align: right;">Products</th><th style="text-align: right;">Quantity</th><th style="text-align: right;">Value</th></tr>';
    foreach ($ageBuckets as $bucket => $data) {
        $html .= '<tr>';
        $html .= '<td>' . $bucket . ' days</td>';
        $html .= '<td style="text-align: right;">' . $data['count'] . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($data['quantity'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($data['value']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '<tr style="background-color: #e0e0e0; font-weight: bold;">';
    $html .= '<td>Total</td>';
    $html .= '<td style="text-align: right;">' . $summary['total_products'] . '</td>';
    $html .= '<td style="text-align: right;">' . number_format($summary['total_quantity'], 2) . '</td>';
    $html .= '<td style="text-align: right;">' . formatCurrency($summary['total_value']) . '</td>';
    $html .= '</tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Stock Ageing Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product Code</th><th>Product Name</th><th>Category</th><th>Branch</th><th>Last Purchase</th><th>Days Old</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Value</th></tr>';
    foreach ($stockAgeing as $item) {
        $ageColor = $item['days_old'] > 90 ? '#dc2626' : ($item['days_old'] > 60 ? '#f97316' : ($item['days_old'] > 30 ? '#f59e0b' : '#10b981'));
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($item['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml(substr($item['product_name'] ?? 'N/A', 0, 25)) . '</td>';
        $html .= '<td>' . escapeHtml($item['category_name'] ?? 'Uncategorized') . '</td>';
        $html .= '<td>' . escapeHtml($item['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . date('M d, Y', strtotime($item['last_purchase_date'])) . '</td>';
        $html .= '<td style="color: ' . $ageColor . '; font-weight: bold;">' . $item['days_old'] . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($item['quantity_in_stock'], 2) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($item['stock_value']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('FIFO Stock Ageing Report', $html, 'FIFO_Stock_Ageing_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clock-history"></i> FIFO Stock Ageing Report</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
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
                <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                <select name="category_id" class="form-select">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $selectedCategory == $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-filter"></i> Age Filter</label>
                <select name="age_filter" class="form-select">
                    <option value="all" <?= $ageFilter === 'all' ? 'selected' : '' ?>>All Ages</option>
                    <option value="0-30" <?= $ageFilter === '0-30' ? 'selected' : '' ?>>0-30 days</option>
                    <option value="31-60" <?= $ageFilter === '31-60' ? 'selected' : '' ?>>31-60 days</option>
                    <option value="61-90" <?= $ageFilter === '61-90' ? 'selected' : '' ?>>61-90 days</option>
                    <option value="90+" <?= $ageFilter === '90+' ? 'selected' : '' ?>>90+ days</option>
                </select>
            </div>
            <div class="col-md-4">
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
                <h6 class="text-muted mb-2">0-30 Days</h6>
                <h3 class="mb-0 text-success"><?= $ageBuckets['0-30']['count'] ?></h3>
                <small class="text-muted"><?= formatCurrency($ageBuckets['0-30']['value']) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">31-60 Days</h6>
                <h3 class="mb-0 text-warning"><?= $ageBuckets['31-60']['count'] ?></h3>
                <small class="text-muted"><?= formatCurrency($ageBuckets['31-60']['value']) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">61-90 Days</h6>
                <h3 class="mb-0 text-warning"><?= $ageBuckets['61-90']['count'] ?></h3>
                <small class="text-muted"><?= formatCurrency($ageBuckets['61-90']['value']) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">90+ Days</h6>
                <h3 class="mb-0 text-danger"><?= $ageBuckets['90+']['count'] ?></h3>
                <small class="text-muted"><?= formatCurrency($ageBuckets['90+']['value']) ?></small>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="ageingTable">
                <thead>
                    <tr>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Branch</th>
                        <th>Last Purchase</th>
                        <th class="text-end">Days Old</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockAgeing)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No stock found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stockAgeing as $item): ?>
                            <tr>
                                <td><span class="fw-bold"><?= escapeHtml($item['product_code'] ?? 'N/A') ?></span></td>
                                <td><?= escapeHtml($item['product_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($item['category_name'] ?? 'Uncategorized') ?></td>
                                <td><?= escapeHtml($item['branch_name'] ?? 'N/A') ?></td>
                                <td><?= date('M d, Y', strtotime($item['last_purchase_date'])) ?></td>
                                <td class="text-end">
                                    <span class="badge bg-<?= $item['days_old'] > 90 ? 'danger' : ($item['days_old'] > 60 ? 'warning' : ($item['days_old'] > 30 ? 'info' : 'success')) ?>">
                                        <?= $item['days_old'] ?> days
                                    </span>
                                </td>
                                <td class="text-end"><?= number_format($item['quantity_in_stock'], 2) ?></td>
                                <td class="text-end fw-bold"><?= formatCurrency($item['stock_value']) ?></td>
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
    $('#ageingTable').DataTable({
        order: [[5, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


