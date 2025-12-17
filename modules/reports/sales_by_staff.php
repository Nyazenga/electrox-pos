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

$pageTitle = 'Sales by Staff Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", ];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

$whereClause = implode(' AND ', $whereConditions);

// Get sales by staff
$salesByStaff = $db->getRows("SELECT 
    u.id as staff_id,
    u.first_name,
    u.last_name,
    COUNT(DISTINCT s.id) as receipt_count,
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(SUM(s.discount_amount), 0) as total_discount,
    COALESCE(AVG(s.total_amount), 0) as avg_sale
FROM sales s
LEFT JOIN users u ON s.user_id = u.id
WHERE $whereClause
GROUP BY u.id, u.first_name, u.last_name
ORDER BY total_sales DESC
LIMIT 1000", $params);

if ($salesByStaff === false) {
    $salesByStaff = [];
}

// Get summary
$summary = $db->getRow("SELECT 
    COALESCE(SUM(s.total_amount), 0) as total_sales
FROM sales s
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = ['total_sales' => 0];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales by Staff Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Total Sales</td><td style="text-align: right;">' . formatCurrency($summary['total_sales']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Sales by Staff</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Staff</th><th style="text-align: right;">Receipts</th><th style="text-align: right;">Total Sales</th><th style="text-align: right;">Discount</th><th style="text-align: right;">Avg Sale</th></tr>';
    foreach ($salesByStaff as $ss) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml(($ss['first_name'] ?? '') . ' ' . ($ss['last_name'] ?? '')) . '</td>';
        $html .= '<td style="text-align: right;">' . $ss['receipt_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($ss['total_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($ss['total_discount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($ss['avg_sale']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales by Staff Report', $html, 'Sales_by_Staff_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-people"></i> Sales by Staff Report</h2>
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
                    <a href="sales_by_staff.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Sales</h6>
                <h3 class="mb-0 text-primary"><?= formatCurrency($summary['total_sales']) ?></h3>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($salesByStaff)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Sales by Staff (Bar Chart)</h5>
            </div>
            <div class="card-body">
                <canvas id="salesByStaffChart" height="80"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Sales by Staff</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="salesByStaffTable">
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th class="text-end">Receipts</th>
                        <th class="text-end">Total Sales</th>
                        <th class="text-end">Discount</th>
                        <th class="text-end">Avg Sale</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salesByStaff)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($salesByStaff as $ss): ?>
                            <tr>
                                <td><strong><?= escapeHtml(($ss['first_name'] ?? '') . ' ' . ($ss['last_name'] ?? '')) ?></strong></td>
                                <td class="text-end"><?= $ss['receipt_count'] ?></td>
                                <td class="text-end"><strong><?= formatCurrency($ss['total_sales']) ?></strong></td>
                                <td class="text-end"><?= formatCurrency($ss['total_discount']) ?></td>
                                <td class="text-end"><?= formatCurrency($ss['avg_sale']) ?></td>
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
        var table = $('#salesByStaffTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        table.DataTable({
            order: [[2, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
    
    <?php if (!empty($salesByStaff)): ?>
    const salesByStaffCtx = document.getElementById('salesByStaffChart');
    if (salesByStaffCtx) {
        new Chart(salesByStaffCtx, {
            type: 'bar',
            data: {
                labels: [<?= implode(',', array_map(function($ss) { return "'" . addslashes(($ss['first_name'] ?? '') . ' ' . ($ss['last_name'] ?? '')) . "'"; }, $salesByStaff)) ?>],
                datasets: [{
                    label: 'Total Sales',
                    data: [<?= implode(',', array_column($salesByStaff, 'total_sales')) ?>],
                    backgroundColor: 'rgba(30, 58, 138, 0.8)',
                    borderColor: 'rgba(30, 58, 138, 1)',
                    borderWidth: 1
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

