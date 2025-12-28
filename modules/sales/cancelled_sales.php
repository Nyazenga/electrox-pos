<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('sales.view', 'sales.refund', 'receipts.refund', 'reports.refunds');

$pageTitle = 'Cancelled Sales / Refunds';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCashier = $_GET['user_id'] ?? 'all';
$search = $_GET['search'] ?? '';

// Get filter options
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

$cashiers = $db->getRows("SELECT DISTINCT u.* FROM users u 
                         INNER JOIN refunds r ON u.id = r.user_id 
                         WHERE DATE(r.refund_date) BETWEEN :start AND :end 
                         ORDER BY u.first_name, u.last_name", 
                         [':start' => $startDate, ':end' => $endDate]);
if ($cashiers === false) $cashiers = [];

// Build query conditions
$whereConditions = ["DATE(r.refund_date) BETWEEN :start_date AND :end_date", "r.status = 'completed'"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "r.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "r.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedCashier !== 'all' && $selectedCashier) {
    $whereConditions[] = "r.user_id = :user_id";
    $params[':user_id'] = $selectedCashier;
}

if ($search) {
    $whereConditions[] = "(r.refund_number LIKE :search1 OR r.credit_note_number LIKE :search2 OR s.receipt_number LIKE :search3)";
    $searchTerm = "%$search%";
    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    $params[':search3'] = $searchTerm;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT r.id) as total_refunds,
    COALESCE(SUM(r.total_amount), 0) as total_amount,
    COUNT(DISTINCT r.customer_id) as unique_customers
FROM refunds r
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_refunds' => 0,
        'total_amount' => 0,
        'unique_customers' => 0
    ];
}

// Get refunds with all details
$refunds = $db->getRows("SELECT 
                        r.*,
                        s.receipt_number,
                        s.sale_date,
                        COALESCE(c.first_name, '') as customer_first,
                        COALESCE(c.last_name, '') as customer_last,
                        COALESCE(c.company_name, '') as customer_company,
                        COALESCE(u.first_name, '') as cashier_first,
                        COALESCE(u.last_name, '') as cashier_last,
                        COALESCE(b.branch_name, 'N/A') as branch_name
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

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Cancelled Sales / Refunds Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Refunds</td><td style="text-align: right;">' . $summary['total_refunds'] . '</td></tr>';
    $html .= '<tr><td>Total Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_amount']) . '</td></tr>';
    $html .= '<tr><td>Unique Customers</td><td style="text-align: right;">' . $summary['unique_customers'] . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Cancelled Sales</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Date</th><th>Refund #</th><th>Credit Note #</th><th>Receipt #</th><th>Customer</th><th>Cancelled By</th><th>Branch</th><th style="text-align: right;">Amount</th><th>Reason</th></tr>';
    foreach ($refunds as $refund) {
        $customerName = trim(($refund['customer_first'] ?? '') . ' ' . ($refund['customer_last'] ?? ''));
        if (empty($customerName)) {
            $customerName = $refund['customer_company'] ?? 'Walk-in';
        }
        $cashierName = trim(($refund['cashier_first'] ?? '') . ' ' . ($refund['cashier_last'] ?? ''));
        
        $html .= '<tr>';
        $html .= '<td>' . date('M d, Y H:i', strtotime($refund['refund_date'])) . '</td>';
        $html .= '<td>' . escapeHtml($refund['refund_number']) . '</td>';
        $html .= '<td>' . escapeHtml($refund['credit_note_number'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($refund['receipt_number'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($customerName) . '</td>';
        $html .= '<td>' . escapeHtml($cashierName ?: 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($refund['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($refund['total_amount']) . '</td>';
        $html .= '<td>' . escapeHtml($refund['reason'] ?? 'N/A') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Cancelled Sales Report', $html, 'Cancelled_Sales_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-x-circle"></i> Cancelled Sales / Refunds</h2>
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
                <label class="form-label"><i class="bi bi-person-badge"></i> Cashier</label>
                <select name="user_id" class="form-select">
                    <option value="all" <?= $selectedCashier === 'all' ? 'selected' : '' ?>>All Cashiers</option>
                    <?php foreach ($cashiers as $cashier): ?>
                        <option value="<?= $cashier['id'] ?>" <?= $selectedCashier == $cashier['id'] ? 'selected' : '' ?>><?= escapeHtml($cashier['first_name'] . ' ' . $cashier['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-search"></i> Search</label>
                <input type="text" name="search" value="<?= escapeHtml($search) ?>" class="form-control" placeholder="Refund/Receipt #">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="cancelled_sales.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Cancelled Sales</h6>
                <h3 class="mb-0 text-danger"><?= $summary['total_refunds'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Refund Amount</h6>
                <h3 class="mb-0 text-danger"><?= formatCurrency($summary['total_amount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Customers</h6>
                <h3 class="mb-0"><?= $summary['unique_customers'] ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Cancelled Sales / Refunds</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="cancelledSalesTable">
                <thead>
                    <tr>
                        <th>Date Cancelled</th>
                        <th>Refund #</th>
                        <th>Credit Note #</th>
                        <th>Receipt/Invoice #</th>
                        <th>Customer</th>
                        <th>Cancelled By</th>
                        <th>Branch</th>
                        <th class="text-end">Amount</th>
                        <th>Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($refunds)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                <i class="bi bi-inbox" style="font-size: 48px;"></i>
                                <p class="mt-2">No cancelled sales found for the selected criteria</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($refunds as $refund): ?>
                            <?php
                            $customerName = trim(($refund['customer_first'] ?? '') . ' ' . ($refund['customer_last'] ?? ''));
                            if (empty($customerName)) {
                                $customerName = $refund['customer_company'] ?? 'Walk-in';
                            }
                            $cashierName = trim(($refund['cashier_first'] ?? '') . ' ' . ($refund['cashier_last'] ?? ''));
                            $fiscalDetails = !empty($refund['fiscal_details']) ? json_decode($refund['fiscal_details'], true) : null;
                            ?>
                            <tr>
                                <td><?= date('M d, Y H:i', strtotime($refund['refund_date'])) ?></td>
                                <td><strong><?= escapeHtml($refund['refund_number']) ?></strong></td>
                                <td><?= escapeHtml($refund['credit_note_number'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($refund['receipt_number'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($customerName) ?></td>
                                <td><?= escapeHtml($cashierName ?: 'N/A') ?></td>
                                <td><?= escapeHtml($refund['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end text-danger"><strong><?= formatCurrency($refund['total_amount']) ?></strong></td>
                                <td><?= escapeHtml($refund['reason'] ?? 'N/A') ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($refund['credit_note_number']): ?>
                                            <button onclick="viewCreditNote(<?= $refund['id'] ?>)" class="btn btn-info" title="View Credit Note">
                                                <i class="bi bi-receipt"></i>
                                            </button>
                                            <button onclick="printCreditNote(<?= $refund['id'] ?>, '80mm')" class="btn btn-secondary" title="Print 80mm Credit Note">
                                                <i class="bi bi-printer"></i> 80mm
                                            </button>
                                            <button onclick="printCreditNote(<?= $refund['id'] ?>, 'A4')" class="btn btn-secondary" title="Print A4 Credit Note">
                                                <i class="bi bi-printer"></i> A4
                                            </button>
                                        <?php endif; ?>
                                        <button onclick="printOriginalReceipt(<?= $refund['sale_id'] ?>)" class="btn btn-outline-secondary" title="Print Original Receipt">
                                            <i class="bi bi-file-earmark-text"></i>
                                        </button>
                                    </div>
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
(function() {
    if (window.cancelledSalesTableInitialized) {
        return;
    }
    
    $(document).ready(function() {
        if (typeof jQuery === 'undefined' || !$.fn.DataTable) {
            return;
        }
        
        var table = $('#cancelledSalesTable');
        if (table.length === 0) {
            return;
        }
        
        if ($.fn.DataTable.isDataTable(table)) {
            try {
                table.DataTable().destroy();
                table.empty();
            } catch(e) {
                // Ignore
            }
        }
        
        var tbody = table.find('tbody');
        var firstRow = tbody.find('tr:first');
        var hasData = firstRow.length > 0 && !firstRow.find('td[colspan]').length;
        
        if (hasData) {
            try {
                table.DataTable({
                    order: [[0, 'desc']],
                    pageLength: 25,
                    autoWidth: false,
                    language: {
                        emptyTable: "No cancelled sales found for the selected criteria"
                    }
                });
                window.cancelledSalesTableInitialized = true;
            } catch(e) {
                console.error('DataTables initialization error:', e);
            }
        }
    });
})();

function viewCreditNote(refundId) {
    window.open('<?= BASE_URL ?>modules/sales/view_credit_note.php?id=' + refundId, '_blank');
}

function printCreditNote(refundId, format) {
    window.open('<?= BASE_URL ?>modules/sales/print_credit_note.php?id=' + refundId + '&format=' + format, '_blank');
}

function printOriginalReceipt(saleId) {
    window.open('<?= BASE_URL ?>modules/pos/receipt.php?id=' + saleId, '_blank');
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

