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

$pageTitle = 'Products Consumed by Staff Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Build query conditions
// Products consumed by staff are typically those sold to staff members or internal consumption
// We'll check for sales where customer is a staff member or has special flags
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", ];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

$whereClause = implode(' AND ', $whereConditions);

// Get products consumed by staff - select only needed columns (5 columns to match table)
$productsConsumed = $db->getRows("SELECT 
    u.first_name,
    u.last_name,
    si.product_name,
    p.product_code,
    SUM(si.quantity) as consumed_qty,
    COALESCE(SUM(si.total_price), 0) as total_value
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN users u ON s.user_id = u.id
WHERE $whereClause
  AND (s.notes LIKE '%staff%' OR s.notes LIKE '%internal%' OR s.notes LIKE '%consumption%')
GROUP BY u.first_name, u.last_name, si.product_name, p.product_code
ORDER BY u.last_name, u.first_name, consumed_qty DESC
LIMIT 1000", $params);

if ($productsConsumed === false) {
    $productsConsumed = [];
}

// Get summary
$summary = $db->getRow("SELECT 
    SUM(si.quantity) as total_consumed_qty,
    COALESCE(SUM(si.total_price), 0) as total_value
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
WHERE $whereClause
  AND (s.notes LIKE '%staff%' OR s.notes LIKE '%internal%' OR s.notes LIKE '%consumption%')", $params);

if ($summary === false) {
    $summary = [
        'total_consumed_qty' => 0,
        'total_value' => 0
    ];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Products Consumed by Staff Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Consumed Qty</td><td style="text-align: right;">' . $summary['total_consumed_qty'] . '</td></tr>';
    $html .= '<tr><td>Total Value</td><td style="text-align: right;">' . formatCurrency($summary['total_value']) . '</td></tr>';
    $html .= '</table>';
    
    if (!empty($productsConsumed)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Products Consumed by Staff</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Staff</th><th>Product</th><th>Code</th><th style="text-align: right;">Consumed Qty</th><th style="text-align: right;">Total Value</th></tr>';
        foreach ($productsConsumed as $pc) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml(($pc['first_name'] ?? '') . ' ' . ($pc['last_name'] ?? '')) . '</td>';
            $html .= '<td>' . escapeHtml($pc['product_name']) . '</td>';
            $html .= '<td>' . escapeHtml($pc['product_code'] ?? 'N/A') . '</td>';
            $html .= '<td style="text-align: right;">' . $pc['consumed_qty'] . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($pc['total_value']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    ReportHelper::generatePDF('Products Consumed by Staff Report', $html, 'Products_Consumed_by_Staff_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-person-dash"></i> Products Consumed by Staff Report</h2>
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
                    <a href="products_consumed_by_staff.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Consumed Qty</h6>
                <h3 class="mb-0"><?= $summary['total_consumed_qty'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Value</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_value']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Products Consumed by Staff</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="productsConsumedByStaffTable">
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th>Product</th>
                        <th>Code</th>
                        <th class="text-end">Consumed Qty</th>
                        <th class="text-end">Total Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productsConsumed)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productsConsumed as $pc): ?>
                            <tr>
                                <td><strong><?= escapeHtml(($pc['first_name'] ?? '') . ' ' . ($pc['last_name'] ?? '')) ?></strong></td>
                                <td><?= escapeHtml($pc['product_name']) ?></td>
                                <td><?= escapeHtml($pc['product_code'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $pc['consumed_qty'] ?></td>
                                <td class="text-end"><strong><?= formatCurrency($pc['total_value']) ?></strong></td>
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
        var table = $('#productsConsumedByStaffTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        table.DataTable({
            order: [[4, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
});
</script>

