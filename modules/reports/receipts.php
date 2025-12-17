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

$pageTitle = 'Receipts Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCustomer = $_GET['customer_id'] ?? 'all';
$selectedEmployee = $_GET['user_id'] ?? 'all';

// Get filter options
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

$customers = $db->getRows("SELECT DISTINCT c.* FROM customers c 
                          INNER JOIN sales s ON c.id = s.customer_id 
                          WHERE s.sale_date BETWEEN :start AND :end 
                          ORDER BY c.first_name, c.last_name", 
                          [':start' => $startDate, ':end' => $endDate]);
if ($customers === false) $customers = [];

$employees = $db->getRows("SELECT DISTINCT u.* FROM users u 
                          INNER JOIN sales s ON u.id = s.user_id 
                          WHERE s.sale_date BETWEEN :start AND :end 
                          ORDER BY u.first_name, u.last_name", 
                          [':start' => $startDate, ':end' => $endDate]);
if ($employees === false) $employees = [];

// Build query conditions - sales table doesn't have a status column, only payment_status
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedCustomer !== 'all' && $selectedCustomer) {
    $whereConditions[] = "s.customer_id = :customer_id";
    $params[':customer_id'] = $selectedCustomer;
}

if ($selectedEmployee !== 'all' && $selectedEmployee) {
    $whereConditions[] = "s.user_id = :user_id";
    $params[':user_id'] = $selectedEmployee;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_receipts,
    COUNT(DISTINCT CASE WHEN s.payment_status = 'refunded' THEN s.id END) as total_refunds,
    COUNT(DISTINCT s.customer_id) as unique_customers,
    COALESCE(SUM(s.total_amount), 0) as total_amount
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_receipts' => 0,
        'total_refunds' => 0,
        'unique_customers' => 0,
        'total_amount' => 0
    ];
}

// Get receipts - select only needed columns to avoid DataTables column count issues
$receipts = $db->getRows("SELECT s.id, s.receipt_number, s.sale_date, s.total_amount, s.payment_status,
                         c.first_name as customer_first, c.last_name as customer_last,
                         u.first_name as employee_first, u.last_name as employee_last,
                         b.branch_name
                         FROM sales s
                         LEFT JOIN customers c ON s.customer_id = c.id
                         LEFT JOIN users u ON s.user_id = u.id
                         LEFT JOIN branches b ON s.branch_id = b.id
                         WHERE $whereClause
                         ORDER BY s.sale_date DESC
                         LIMIT 1000", $params);

if ($receipts === false) {
    $receipts = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Receipts Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Receipts</td><td style="text-align: right;">' . $summary['total_receipts'] . '</td></tr>';
    $html .= '<tr><td>Total Refunds</td><td style="text-align: right;">' . $summary['total_refunds'] . '</td></tr>';
    $html .= '<tr><td>Unique Customers</td><td style="text-align: right;">' . $summary['unique_customers'] . '</td></tr>';
    $html .= '<tr><td>Total Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_amount']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Receipts</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Receipt #</th><th>Date</th><th>Customer</th><th>Employee</th><th>Branch</th><th style="text-align: right;">Amount</th><th>Status</th></tr>';
    foreach ($receipts as $receipt) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($receipt['receipt_number']) . '</td>';
        $html .= '<td>' . date('M d, Y H:i', strtotime($receipt['sale_date'])) . '</td>';
        $html .= '<td>' . escapeHtml(($receipt['customer_first'] ?? '') . ' ' . ($receipt['customer_last'] ?? '')) . '</td>';
        $html .= '<td>' . escapeHtml(($receipt['employee_first'] ?? '') . ' ' . ($receipt['employee_last'] ?? '')) . '</td>';
        $html .= '<td>' . escapeHtml($receipt['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($receipt['total_amount']) . '</td>';
        $html .= '<td>' . escapeHtml($receipt['payment_status']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Receipts Report', $html, 'Receipts_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-receipt-cutoff"></i> Receipts Report</h2>
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
                <label class="form-label"><i class="bi bi-person-badge"></i> Employee</label>
                <select name="user_id" class="form-select">
                    <option value="all" <?= $selectedEmployee === 'all' ? 'selected' : '' ?>>All Employees</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= $employee['id'] ?>" <?= $selectedEmployee == $employee['id'] ? 'selected' : '' ?>><?= escapeHtml($employee['first_name'] . ' ' . $employee['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="receipts.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Receipts</h6>
                <h3 class="mb-0"><?= $summary['total_receipts'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Refunds</h6>
                <h3 class="mb-0"><?= $summary['total_refunds'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Unique Customers</h6>
                <h3 class="mb-0"><?= $summary['unique_customers'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Amount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_amount']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Receipts</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="receiptsTable">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Employee</th>
                        <th>Branch</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($receipts)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($receipts as $receipt): ?>
                            <tr>
                                <td><?= escapeHtml($receipt['receipt_number']) ?></td>
                                <td><?= date('M d, Y H:i', strtotime($receipt['sale_date'])) ?></td>
                                <td><?= escapeHtml(($receipt['customer_first'] ?? 'Walk-in') . ' ' . ($receipt['customer_last'] ?? '')) ?></td>
                                <td><?= escapeHtml(($receipt['employee_first'] ?? '') . ' ' . ($receipt['employee_last'] ?? '')) ?></td>
                                <td><?= escapeHtml($receipt['branch_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= formatCurrency($receipt['total_amount']) ?></td>
                                <td><span class="badge bg-<?= $receipt['payment_status'] === 'paid' ? 'success' : ($receipt['payment_status'] === 'refunded' ? 'danger' : 'warning') ?>"><?= escapeHtml($receipt['payment_status']) ?></span></td>
                                <td><a href="<?= BASE_URL ?>modules/pos/receipt.php?id=<?= $receipt['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-eye"></i></a></td>
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
    if ($.fn.DataTable) {
        var table = $('#receiptsTable');
        
        // Destroy existing instance if it exists
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        
        // Check if table has actual data (8 columns with content, not empty state)
        var tbody = table.find('tbody');
        var firstRow = tbody.find('tr:first');
        var firstCell = firstRow.find('td').first();
        var hasColspan = firstCell.attr('colspan') !== undefined;
        var tdCount = firstRow.find('td').length;
        var firstCellText = firstCell.text().trim();
        
        // Only initialize if we have data (no colspan, 8 columns, and has content)
        var hasContent = !hasColspan && firstRow.length > 0 && tdCount === 8 && firstCellText !== '';
        
        if (hasContent) {
            // Initialize DataTable
            table.DataTable({
                order: [[1, 'desc']],
                pageLength: 25,
                destroy: true,
                autoWidth: false,
                columnDefs: [
                    { targets: 7, orderable: false, searchable: false } // Actions column
                ],
                language: {
                    emptyTable: "No receipts found for the selected criteria"
                }
            });
        } else {
            // Show empty message
            tbody.html('<tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>');
        }
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

