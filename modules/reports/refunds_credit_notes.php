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

$pageTitle = 'Refunds & Credit Notes Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Build query conditions
$whereConditions = ["DATE(r.refund_date) BETWEEN :start_date AND :end_date", "r.status = 'completed'"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

$whereClause = implode(' AND ', $whereConditions);

// Get refunds - select only needed columns (6 columns to match table)
$refunds = $db->getRows("SELECT 
                        r.id, r.refund_number, r.refund_date, r.total_amount, r.reason,
                        s.receipt_number as sale_receipt,
                        c.first_name as customer_first, c.last_name as customer_last
                        FROM refunds r
                        LEFT JOIN sales s ON r.sale_id = s.id
                        LEFT JOIN customers c ON r.customer_id = c.id
                        LEFT JOIN users u ON r.user_id = u.id
                        LEFT JOIN branches b ON r.branch_id = b.id
                        WHERE $whereClause
                        ORDER BY r.refund_date DESC
                        LIMIT 1000", $params);

if ($refunds === false) {
    $refunds = [];
}

// Get credit notes (invoices with credit note type)
$creditNotes = $db->getRows("SELECT i.*,
                             c.first_name as customer_first, c.last_name as customer_last,
                             u.first_name as created_by_first, u.last_name as created_by_last,
                             b.branch_name
                             FROM invoices i
                             LEFT JOIN customers c ON i.customer_id = c.id
                             LEFT JOIN users u ON i.created_by = u.id
                             LEFT JOIN branches b ON i.branch_id = b.id
                             WHERE DATE(i.invoice_date) BETWEEN :start_date AND :end_date
                               AND i.invoice_type = 'credit'
                             ORDER BY i.invoice_date DESC
                             LIMIT 1000", $params);

if ($creditNotes === false) {
    $creditNotes = [];
}

// Get summary
$refundSummary = $db->getRow("SELECT 
    COUNT(DISTINCT r.id) as total_refunds,
    COALESCE(SUM(r.total_amount), 0) as total_refund_amount
FROM refunds r
WHERE $whereClause", $params);

if ($refundSummary === false) {
    $refundSummary = ['total_refunds' => 0, 'total_refund_amount' => 0];
}

$creditNoteSummary = $db->getRow("SELECT 
    COUNT(DISTINCT i.id) as total_credit_notes,
    COALESCE(SUM(i.total_amount), 0) as total_credit_amount
FROM invoices i
WHERE DATE(i.invoice_date) BETWEEN :start_date AND :end_date
  AND i.invoice_type = 'credit'", $params);

if ($creditNoteSummary === false) {
    $creditNoteSummary = ['total_credit_notes' => 0, 'total_credit_amount' => 0];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px; color: #dc3545;">Refunds & Credit Notes Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Refunds</td><td style="text-align: right;">' . $refundSummary['total_refunds'] . '</td></tr>';
    $html .= '<tr><td>Total Refund Amount</td><td style="text-align: right;">' . formatCurrency($refundSummary['total_refund_amount']) . '</td></tr>';
    $html .= '<tr><td>Total Credit Notes</td><td style="text-align: right;">' . $creditNoteSummary['total_credit_notes'] . '</td></tr>';
    $html .= '<tr><td>Total Credit Amount</td><td style="text-align: right;">' . formatCurrency($creditNoteSummary['total_credit_amount']) . '</td></tr>';
    $html .= '</table>';
    
    if (!empty($refunds)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Refunds</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Refund #</th><th>Sale Receipt</th><th>Date</th><th>Customer</th><th style="text-align: right;">Amount</th><th>Reason</th></tr>';
        foreach ($refunds as $refund) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($refund['refund_number']) . '</td>';
            $html .= '<td>' . escapeHtml($refund['sale_receipt'] ?? 'N/A') . '</td>';
            $html .= '<td>' . date('M d, Y', strtotime($refund['refund_date'])) . '</td>';
            $html .= '<td>' . escapeHtml(($refund['customer_first'] ?? 'Walk-in') . ' ' . ($refund['customer_last'] ?? '')) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($refund['total_amount']) . '</td>';
            $html .= '<td>' . escapeHtml($refund['reason'] ?? 'N/A') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    if (!empty($creditNotes)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Credit Notes</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Invoice #</th><th>Date</th><th>Customer</th><th style="text-align: right;">Amount</th><th>Status</th></tr>';
        foreach ($creditNotes as $cn) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($cn['invoice_number']) . '</td>';
            $html .= '<td>' . date('M d, Y', strtotime($cn['invoice_date'])) . '</td>';
            $html .= '<td>' . escapeHtml(($cn['customer_first'] ?? 'Walk-in') . ' ' . ($cn['customer_last'] ?? '')) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($cn['total_amount']) . '</td>';
            $html .= '<td>' . escapeHtml($cn['status']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    ReportHelper::generatePDF('Refunds & Credit Notes Report', $html, 'Refunds_Credit_Notes_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-file-earmark-minus text-danger"></i> Refunds & Credit Notes Report</h2>
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
                    <a href="refunds_credit_notes.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Refunds</h6>
                <h3 class="mb-0 text-danger"><?= $refundSummary['total_refunds'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Refund Amount</h6>
                <h3 class="mb-0 text-danger"><?= formatCurrency($refundSummary['total_refund_amount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Credit Notes</h6>
                <h3 class="mb-0 text-warning"><?= $creditNoteSummary['total_credit_notes'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Credit Amount</h6>
                <h3 class="mb-0 text-warning"><?= formatCurrency($creditNoteSummary['total_credit_amount']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-arrow-counterclockwise"></i> Refunds</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover data-table" id="refundsCreditNotesTable">
                        <thead>
                            <tr>
                                <th>Refund #</th>
                                <th>Sale Receipt</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th class="text-end">Amount</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($refunds)): ?>
                                <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                            <?php else: ?>
                                <?php foreach ($refunds as $refund): ?>
                                    <tr>
                                        <td><span class="badge bg-danger"><?= escapeHtml($refund['refund_number']) ?></span></td>
                                        <td><?= escapeHtml($refund['sale_receipt'] ?? 'N/A') ?></td>
                                        <td><?= date('M d, Y', strtotime($refund['refund_date'])) ?></td>
                                        <td><?= escapeHtml(($refund['customer_first'] ?? 'Walk-in') . ' ' . ($refund['customer_last'] ?? '')) ?></td>
                                        <td class="text-end text-danger"><strong><?= formatCurrency($refund['total_amount']) ?></strong></td>
                                        <td><?= escapeHtml($refund['reason'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-file-earmark-minus"></i> Credit Notes</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover data-table" id="creditNotesTable">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($creditNotes)): ?>
                                <tr>
                            <td></td><td></td><td></td><td></td><td></td>
                        </tr>
                            <?php else: ?>
                                <?php foreach ($creditNotes as $cn): ?>
                                    <tr>
                                        <td><span class="badge bg-warning text-dark"><?= escapeHtml($cn['invoice_number']) ?></span></td>
                                        <td><?= date('M d, Y', strtotime($cn['invoice_date'])) ?></td>
                                        <td><?= escapeHtml(($cn['customer_first'] ?? 'Walk-in') . ' ' . ($cn['customer_last'] ?? '')) ?></td>
                                        <td class="text-end text-warning"><strong><?= formatCurrency($cn['total_amount']) ?></strong></td>
                                        <td><span class="badge bg-<?= $cn['status'] === 'Paid' ? 'success' : 'secondary' ?>"><?= escapeHtml($cn['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
        var refundsTable = $('#refundsCreditNotesTable');
        var creditNotesTable = $('#creditNotesTable');
        
        if ($.fn.DataTable.isDataTable(refundsTable)) {
            refundsTable.DataTable().destroy();
        }
        refundsTable.DataTable({
            order: [[2, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
        
        if ($.fn.DataTable.isDataTable(creditNotesTable)) {
            creditNotesTable.DataTable().destroy();
        }
        creditNotesTable.DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
});
</script>

