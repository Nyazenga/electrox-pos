<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Product Sales by Staff Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", ];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

$whereClause = implode(' AND ', $whereConditions);

// Get summary
$summary = $db->getRow("SELECT 
    SUM(si.quantity) as total_sold_qty,
    COALESCE(SUM(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0))), 0) as total_discount,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as total_sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_sold_qty' => 0,
        'total_discount' => 0,
        'total_sales' => 0
    ];
}

// Get product sales by staff - select only needed columns (6 columns to match table)
$productSalesByStaff = $db->getRows("SELECT 
    u.first_name,
    u.last_name,
    si.product_name,
    p.product_code,
    SUM(si.quantity) as sold_qty,
    COALESCE(SUM(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0))), 0) as discount,
    COALESCE(SUM(si.total_price - COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0)), 0) as sales
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN users u ON s.user_id = u.id
WHERE $whereClause
GROUP BY u.first_name, u.last_name, si.product_name, p.product_code
ORDER BY u.last_name, u.first_name, sales DESC
LIMIT 1000", $params);

if ($productSalesByStaff === false) {
    $productSalesByStaff = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Product Sales by Staff Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Sold Qty</td><td style="text-align: right;">' . $summary['total_sold_qty'] . '</td></tr>';
    $html .= '<tr><td>Total Discount</td><td style="text-align: right;">' . formatCurrency($summary['total_discount']) . '</td></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_sales']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Product Sales by Staff</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Staff</th><th>Product</th><th>Code</th><th style="text-align: right;">Sold Qty</th><th style="text-align: right;">Discount</th><th style="text-align: right;">Sales</th></tr>';
    foreach ($productSalesByStaff as $ps) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml(($ps['first_name'] ?? '') . ' ' . ($ps['last_name'] ?? '')) . '</td>';
        $html .= '<td>' . escapeHtml($ps['product_name']) . '</td>';
        $html .= '<td>' . escapeHtml($ps['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . $ps['sold_qty'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($ps['discount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($ps['sales']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Product Sales by Staff Report', $html, 'Product_Sales_by_Staff_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-person-badge"></i> Product Sales by Staff Report</h2>
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
                <label class="form-label">&nbsp;</label>
                <div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                    <a href="product_sales_by_staff.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Sold Qty</h6>
                <h3 class="mb-0"><?= $summary['total_sold_qty'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Discount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_discount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_sales']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Product Sales by Staff</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="productSalesByStaffTable">
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th>Product</th>
                        <th>Code</th>
                        <th class="text-end">Sold Qty</th>
                        <th class="text-end">Discount</th>
                        <th class="text-end">Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productSalesByStaff)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productSalesByStaff as $ps): ?>
                            <tr>
                                <td><strong><?= escapeHtml(($ps['first_name'] ?? '') . ' ' . ($ps['last_name'] ?? '')) ?></strong></td>
                                <td><?= escapeHtml($ps['product_name']) ?></td>
                                <td><?= escapeHtml($ps['product_code'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $ps['sold_qty'] ?></td>
                                <td class="text-end"><?= formatCurrency($ps['discount']) ?></td>
                                <td class="text-end"><strong><?= formatCurrency($ps['sales']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Wait for jQuery to be available
    if (typeof jQuery === 'undefined') {
        console.error('jQuery is not loaded');
        return;
    }
    
    var $ = jQuery;
    
    if ($.fn.DataTable) {
        var table = $('#productSalesByStaffTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        table.DataTable({
            order: [[5, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
});
</script>

