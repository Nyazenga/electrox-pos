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

$pageTitle = 'Tax Summary Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

$whereClause = implode(' AND ', $whereConditions);

// Get tax summary
$summary = $db->getRow("SELECT 
    COALESCE(SUM(CASE WHEN s.tax_amount > 0 THEN s.total_amount ELSE 0 END), 0) as taxable_sales,
    COALESCE(SUM(CASE WHEN s.tax_amount = 0 OR s.tax_amount IS NULL THEN s.total_amount ELSE 0 END), 0) as non_taxable_sales,
    COALESCE(SUM(s.total_amount), 0) as total_net_sales,
    COALESCE(SUM(s.tax_amount), 0) as total_tax
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'taxable_sales' => 0,
        'non_taxable_sales' => 0,
        'total_net_sales' => 0,
        'total_tax' => 0
    ];
}

// Get daily tax breakdown
$dailyTax = $db->getRows("SELECT 
    DATE(s.sale_date) as sale_date,
    COUNT(DISTINCT s.id) as receipt_count,
    COALESCE(SUM(CASE WHEN s.tax_amount > 0 THEN s.total_amount ELSE 0 END), 0) as taxable_sales,
    COALESCE(SUM(CASE WHEN s.tax_amount = 0 OR s.tax_amount IS NULL THEN s.total_amount ELSE 0 END), 0) as non_taxable_sales,
    COALESCE(SUM(s.tax_amount), 0) as tax_amount
FROM sales s
WHERE $whereClause
GROUP BY DATE(s.sale_date)
ORDER BY sale_date DESC", $params);

if ($dailyTax === false) {
    $dailyTax = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Tax Summary Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Amount</th></tr>';
    $html .= '<tr><td>Taxable Sales</td><td style="text-align: right;">' . formatCurrency($summary['taxable_sales']) . '</td></tr>';
    $html .= '<tr><td>Non-taxable Sales</td><td style="text-align: right;">' . formatCurrency($summary['non_taxable_sales']) . '</td></tr>';
    $html .= '<tr><td>Total Net Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_net_sales']) . '</td></tr>';
    $html .= '<tr><td>Total Tax</td><td style="text-align: right;">' . formatCurrency($summary['total_tax']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Daily Tax Breakdown</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Date</th><th style="text-align: right;">Receipts</th><th style="text-align: right;">Taxable Sales</th><th style="text-align: right;">Non-taxable Sales</th><th style="text-align: right;">Tax Amount</th></tr>';
    foreach ($dailyTax as $day) {
        $html .= '<tr>';
        $html .= '<td>' . date('M d, Y', strtotime($day['sale_date'])) . '</td>';
        $html .= '<td style="text-align: right;">' . $day['receipt_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['taxable_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['non_taxable_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($day['tax_amount']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Tax Summary Report', $html, 'Tax_Summary_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-receipt"></i> Tax Summary Report</h2>
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
                    <a href="taxes.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Taxable Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['taxable_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Non-taxable Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['non_taxable_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Net Sales</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_net_sales']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Tax</h6>
                <h3 class="mb-0 text-primary"><?= formatCurrency($summary['total_tax']) ?></h3>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($dailyTax)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Tax Trend</h5>
            </div>
            <div class="card-body">
                <canvas id="taxTrendChart" height="80"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Daily Tax Breakdown</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="taxesTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Receipts</th>
                        <th class="text-end">Taxable Sales</th>
                        <th class="text-end">Non-taxable Sales</th>
                        <th class="text-end">Tax Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dailyTax)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dailyTax as $day): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($day['sale_date'])) ?></td>
                                <td class="text-end"><?= $day['receipt_count'] ?></td>
                                <td class="text-end"><?= formatCurrency($day['taxable_sales']) ?></td>
                                <td class="text-end"><?= formatCurrency($day['non_taxable_sales']) ?></td>
                                <td class="text-end"><strong><?= formatCurrency($day['tax_amount']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Wait for jQuery to be available
    if (typeof jQuery === 'undefined') {
        console.error('jQuery is not loaded');
        return;
    }
    
    var $ = jQuery;
    
    if ($.fn.DataTable) {
        var table = $('#taxesTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        table.DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
    
    <?php if (!empty($dailyTax)): ?>
    const taxTrendCtx = document.getElementById('taxTrendChart');
    if (taxTrendCtx && typeof Chart !== 'undefined') {
        const labels = [<?= implode(',', array_map(function($d) { return "'" . date('M d', strtotime($d['sale_date'])) . "'"; }, array_reverse($dailyTax))) ?>];
        new Chart(taxTrendCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Tax Amount',
                    data: [<?= implode(',', array_map(function($d) { return $d['tax_amount']; }, array_reverse($dailyTax))) ?>],
                    borderColor: 'rgba(30, 58, 138, 1)',
                    backgroundColor: 'rgba(30, 58, 138, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Taxable Sales',
                    data: [<?= implode(',', array_map(function($d) { return $d['taxable_sales']; }, array_reverse($dailyTax))) ?>],
                    borderColor: 'rgba(59, 130, 246, 1)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>

