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

$pageTitle = 'Sales Summary Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCustomer = $_GET['customer_id'] ?? 'all';
$selectedCashier = $_GET['user_id'] ?? 'all';

// Get filter options
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

$customers = $db->getRows("SELECT DISTINCT c.* FROM customers c 
                          INNER JOIN sales s ON c.id = s.customer_id 
                          WHERE s.sale_date BETWEEN :start AND :end 
                          ORDER BY c.first_name, c.last_name", 
                          [':start' => $startDate, ':end' => $endDate]);
if ($customers === false) $customers = [];

$cashiers = $db->getRows("SELECT DISTINCT u.* FROM users u 
                         INNER JOIN sales s ON u.id = s.user_id 
                         WHERE s.sale_date BETWEEN :start AND :end 
                         ORDER BY u.first_name, u.last_name", 
                         [':start' => $startDate, ':end' => $endDate]);
if ($cashiers === false) $cashiers = [];

// Build query conditions
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

if ($selectedCashier !== 'all' && $selectedCashier) {
    $whereConditions[] = "s.user_id = :user_id";
    $params[':user_id'] = $selectedCashier;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary statistics - match dashboard approach
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_receipts,
    COALESCE(SUM(s.total_amount), 0) as gross_sales,
    COALESCE(SUM(s.discount_amount), 0) as total_discount,
    COALESCE(SUM(s.tax_amount), 0) as total_tax,
    COALESCE(SUM(CASE WHEN s.payment_status = 'refunded' THEN s.total_amount ELSE 0 END), 0) as total_refunds
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_receipts' => 0,
        'gross_sales' => 0,
        'total_discount' => 0,
        'total_tax' => 0,
        'total_refunds' => 0
    ];
}

// Calculate net sales
$netSales = $summary['gross_sales'] - $summary['total_refunds'] - $summary['total_discount'];

// Get product cost (sum of cost_price * quantity from sale_items)
$productCost = $db->getRow("SELECT COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost
                            FROM sale_items si
                            INNER JOIN sales s ON si.sale_id = s.id
                            LEFT JOIN products p ON si.product_id = p.id
                            WHERE $whereClause", $params);
if ($productCost === false) {
    $productCost = ['total_cost' => 0];
}

$grossProfit = $netSales - $productCost['total_cost'];
$operatingExpenses = 0; // This would come from expenses table if available
$operatingProfit = $grossProfit - $operatingExpenses;
$netProfit = $operatingProfit; // Assuming no other deductions

// Get daily breakdown - simplified approach matching dashboard
$dailyBreakdown = $db->getRows("SELECT 
    DATE(s.sale_date) as sale_date,
    COUNT(DISTINCT s.id) as receipt_count,
    COALESCE(SUM(s.total_amount), 0) as gross_sales,
    COALESCE(SUM(CASE WHEN s.payment_status = 'refunded' THEN s.total_amount ELSE 0 END), 0) as refunds,
    COALESCE(SUM(s.discount_amount), 0) as discount,
    COALESCE(SUM(s.total_amount) - COALESCE(SUM(CASE WHEN s.payment_status = 'refunded' THEN s.total_amount ELSE 0 END), 0) - COALESCE(SUM(s.discount_amount), 0), 0) as net_sales,
    COALESCE(SUM(s.tax_amount), 0) as taxes,
    0 as charges
FROM sales s
WHERE $whereClause
GROUP BY DATE(s.sale_date)
ORDER BY sale_date DESC", $params);

if ($dailyBreakdown === false) {
    $dailyBreakdown = [];
} else {
    // Calculate product cost and gross profit for each day
    foreach ($dailyBreakdown as &$day) {
        $dayDate = $day['sale_date'];
        $costWhereClause = "DATE(s.sale_date) = :sale_date";
        $costParams = [':sale_date' => $dayDate];
        
        if ($selectedBranch !== 'all' && $selectedBranch) {
            $costWhereClause .= " AND s.branch_id = :branch_id";
            $costParams[':branch_id'] = $selectedBranch;
        } elseif ($branchId !== null) {
            $costWhereClause .= " AND s.branch_id = :branch_id";
            $costParams[':branch_id'] = $branchId;
        }
        if ($selectedCustomer !== 'all' && $selectedCustomer) {
            $costWhereClause .= " AND s.customer_id = :customer_id";
            $costParams[':customer_id'] = $selectedCustomer;
        }
        if ($selectedCashier !== 'all' && $selectedCashier) {
            $costWhereClause .= " AND s.user_id = :user_id";
            $costParams[':user_id'] = $selectedCashier;
        }
        
        $dayCost = $db->getRow("SELECT COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as product_cost
                                FROM sale_items si
                                INNER JOIN sales s ON si.sale_id = s.id
                                LEFT JOIN products p ON si.product_id = p.id
                                WHERE $costWhereClause", $costParams);
        $day['product_cost'] = $dayCost !== false ? floatval($dayCost['product_cost']) : 0;
        $day['gross_profit'] = floatval($day['net_sales']) - floatval($day['product_cost']);
    }
    unset($day);
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales Summary Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left; width: 25%;">Metric</th><th style="text-align: right; width: 25%;">Amount</th></tr>';
    $html .= '<tr><td>Gross Sales</td><td style="text-align: right;">' . formatCurrency($summary['gross_sales']) . '</td></tr>';
    $html .= '<tr><td>Refunds</td><td style="text-align: right;">' . formatCurrency($summary['total_refunds']) . '</td></tr>';
    $html .= '<tr><td>Discount</td><td style="text-align: right;">' . formatCurrency($summary['total_discount']) . '</td></tr>';
    $html .= '<tr><td>Net Sales</td><td style="text-align: right;">' . formatCurrency($netSales) . '</td></tr>';
    $html .= '<tr><td>Product Cost</td><td style="text-align: right;">' . formatCurrency($productCost['total_cost']) . '</td></tr>';
    $html .= '<tr><td>Gross Profit</td><td style="text-align: right;">' . formatCurrency($grossProfit) . '</td></tr>';
    $html .= '<tr><td>Operating Expenses</td><td style="text-align: right;">' . formatCurrency($operatingExpenses) . '</td></tr>';
    $html .= '<tr><td>Operating Profit</td><td style="text-align: right;">' . formatCurrency($operatingProfit) . '</td></tr>';
    $html .= '<tr><td>Taxes</td><td style="text-align: right;">' . formatCurrency($summary['total_tax']) . '</td></tr>';
    $html .= '<tr><td>Net Profit</td><td style="text-align: right;">' . formatCurrency($netProfit) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Daily Breakdown</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Date</th><th style="text-align: right;">Gross Sales</th><th style="text-align: right;">Refunds</th><th style="text-align: right;">Discount</th><th style="text-align: right;">Net Sales</th><th style="text-align: right;">Taxes</th><th style="text-align: right;">Charges</th><th style="text-align: right;">Product Cost</th><th style="text-align: right;">Gross Profit</th></tr>';
    foreach ($dailyBreakdown as $day) {
        $html .= '<tr>';
        $html .= '<td>' . date('M d, Y', strtotime($day['sale_date'])) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['gross_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['refunds']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['discount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['net_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['taxes']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['charges']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['product_cost']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['gross_profit']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales Summary Report', $html, 'Sales_Summary_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<style>
.metric-card {
    border-left: 4px solid;
    transition: transform 0.2s;
}
.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.metric-card.gross-sales { border-left-color: #0d6efd; }
.metric-card.refunds { border-left-color: #dc3545; }
.metric-card.discount { border-left-color: #ffc107; }
.metric-card.net-sales { border-left-color: #198754; }
.metric-card.product-cost { border-left-color: #6c757d; }
.metric-card.gross-profit { border-left-color: #0dcaf0; }
.metric-card.operating-profit { border-left-color: #6610f2; }
.metric-card.net-profit { border-left-color: #20c997; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-bar-chart"></i> Sales Summary Report</h2>
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
                <a href="sales_summary.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card metric-card gross-sales">
            <div class="card-body">
                <h6 class="text-muted mb-2">Gross Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['gross_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card refunds">
            <div class="card-body">
                <h6 class="text-muted mb-2">Refunds</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_refunds']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card discount">
            <div class="card-body">
                <h6 class="text-muted mb-2">Discount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_discount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card net-sales">
            <div class="card-body">
                <h6 class="text-muted mb-2">Net Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($netSales) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card metric-card product-cost">
            <div class="card-body">
                <h6 class="text-muted mb-2">Product Cost</h6>
                <h3 class="mb-0"><?= formatCurrency($productCost['total_cost']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card gross-profit">
            <div class="card-body">
                <h6 class="text-muted mb-2">Gross Profit</h6>
                <h3 class="mb-0"><?= formatCurrency($grossProfit) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Operating Expenses <i class="bi bi-info-circle" data-bs-toggle="tooltip" title="Not configured"></i></h6>
                <h3 class="mb-0"><?= formatCurrency($operatingExpenses) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card metric-card operating-profit">
            <div class="card-body">
                <h6 class="text-muted mb-2">Operating Profit <i class="bi bi-info-circle" data-bs-toggle="tooltip" title="Gross Profit - Operating Expenses"></i></h6>
                <h3 class="mb-0"><?= formatCurrency($operatingProfit) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Taxes</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_tax']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card metric-card net-profit">
            <div class="card-body">
                <h6 class="text-muted mb-2">Net Profit</h6>
                <h3 class="mb-0"><?= formatCurrency($netProfit) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Daily Breakdown</h5>
        <div>
            <button class="btn btn-sm btn-outline-secondary" onclick="toggleColumns()"><i class="bi bi-list"></i> Columns</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="dailyTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Gross Sales</th>
                        <th class="text-end">Refunds</th>
                        <th class="text-end">Discount</th>
                        <th class="text-end">Net Sales</th>
                        <th class="text-end">Taxes</th>
                        <th class="text-end">Charges</th>
                        <th class="text-end">Product Cost</th>
                        <th class="text-end">Gross Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dailyBreakdown)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dailyBreakdown as $day): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($day['sale_date'])) ?></td>
                                <td class="text-end"><?= formatCurrency($day['gross_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($day['refunds']) ?></td>
                                <td class="text-end"><?= formatCurrency($day['discount']) ?></td>
                                <td class="text-end"><?= formatCurrency($day['net_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($day['taxes']) ?></td>
                                <td class="text-end"><?= formatCurrency($day['charges']) ?></td>
                                <td class="text-end"><?= formatCurrency($day['product_cost']) ?></td>
                                <td class="text-end"><?= formatCurrency($day['gross_profit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    // Initialize DataTable - properly destroy and reinitialize
    if ($.fn.DataTable) {
        var table = $('#dailyTable');
        
        // Destroy existing instance if it exists
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        
        // Check if table has actual data (9 columns with content, not empty state)
        var tbody = table.find('tbody');
        var firstRow = tbody.find('tr:first');
        var firstCell = firstRow.find('td').first();
        var hasColspan = firstCell.attr('colspan') !== undefined;
        var tdCount = firstRow.find('td').length;
        var firstCellText = firstCell.text().trim();
        
        // Only initialize if we have data (no colspan, 9 columns, and has content)
        var hasContent = !hasColspan && firstRow.length > 0 && tdCount === 9 && firstCellText !== '';
        
        if (hasContent) {
            // Initialize DataTable
            table.DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                dom: 'Bfrtip',
                buttons: ['copy', 'csv', 'excel'],
                columnDefs: [
                    { targets: 0, type: 'date' },
                    { targets: [1, 2, 3, 4, 5, 6, 7, 8], type: 'num', render: function(data) { return data || '0.00'; } }
                ],
                autoWidth: false,
                destroy: true,
                language: {
                    emptyTable: "No sales data available for the selected period"
                }
            });
        } else {
            // Show empty message
            tbody.html('<tr><td colspan="9" class="text-center text-muted">No data available</td></tr>');
        }
    }
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

