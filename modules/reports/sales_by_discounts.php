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

$pageTitle = 'Sales by Discounts Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedCashier = $_GET['user_id'] ?? 'all';
$selectedCategory = $_GET['category_id'] ?? 'all';

// Get filter options
$cashiers = $db->getRows("SELECT DISTINCT u.* FROM users u 
                         INNER JOIN sales s ON u.id = s.user_id 
                         WHERE s.sale_date BETWEEN :start AND :end 
                         ORDER BY u.first_name, u.last_name", 
                         [':start' => $startDate, ':end' => $endDate]);
if ($cashiers === false) $cashiers = [];

$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", "s.discount_amount > 0"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedCashier !== 'all' && $selectedCashier) {
    $whereConditions[] = "s.user_id = :user_id";
    $params[':user_id'] = $selectedCashier;
}

if ($selectedCategory !== 'all' && $selectedCategory) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $selectedCategory;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_receipts,
    COALESCE(SUM(s.discount_amount), 0) as total_discount,
    SUM(si.quantity) as total_sold_qty
FROM sales s
LEFT JOIN sale_items si ON s.id = si.sale_id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_receipts' => 0,
        'total_discount' => 0,
        'total_sold_qty' => 0
    ];
}

// Get discount breakdown - select only needed columns (7 columns to match table)
$discounts = $db->getRows("SELECT 
    s.receipt_number,
    DATE(s.sale_date) as sale_date,
    s.discount_type,
    s.discount_amount,
    s.total_amount,
    COUNT(si.id) as item_count,
    SUM(si.quantity) as total_qty
FROM sales s
LEFT JOIN sale_items si ON s.id = si.sale_id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause
GROUP BY s.receipt_number, DATE(s.sale_date), s.discount_type, s.discount_amount, s.total_amount
ORDER BY s.sale_date DESC
LIMIT 1000", $params);

if ($discounts === false) {
    $discounts = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales by Discounts Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Receipts</td><td style="text-align: right;">' . $summary['total_receipts'] . '</td></tr>';
    $html .= '<tr><td>Total Discount</td><td style="text-align: right;">' . formatCurrency($summary['total_discount']) . '</td></tr>';
    $html .= '<tr><td>Total Sold Quantity</td><td style="text-align: right;">' . $summary['total_sold_qty'] . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Discounts</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Receipt #</th><th>Date</th><th>Type</th><th style="text-align: right;">Discount</th><th style="text-align: right;">Total</th><th style="text-align: right;">Items</th><th style="text-align: right;">Qty</th></tr>';
    foreach ($discounts as $discount) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($discount['receipt_number']) . '</td>';
        $html .= '<td>' . date('M d, Y', strtotime($discount['sale_date'])) . '</td>';
        $html .= '<td>' . escapeHtml($discount['discount_type'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($discount['discount_amount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($discount['total_amount']) . '</td>';
        $html .= '<td style="text-align: right;">' . $discount['item_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . $discount['total_qty'] . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales by Discounts Report', $html, 'Sales_by_Discounts_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-percent"></i> Sales by Discounts Report</h2>
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
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-person-badge"></i> Cashier</label>
                <select name="user_id" class="form-select">
                    <option value="all" <?= $selectedCashier === 'all' ? 'selected' : '' ?>>All Cashiers</option>
                    <?php foreach ($cashiers as $cashier): ?>
                        <option value="<?= $cashier['id'] ?>" <?= $selectedCashier == $cashier['id'] ? 'selected' : '' ?>><?= escapeHtml($cashier['first_name'] . ' ' . $cashier['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                <select name="category_id" class="form-select">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $selectedCategory == $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="sales_by_discounts.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Receipts</h6>
                <h3 class="mb-0"><?= $summary['total_receipts'] ?></h3>
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
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Sold Quantity</h6>
                <h3 class="mb-0"><?= $summary['total_sold_qty'] ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Discounts</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="salesByDiscountsTable">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th class="text-end">Discount</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Items</th>
                        <th class="text-end">Qty</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($discounts)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($discounts as $discount): ?>
                            <tr>
                                <td><?= escapeHtml($discount['receipt_number']) ?></td>
                                <td><?= date('M d, Y', strtotime($discount['sale_date'])) ?></td>
                                <td><span class="badge bg-info"><?= escapeHtml($discount['discount_type'] ?? 'N/A') ?></span></td>
                                <td class="text-end"><?= formatCurrency($discount['discount_amount']) ?></td>
                                <td class="text-end"><?= formatCurrency($discount['total_amount']) ?></td>
                                <td class="text-end"><?= $discount['item_count'] ?></td>
                                <td class="text-end"><?= $discount['total_qty'] ?></td>
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
    if (window.salesByDiscountsTableInitialized) {
        return;
    }
    
    $(document).ready(function() {
        if (typeof jQuery === 'undefined' || !$.fn.DataTable) {
            return;
        }
        
        var table = $('#salesByDiscountsTable');
        if (table.length === 0) {
            return;
        }
        
        // Destroy existing instance if it exists
        if ($.fn.DataTable.isDataTable(table)) {
            try {
                table.DataTable().destroy();
            } catch(e) {
                // Ignore
            }
        }
        
        // Check if table has actual data (7 columns with content, not colspan empty state)
        var tbody = table.find('tbody');
        var firstRow = tbody.find('tr:first');
        var firstCell = firstRow.find('td').first();
        var hasColspan = firstCell.attr('colspan') !== undefined;
        var tdCount = firstRow.find('td').length;
        var firstCellText = firstCell.text().trim();
        
        // Only initialize if we have data (no colspan, 7 columns, and has content)
        var hasContent = !hasColspan && firstRow.length > 0 && tdCount === 7 && firstCellText !== '';
        
        if (hasContent) {
            try {
                table.DataTable({
                    order: [[1, 'desc']],
                    pageLength: 25,
                    autoWidth: false,
                    language: {
                        emptyTable: "No discounts found for the selected criteria"
                    }
                });
                window.salesByDiscountsTableInitialized = true;
            } catch(e) {
                console.error('DataTables initialization error:', e);
            }
        }
    });
})();
</script>

