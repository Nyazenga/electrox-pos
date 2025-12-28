<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.discounts_period');

$pageTitle = 'Discounts Given for Period';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$discountType = $_GET['discount_type'] ?? 'all'; // 'all', 'product', 'invoice'

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", "s.discount_amount > 0"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary stats
$summary = $db->getRow("SELECT 
    COUNT(DISTINCT s.id) as total_transactions,
    COALESCE(SUM(s.discount_amount), 0) as total_discount,
    COALESCE(SUM(CASE WHEN s.discount_type = 'value' THEN s.discount_amount ELSE 0 END), 0) as value_discounts,
    COALESCE(SUM(CASE WHEN s.discount_type = 'percentage' THEN s.discount_amount ELSE 0 END), 0) as percentage_discounts,
    COALESCE(AVG(s.discount_amount), 0) as avg_discount
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_transactions' => 0,
        'total_discount' => 0,
        'value_discounts' => 0,
        'percentage_discounts' => 0,
        'avg_discount' => 0
    ];
}

// Get discount details
$discounts = $db->getRows("SELECT 
    s.id,
    s.receipt_number,
    s.sale_date,
    s.discount_type,
    s.discount_amount,
    s.total_amount,
    s.subtotal,
    b.branch_name,
    CONCAT(u.first_name, ' ', u.last_name) as cashier_name,
    CONCAT(c.first_name, ' ', c.last_name) as customer_name,
    CASE 
        WHEN s.discount_type = 'percentage' THEN CONCAT(ROUND((s.discount_amount / s.subtotal * 100), 2), '%')
        ELSE formatCurrency(s.discount_amount)
    END as discount_display
FROM sales s
LEFT JOIN branches b ON s.branch_id = b.id
LEFT JOIN users u ON s.user_id = u.id
LEFT JOIN customers c ON s.customer_id = c.id
WHERE $whereClause
ORDER BY s.sale_date DESC, s.discount_amount DESC
LIMIT 1000", $params);

if ($discounts === false) {
    $discounts = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Discounts Given for Period</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Transactions with Discounts</td><td style="text-align: right;">' . $summary['total_transactions'] . '</td></tr>';
    $html .= '<tr><td>Total Discount Amount</td><td style="text-align: right;">' . formatCurrency($summary['total_discount']) . '</td></tr>';
    $html .= '<tr><td>Average Discount</td><td style="text-align: right;">' . formatCurrency($summary['avg_discount']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Discount Details</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Date</th><th>Receipt #</th><th>Branch</th><th>Cashier</th><th>Customer</th><th>Discount Type</th><th style="text-align: right;">Discount Amount</th><th style="text-align: right;">Sale Total</th></tr>';
    foreach ($discounts as $discount) {
        $html .= '<tr>';
        $html .= '<td>' . date('M d, Y H:i', strtotime($discount['sale_date'])) . '</td>';
        $html .= '<td>' . escapeHtml($discount['receipt_number']) . '</td>';
        $html .= '<td>' . escapeHtml($discount['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($discount['cashier_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($discount['customer_name'] ?? 'Walk-in') . '</td>';
        $html .= '<td>' . ucfirst($discount['discount_type'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($discount['discount_amount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($discount['total_amount']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Discounts Given for Period', $html, 'Discounts_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-percent"></i> Discounts Given for Period</h2>
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
                <label class="form-label"><i class="bi bi-tag"></i> Discount Type</label>
                <select name="discount_type" class="form-select">
                    <option value="all" <?= $discountType === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="value" <?= $discountType === 'value' ? 'selected' : '' ?>>Value</option>
                    <option value="percentage" <?= $discountType === 'percentage' ? 'selected' : '' ?>>Percentage</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Transactions</h6>
                <h3 class="mb-0"><?= $summary['total_transactions'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Discount</h6>
                <h3 class="mb-0 text-danger"><?= formatCurrency($summary['total_discount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Average Discount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['avg_discount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Value Discounts</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['value_discounts']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="discountsTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Receipt #</th>
                        <th>Branch</th>
                        <th>Cashier</th>
                        <th>Customer</th>
                        <th>Discount Type</th>
                        <th class="text-end">Discount Amount</th>
                        <th class="text-end">Sale Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($discounts)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No discounts found for the selected period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($discounts as $discount): ?>
                            <tr>
                                <td><?= date('M d, Y H:i', strtotime($discount['sale_date'])) ?></td>
                                <td><a href="<?= BASE_URL ?>modules/pos/receipt.php?id=<?= $discount['id'] ?>" target="_blank"><?= escapeHtml($discount['receipt_number']) ?></a></td>
                                <td><?= escapeHtml($discount['branch_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($discount['cashier_name'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($discount['customer_name'] ?? 'Walk-in') ?></td>
                                <td><span class="badge bg-info"><?= ucfirst($discount['discount_type'] ?? 'N/A') ?></span></td>
                                <td class="text-end text-danger fw-bold"><?= formatCurrency($discount['discount_amount']) ?></td>
                                <td class="text-end"><?= formatCurrency($discount['total_amount']) ?></td>
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
    $('#discountsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: true
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

