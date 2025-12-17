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

$pageTitle = 'Refunds Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCustomer = $_GET['customer_id'] ?? 'all';
$selectedCashier = $_GET['user_id'] ?? 'all';

// Get filter options
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

$customers = $db->getRows("SELECT DISTINCT c.* FROM customers c 
                          INNER JOIN refunds r ON c.id = r.customer_id 
                          WHERE r.refund_date BETWEEN :start AND :end 
                          ORDER BY c.first_name, c.last_name", 
                          [':start' => $startDate, ':end' => $endDate]);
if ($customers === false) $customers = [];

$cashiers = $db->getRows("SELECT DISTINCT u.* FROM users u 
                         INNER JOIN refunds r ON u.id = r.user_id 
                         WHERE r.refund_date BETWEEN :start AND :end 
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

if ($selectedCustomer !== 'all' && $selectedCustomer) {
    $whereConditions[] = "r.customer_id = :customer_id";
    $params[':customer_id'] = $selectedCustomer;
}

if ($selectedCashier !== 'all' && $selectedCashier) {
    $whereConditions[] = "r.user_id = :user_id";
    $params[':user_id'] = $selectedCashier;
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

// Get refunds - select individual columns, then combine to exactly 8 columns for DataTables
$refundsRaw = $db->getRows("SELECT 
                        r.refund_number, 
                        r.refund_date, 
                        r.total_amount, 
                        COALESCE(r.reason, '') as reason,
                        COALESCE(s.receipt_number, 'N/A') as sale_receipt,
                        COALESCE(c.first_name, '') as customer_first,
                        COALESCE(c.last_name, '') as customer_last,
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

// Process to create exactly 8 columns matching the table structure
$refunds = [];
if ($refundsRaw !== false && is_array($refundsRaw) && !empty($refundsRaw)) {
    foreach ($refundsRaw as $row) {
        // Combine customer name
        $customerName = trim(($row['customer_first'] ?? 'Walk-in') . ' ' . ($row['customer_last'] ?? ''));
        if (empty($customerName) || $customerName === 'Walk-in ') {
            $customerName = 'Walk-in';
        }
        // Combine cashier name
        $cashierName = trim(($row['cashier_first'] ?? '') . ' ' . ($row['cashier_last'] ?? ''));
        if (empty($cashierName)) {
            $cashierName = 'N/A';
        }
        
        // Create array with exactly 8 columns in exact table order
        // Order: refund_number, refund_date, sale_receipt, customer_name, cashier_name, branch_name, total_amount, reason
        $refundRow = [];
        $refundRow['refund_number'] = $row['refund_number'] ?? '';
        $refundRow['refund_date'] = $row['refund_date'] ?? '';
        $refundRow['sale_receipt'] = $row['sale_receipt'] ?? 'N/A';
        $refundRow['customer_name'] = $customerName;
        $refundRow['cashier_name'] = $cashierName;
        $refundRow['branch_name'] = $row['branch_name'] ?? 'N/A';
        $refundRow['total_amount'] = $row['total_amount'] ?? 0;
        $refundRow['reason'] = $row['reason'] ?? 'N/A';
        
        $refunds[] = $refundRow;
    }
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Refunds Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Refunds</td><td style="text-align: right;">' . $summary['total_refunds'] . '</td></tr>';
    $html .= '<tr><td>Total Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_amount']) . '</td></tr>';
    $html .= '<tr><td>Unique Customers</td><td style="text-align: right;">' . $summary['unique_customers'] . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Refunds</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Refund #</th><th>Date</th><th>Sale Receipt</th><th>Customer</th><th>Cashier</th><th>Branch</th><th style="text-align: right;">Amount</th><th>Reason</th></tr>';
    foreach ($refunds as $refund) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($refund['refund_number']) . '</td>';
        $html .= '<td>' . date('M d, Y H:i', strtotime($refund['refund_date'])) . '</td>';
        $html .= '<td>' . escapeHtml($refund['sale_receipt'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($refund['customer_name'] ?? 'Walk-in') . '</td>';
        $html .= '<td>' . escapeHtml($refund['cashier_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($refund['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($refund['total_amount']) . '</td>';
        $html .= '<td>' . escapeHtml($refund['reason'] ?? 'N/A') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Refunds Report', $html, 'Refunds_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-counterclockwise"></i> Refunds Report</h2>
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
                <label class="form-label"><i class="bi bi-person"></i> Customer</label>
                <select name="customer_id" class="form-select">
                    <option value="all" <?= $selectedCustomer === 'all' ? 'selected' : '' ?>>All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= $customer['id'] ?>" <?= $selectedCustomer == $customer['id'] ? 'selected' : '' ?>><?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?></option>
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
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="refunds.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Refunds</h6>
                <h3 class="mb-0 text-danger"><?= $summary['total_refunds'] ?></h3>
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
                <h6 class="text-muted mb-2">Unique Customers</h6>
                <h3 class="mb-0"><?= $summary['unique_customers'] ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Refunds</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="refundsTable">
                <thead>
                    <tr>
                        <th>Refund #</th>
                        <th>Date</th>
                        <th>Sale Receipt</th>
                        <th>Customer</th>
                        <th>Cashier</th>
                        <th>Branch</th>
                        <th class="text-end">Amount</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($refunds)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($refunds as $refund): ?>
                            <tr>
                                <td><?= escapeHtml($refund['refund_number']) ?></td>
                                <td><?= date('M d, Y H:i', strtotime($refund['refund_date'])) ?></td>
                                <td><?= escapeHtml($refund['sale_receipt'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($refund['customer_name'] ?? 'Walk-in') ?></td>
                                <td><?= escapeHtml($refund['cashier_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($refund['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end text-danger"><?= formatCurrency($refund['total_amount']) ?></td>
                                <td><?= escapeHtml($refund['reason'] ?? 'N/A') ?></td>
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
(function() {
    // Use global variable to prevent multiple initializations
    if (window.refundsTableInitialized) {
        return;
    }
    
    $(document).ready(function() {
        if (typeof jQuery === 'undefined' || !$.fn.DataTable) {
            return;
        }
        
        var table = $('#refundsTable');
        if (table.length === 0) {
            return;
        }
        
        // Destroy existing instance if it exists
        if ($.fn.DataTable.isDataTable(table)) {
            try {
                table.DataTable().destroy();
                table.empty();
            } catch(e) {
                // Ignore
            }
        }
        
        // Check if table has actual data (8 columns with content, not colspan empty state)
        var tbody = table.find('tbody');
        var firstRow = tbody.find('tr:first');
        var firstCell = firstRow.find('td').first();
        var hasColspan = firstCell.attr('colspan') !== undefined;
        var tdCount = firstRow.find('td').length;
        var firstCellText = firstCell.text().trim();
        
        // Only initialize if we have data (no colspan, 8 columns, and has content)
        var hasContent = !hasColspan && firstRow.length > 0 && tdCount === 8 && firstCellText !== '';
        
        if (hasContent) {
            try {
                table.DataTable({
                    order: [[1, 'desc']],
                    pageLength: 25,
                    autoWidth: false,
                    language: {
                        emptyTable: "No refunds found for the selected criteria"
                    }
                });
                window.refundsTableInitialized = true;
            } catch(e) {
                console.error('DataTables initialization error:', e);
            }
        }
    });
})();
</script>

