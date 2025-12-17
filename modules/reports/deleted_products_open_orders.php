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

$pageTitle = 'Deleted Products in Open Orders Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Build query conditions
// Find sale items where the product has been deleted (status = 'deleted' or doesn't exist)
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", "s.payment_status = 'pending'"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

$whereClause = implode(' AND ', $whereConditions);

// Get deleted products in open orders - select only needed columns (11 columns to match table)
$deletedProductsOrders = $db->getRows("SELECT 
    s.id as sale_id,
    s.receipt_number,
    DATE(s.sale_date) as sale_date,
    si.product_id,
    si.product_name,
    si.quantity,
    si.unit_price,
    si.total_price,
    CASE WHEN p.id IS NULL OR p.status = 'deleted' THEN 'Deleted' ELSE 'Active' END as product_status,
    c.first_name as customer_first, c.last_name as customer_last,
    b.branch_name
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN customers c ON s.customer_id = c.id
LEFT JOIN users u ON s.user_id = u.id
LEFT JOIN branches b ON s.branch_id = b.id
WHERE $whereClause
  AND (p.id IS NULL OR p.status = 'deleted')
ORDER BY s.sale_date DESC
LIMIT 1000", $params);

if ($deletedProductsOrders === false) {
    $deletedProductsOrders = [];
}

// Get summary
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_orders,
    COUNT(si.id) as total_items,
    COALESCE(SUM(si.total_price), 0) as total_amount
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause
  AND (p.id IS NULL OR p.status = 'deleted')", $params);

if ($summary === false) {
    $summary = [
        'total_orders' => 0,
        'total_items' => 0,
        'total_amount' => 0
    ];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px; color: #dc3545;">Deleted Products in Open Orders Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Orders</td><td style="text-align: right;">' . $summary['total_orders'] . '</td></tr>';
    $html .= '<tr><td>Total Items</td><td style="text-align: right;">' . $summary['total_items'] . '</td></tr>';
    $html .= '<tr><td>Total Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_amount']) . '</td></tr>';
    $html .= '</table>';
    
    if (!empty($deletedProductsOrders)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Deleted Products in Open Orders</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Receipt #</th><th>Date</th><th>Product</th><th>Product ID</th><th>Status</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Unit Price</th><th style="text-align: right;">Total</th><th>Customer</th><th>Branch</th></tr>';
        foreach ($deletedProductsOrders as $dpo) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($dpo['receipt_number']) . '</td>';
            $html .= '<td>' . date('M d, Y', strtotime($dpo['sale_date'])) . '</td>';
            $html .= '<td>' . escapeHtml($dpo['product_name']) . '</td>';
            $html .= '<td>' . ($dpo['product_id'] ?? 'N/A') . '</td>';
            $html .= '<td><span style="color: #dc3545; font-weight: bold;">' . escapeHtml($dpo['product_status']) . '</span></td>';
            $html .= '<td style="text-align: right;">' . $dpo['quantity'] . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($dpo['unit_price']) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($dpo['total_price']) . '</td>';
            $html .= '<td>' . escapeHtml(($dpo['customer_first'] ?? 'Walk-in') . ' ' . ($dpo['customer_last'] ?? '')) . '</td>';
            $html .= '<td>' . escapeHtml($dpo['branch_name'] ?? 'N/A') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    ReportHelper::generatePDF('Deleted Products in Open Orders Report', $html, 'Deleted_Products_Open_Orders_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-exclamation-circle text-danger"></i> Deleted Products in Open Orders Report</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4 border-danger">
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
                    <a href="deleted_products_open_orders.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-danger" role="alert">
    <i class="bi bi-exclamation-triangle"></i> <strong>Warning:</strong> This report shows products that have been deleted but still exist in open (pending) orders. These orders may need attention.
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Orders</h6>
                <h3 class="mb-0 text-danger"><?= $summary['total_orders'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Items</h6>
                <h3 class="mb-0 text-danger"><?= $summary['total_items'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Amount</h6>
                <h3 class="mb-0 text-danger"><?= formatCurrency($summary['total_amount']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card border-danger">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-exclamation-circle"></i> Deleted Products in Open Orders</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="deletedProductsOpenOrdersTable">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Product ID</th>
                        <th>Status</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Total</th>
                        <th>Customer</th>
                        <th>Branch</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deletedProductsOrders)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($deletedProductsOrders as $dpo): ?>
                            <tr>
                                <td><span class="badge bg-danger"><?= escapeHtml($dpo['receipt_number']) ?></span></td>
                                <td><?= date('M d, Y', strtotime($dpo['sale_date'])) ?></td>
                                <td><strong><?= escapeHtml($dpo['product_name']) ?></strong></td>
                                <td><?= $dpo['product_id'] ?? 'N/A' ?></td>
                                <td><span class="badge bg-danger"><?= escapeHtml($dpo['product_status']) ?></span></td>
                                <td class="text-end"><?= $dpo['quantity'] ?></td>
                                <td class="text-end"><?= formatCurrency($dpo['unit_price']) ?></td>
                                <td class="text-end text-danger"><strong><?= formatCurrency($dpo['total_price']) ?></strong></td>
                                <td><?= escapeHtml(($dpo['customer_first'] ?? 'Walk-in') . ' ' . ($dpo['customer_last'] ?? '')) ?></td>
                                <td><?= escapeHtml($dpo['branch_name'] ?? 'N/A') ?></td>
                                <td><a href="<?= BASE_URL ?>modules/pos/receipt.php?id=<?= $dpo['sale_id'] ?>" class="btn btn-sm btn-outline-danger" target="_blank"><i class="bi bi-eye"></i></a></td>
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
        var table = $('#deletedProductsOpenOrdersTable');
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

