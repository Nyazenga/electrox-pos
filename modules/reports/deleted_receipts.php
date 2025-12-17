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

$pageTitle = 'Deleted Receipts Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Check if deleted_at column exists
$hasDeletedAtColumn = false;
try {
    $colCheck = $db->getRow("SELECT COUNT(*) as count FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'sales' 
                            AND COLUMN_NAME = 'deleted_at'");
    $hasDeletedAtColumn = ($colCheck && $colCheck['count'] > 0);
} catch (Exception $e) {
    $hasDeletedAtColumn = false;
}

// Build query conditions - only get deleted receipts
$whereConditions = [
    "DATE(s.sale_date) BETWEEN :start_date AND :end_date"
];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

// Only add deleted_at filter if column exists
if ($hasDeletedAtColumn) {
    $whereConditions[] = "s.deleted_at IS NOT NULL";
} else {
    // If column doesn't exist, return empty (no deleted receipts yet)
    $whereConditions[] = "1 = 0"; // Always false - no results
}

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

// Get deleted receipts
$orderBy = $hasDeletedAtColumn ? "s.deleted_at DESC" : "s.updated_at DESC";
$deletedReceipts = $db->getRows("SELECT s.receipt_number, s.sale_date, s.total_amount, s.discount_amount, " . 
                                 ($hasDeletedAtColumn ? "s.deleted_at" : "s.updated_at as deleted_at") . ",
                                 c.first_name as customer_first, c.last_name as customer_last,
                                 u.first_name as deleted_by_first, u.last_name as deleted_by_last,
                                 b.branch_name
                                 FROM sales s
                                 LEFT JOIN customers c ON s.customer_id = c.id
                                 LEFT JOIN users u ON " . ($hasDeletedAtColumn ? "s.deleted_by" : "s.user_id") . " = u.id
                                 LEFT JOIN branches b ON s.branch_id = b.id
                                 WHERE $whereClause
                                 ORDER BY $orderBy
                                 LIMIT 1000", $params);

if ($deletedReceipts === false) {
    $deletedReceipts = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Deleted Receipts Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Deleted Receipts</td><td style="text-align: right;">' . $summary['total_deleted_receipts'] . '</td></tr>';
    $html .= '<tr><td>Total Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_amount']) . '</td></tr>';
    $html .= '<tr><td>Total Discount</td><td style="text-align: right;">' . formatCurrency($summary['total_discount']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Deleted Receipts</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Receipt #</th><th>Date</th><th>Customer</th><th>Branch</th><th style="text-align: right;">Amount</th><th style="text-align: right;">Discount</th><th>Deleted By</th><th>Deleted At</th></tr>';
    foreach ($deletedReceipts as $receipt) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($receipt['receipt_number']) . '</td>';
        $html .= '<td>' . date('M d, Y', strtotime($receipt['sale_date'])) . '</td>';
        $html .= '<td>' . escapeHtml(($receipt['customer_first'] ?? 'Walk-in') . ' ' . ($receipt['customer_last'] ?? '')) . '</td>';
        $html .= '<td>' . escapeHtml($receipt['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($receipt['total_amount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($receipt['discount_amount']) . '</td>';
        $html .= '<td>' . escapeHtml(($receipt['deleted_by_first'] ?? 'N/A') . ' ' . ($receipt['deleted_by_last'] ?? '')) . '</td>';
        $html .= '<td>' . ($receipt['deleted_at'] ? date('M d, Y H:i', strtotime($receipt['deleted_at'])) : 'N/A') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Deleted Receipts Report', $html, 'Deleted_Receipts_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-trash"></i> Deleted Receipts Report</h2>
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
                    <a href="deleted_receipts.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
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

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Deleted Receipts</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="deletedReceiptsTable">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Branch</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Discount</th>
                        <th>Deleted By</th>
                        <th>Deleted At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deletedReceipts)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($deletedReceipts as $receipt): ?>
                            <tr>
                                <td><span class="badge bg-danger"><?= escapeHtml($receipt['receipt_number']) ?></span></td>
                                <td><?= date('M d, Y', strtotime($receipt['sale_date'])) ?></td>
                                <td><?= escapeHtml(($receipt['customer_first'] ?? 'Walk-in') . ' ' . ($receipt['customer_last'] ?? '')) ?></td>
                                <td><?= escapeHtml($receipt['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end text-danger"><?= formatCurrency($receipt['total_amount']) ?></td>
                                <td class="text-end"><?= formatCurrency($receipt['discount_amount']) ?></td>
                                <td><?= escapeHtml(($receipt['deleted_by_first'] ?? 'N/A') . ' ' . ($receipt['deleted_by_last'] ?? '')) ?></td>
                                <td><?= $receipt['deleted_at'] ? date('M d, Y H:i', strtotime($receipt['deleted_at'])) : 'N/A' ?></td>
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
        var table = $('#deletedReceiptsTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        table.DataTable({
            order: [[7, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
});
</script>

