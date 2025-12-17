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

$pageTitle = 'Ecommerce Sales Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Build query conditions
// Ecommerce sales are typically those made through online channels
// We'll identify them by checking payment method or notes
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", ];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

$whereClause = implode(' AND ', $whereConditions);

// Get ecommerce sales - select only needed columns (8 columns to match table, but keep s.id for view link)
$ecommerceSales = $db->getRows("SELECT 
    s.id, s.receipt_number, s.sale_date, s.total_amount, s.discount_amount, s.payment_status,
    c.first_name as customer_first, c.last_name as customer_last,
    b.branch_name
FROM sales s
LEFT JOIN customers c ON s.customer_id = c.id
LEFT JOIN users u ON s.user_id = u.id
LEFT JOIN branches b ON s.branch_id = b.id
WHERE $whereClause
  AND (s.notes LIKE '%online%' OR s.notes LIKE '%ecommerce%' OR s.notes LIKE '%web%' 
       OR EXISTS (SELECT 1 FROM sale_payments sp WHERE sp.sale_id = s.id AND sp.payment_method IN ('online', 'paypal', 'stripe', 'card')))
ORDER BY s.sale_date DESC
LIMIT 1000", $params);

if ($ecommerceSales === false) {
    $ecommerceSales = [];
}

// Get summary
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_orders,
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(SUM(s.discount_amount), 0) as total_discount
FROM sales s
WHERE $whereClause
  AND (s.notes LIKE '%online%' OR s.notes LIKE '%ecommerce%' OR s.notes LIKE '%web%' 
       OR EXISTS (SELECT 1 FROM sale_payments sp WHERE sp.sale_id = s.id AND sp.payment_method IN ('online', 'paypal', 'stripe', 'card')))", $params);

if ($summary === false) {
    $summary = [
        'total_orders' => 0,
        'total_sales' => 0,
        'total_discount' => 0
    ];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Ecommerce Sales Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Orders</td><td style="text-align: right;">' . $summary['total_orders'] . '</td></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_sales']) . '</td></tr>';
    $html .= '<tr><td>Total Discount</td><td style="text-align: right;">' . formatCurrency($summary['total_discount']) . '</td></tr>';
    $html .= '</table>';
    
    if (!empty($ecommerceSales)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Ecommerce Sales</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Receipt #</th><th>Date</th><th>Customer</th><th>Branch</th><th style="text-align: right;">Amount</th><th style="text-align: right;">Discount</th><th>Status</th></tr>';
        foreach ($ecommerceSales as $es) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($es['receipt_number']) . '</td>';
            $html .= '<td>' . date('M d, Y', strtotime($es['sale_date'])) . '</td>';
            $html .= '<td>' . escapeHtml(($es['customer_first'] ?? 'Walk-in') . ' ' . ($es['customer_last'] ?? '')) . '</td>';
            $html .= '<td>' . escapeHtml($es['branch_name'] ?? 'N/A') . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($es['total_amount']) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($es['discount_amount']) . '</td>';
            $html .= '<td>' . escapeHtml($es['payment_status']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    ReportHelper::generatePDF('Ecommerce Sales Report', $html, 'Ecommerce_Sales_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-globe"></i> Ecommerce Sales Report</h2>
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
                    <a href="ecommerce_sales.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Orders</h6>
                <h3 class="mb-0"><?= $summary['total_orders'] ?></h3>
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
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Discount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_discount']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Ecommerce Sales</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="ecommerceSalesTable">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Branch</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Discount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ecommerceSales)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ecommerceSales as $es): ?>
                            <tr>
                                <td><?= escapeHtml($es['receipt_number']) ?></td>
                                <td><?= date('M d, Y', strtotime($es['sale_date'])) ?></td>
                                <td><?= escapeHtml(($es['customer_first'] ?? 'Walk-in') . ' ' . ($es['customer_last'] ?? '')) ?></td>
                                <td><?= escapeHtml($es['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= formatCurrency($es['total_amount']) ?></td>
                                <td class="text-end"><?= formatCurrency($es['discount_amount']) ?></td>
                                <td><span class="badge bg-<?= $es['payment_status'] === 'paid' ? 'success' : 'warning' ?>"><?= escapeHtml($es['payment_status']) ?></span></td>
                                <td><a href="<?= BASE_URL ?>modules/pos/receipt.php?id=<?= $es['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-eye"></i></a></td>
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
        var table = $('#ecommerceSalesTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        table.DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
});
</script>

