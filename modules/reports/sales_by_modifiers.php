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

$pageTitle = 'Sales by Modifiers Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedCashier = $_GET['user_id'] ?? 'all';

// Get filter options
$cashiers = $db->getRows("SELECT DISTINCT u.* FROM users u 
                         INNER JOIN sales s ON u.id = s.user_id 
                         WHERE s.sale_date BETWEEN :start AND :end 
                         ORDER BY u.first_name, u.last_name", 
                         [':start' => $startDate, ':end' => $endDate]);
if ($cashiers === false) $cashiers = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", ];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedCashier !== 'all' && $selectedCashier) {
    $whereConditions[] = "s.user_id = :user_id";
    $params[':user_id'] = $selectedCashier;
}

$whereClause = implode(' AND ', $whereConditions);

// Modifiers are typically discounts, so we'll analyze discount patterns
// Get summary
$summary = $db->getRow("SELECT 
    COALESCE(SUM(s.total_amount), 0) as total_gross_sales,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_gross_cost,
    COALESCE(SUM(s.total_amount - COALESCE(si.quantity * COALESCE(p.cost_price, 0), 0)), 0) as total_gross_profit
FROM sales s
LEFT JOIN sale_items si ON s.id = si.sale_id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_gross_sales' => 0,
        'total_gross_cost' => 0,
        'total_gross_profit' => 0
    ];
}

// Get modifier breakdown (discount types and amounts)
$modifiers = $db->getRows("SELECT 
    s.discount_type,
    s.discount_amount,
    COUNT(DISTINCT s.id) as receipt_count,
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(SUM(si.quantity * COALESCE(p.cost_price, 0)), 0) as total_cost,
    COALESCE(SUM(s.total_amount - COALESCE(si.quantity * COALESCE(p.cost_price, 0), 0)), 0) as total_profit
FROM sales s
LEFT JOIN sale_items si ON s.id = si.sale_id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause AND s.discount_amount > 0
GROUP BY s.discount_type, s.discount_amount
ORDER BY total_sales DESC
LIMIT 1000", $params);

if ($modifiers === false) {
    $modifiers = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales by Modifiers Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Amount</th></tr>';
    $html .= '<tr><td>Total Gross Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_gross_sales']) . '</td></tr>';
    $html .= '<tr><td>Total Gross Cost</td><td style="text-align: right;">' . formatCurrency($summary['total_gross_cost']) . '</td></tr>';
    $html .= '<tr><td>Total Gross Profit</td><td style="text-align: right;">' . formatCurrency($summary['total_gross_profit']) . '</td></tr>';
    $html .= '</table>';
    
    if (!empty($modifiers)) {
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Modifiers Breakdown</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Discount Type</th><th style="text-align: right;">Discount Amount</th><th style="text-align: right;">Receipts</th><th style="text-align: right;">Total Sales</th><th style="text-align: right;">Cost</th><th style="text-align: right;">Profit</th></tr>';
        foreach ($modifiers as $mod) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml(ucfirst($mod['discount_type'] ?? 'N/A')) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($mod['discount_amount']) . '</td>';
            $html .= '<td style="text-align: right;">' . $mod['receipt_count'] . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($mod['total_sales']) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($mod['total_cost']) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($mod['total_profit']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    ReportHelper::generatePDF('Sales by Modifiers Report', $html, 'Sales_by_Modifiers_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-sliders"></i> Sales by Modifiers Report</h2>
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
                <a href="sales_by_modifiers.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Gross Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_gross_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Gross Cost</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_gross_cost']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Gross Profit</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_gross_profit']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Modifiers Breakdown</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="salesByModifiersTable">
                <thead>
                    <tr>
                        <th>Discount Type</th>
                        <th class="text-end">Discount Amount</th>
                        <th class="text-end">Receipts</th>
                        <th class="text-end">Total Sales</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($modifiers)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($modifiers as $mod): ?>
                            <tr>
                                <td><span class="badge bg-info"><?= escapeHtml(ucfirst($mod['discount_type'] ?? 'N/A')) ?></span></td>
                                <td class="text-end"><?= formatCurrency($mod['discount_amount']) ?></td>
                                <td class="text-end"><?= $mod['receipt_count'] ?></td>
                                <td class="text-end"><?= formatCurrency($mod['total_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($mod['total_cost']) ?></td>
                                <td class="text-end"><strong><?= formatCurrency($mod['total_profit']) ?></strong></td>
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
        var table = $('#salesByModifiersTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        table.DataTable({
            order: [[3, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
});
</script>

