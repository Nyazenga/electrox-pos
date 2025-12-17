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

$pageTitle = 'Product Wise Deleted Receipts Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Build query conditions - Deleted receipts
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", ];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

$whereClause = implode(' AND ', $whereConditions);

// Get summary
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_deleted_receipts,
    COALESCE(SUM(s.total_amount), 0) as total_amount,
    COALESCE(SUM(s.discount_amount), 0) as total_discount
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_deleted_receipts' => 0,
        'total_amount' => 0,
        'total_discount' => 0
    ];
}

// Get product wise deleted receipts
$productDeletedReceipts = $db->getRows("SELECT 
    si.product_id,
    si.product_name,
    p.product_code,
    pc.name as category_name,
    COUNT(DISTINCT s.id) as deleted_receipt_count,
    SUM(si.quantity) as deleted_qty,
    COALESCE(SUM(si.total_price), 0) as deleted_amount,
    COALESCE(SUM(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0))), 0) as deleted_discount
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE $whereClause
GROUP BY si.product_id, si.product_name, p.product_code, pc.name
ORDER BY deleted_amount DESC
LIMIT 1000", $params);

if ($productDeletedReceipts === false) {
    $productDeletedReceipts = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px; color: #dc3545;">Product Wise Deleted Receipts Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Deleted Receipts</td><td style="text-align: right;">' . $summary['total_deleted_receipts'] . '</td></tr>';
    $html .= '<tr><td>Total Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_amount']) . '</td></tr>';
    $html .= '<tr><td>Total Discount</td><td style="text-align: right;">' . formatCurrency($summary['total_discount']) . '</td></tr>';
    $html .= '</table>';
    
    if (!empty($productDeletedReceipts)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Product Wise Deleted Receipts</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Product</th><th>Code</th><th>Category</th><th style="text-align: right;">Deleted Receipts</th><th style="text-align: right;">Deleted Qty</th><th style="text-align: right;">Deleted Amount</th><th style="text-align: right;">Deleted Discount</th></tr>';
        foreach ($productDeletedReceipts as $pdr) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($pdr['product_name']) . '</td>';
            $html .= '<td>' . escapeHtml($pdr['product_code'] ?? 'N/A') . '</td>';
            $html .= '<td>' . escapeHtml($pdr['category_name'] ?? 'N/A') . '</td>';
            $html .= '<td style="text-align: right;">' . $pdr['deleted_receipt_count'] . '</td>';
            $html .= '<td style="text-align: right;">' . $pdr['deleted_qty'] . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($pdr['deleted_amount']) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($pdr['deleted_discount']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    ReportHelper::generatePDF('Product Wise Deleted Receipts Report', $html, 'Product_Wise_Deleted_Receipts_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-exclamation-triangle text-danger"></i> Product Wise Deleted Receipts Report</h2>
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
                    <a href="product_wise_deleted_receipts.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Deleted Receipts</h6>
                <h3 class="mb-0 text-danger"><?= $summary['total_deleted_receipts'] ?></h3>
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
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Discount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_discount']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card border-danger">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Product Wise Deleted Receipts</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="productWiseDeletedReceiptsTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Code</th>
                        <th>Category</th>
                        <th class="text-end">Deleted Receipts</th>
                        <th class="text-end">Deleted Qty</th>
                        <th class="text-end">Deleted Amount</th>
                        <th class="text-end">Deleted Discount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productDeletedReceipts)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productDeletedReceipts as $pdr): ?>
                            <tr>
                                <td><?= escapeHtml($pdr['product_name']) ?></td>
                                <td><?= escapeHtml($pdr['product_code'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($pdr['category_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><span class="badge bg-danger"><?= $pdr['deleted_receipt_count'] ?></span></td>
                                <td class="text-end text-danger"><?= $pdr['deleted_qty'] ?></td>
                                <td class="text-end text-danger"><strong><?= formatCurrency($pdr['deleted_amount']) ?></strong></td>
                                <td class="text-end"><?= formatCurrency($pdr['deleted_discount']) ?></td>
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
        var table = $('#productWiseDeletedReceiptsTable');
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

