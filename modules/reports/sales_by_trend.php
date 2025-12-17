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

$pageTitle = 'Sales by Trend Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedCategory = $_GET['category_id'] ?? 'all';
$trendType = $_GET['trend_type'] ?? 'daily'; // daily, weekly, monthly

// Get categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date", ];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedCategory !== 'all' && $selectedCategory) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $selectedCategory;
}

$whereClause = implode(' AND ', $whereConditions);

// Get trend data based on type
$groupBy = '';
$dateFormat = '';
switch ($trendType) {
    case 'weekly':
        $groupBy = "YEARWEEK(s.sale_date)";
        $dateFormat = "CONCAT(YEAR(s.sale_date), '-W', WEEK(s.sale_date))";
        break;
    case 'monthly':
        $groupBy = "DATE_FORMAT(s.sale_date, '%Y-%m')";
        $dateFormat = "DATE_FORMAT(s.sale_date, '%Y-%m')";
        break;
    default: // daily
        $groupBy = "DATE(s.sale_date)";
        $dateFormat = "DATE(s.sale_date)";
}

$trendData = $db->getRows("SELECT 
    $dateFormat as period,
    COUNT(DISTINCT s.id) as receipt_count,
    COALESCE(SUM(s.total_amount), 0) as total_sales,
    COALESCE(SUM(s.discount_amount), 0) as total_discount,
    COALESCE(SUM(s.tax_amount), 0) as total_tax,
    COALESCE(SUM(si.quantity), 0) as total_qty
FROM sales s
LEFT JOIN sale_items si ON s.id = si.sale_id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause
GROUP BY $groupBy
ORDER BY period ASC", $params);

if ($trendData === false) {
    $trendData = [];
}

// Calculate growth rates
$previousValue = null;
foreach ($trendData as &$data) {
    if ($previousValue !== null) {
        $data['growth'] = $previousValue > 0 ? (($data['total_sales'] - $previousValue) / $previousValue * 100) : 0;
    } else {
        $data['growth'] = 0;
    }
    $previousValue = $data['total_sales'];
}
unset($data);

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Sales by Trend Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . ' (' . ucfirst($trendType) . ')</p>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Sales Trend</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Period</th><th style="text-align: right;">Receipts</th><th style="text-align: right;">Total Sales</th><th style="text-align: right;">Discount</th><th style="text-align: right;">Tax</th><th style="text-align: right;">Quantity</th><th style="text-align: right;">Growth %</th></tr>';
    foreach ($trendData as $trend) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($trend['period']) . '</td>';
        $html .= '<td style="text-align: right;">' . $trend['receipt_count'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($trend['total_sales']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($trend['total_discount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($trend['total_tax']) . '</td>';
        $html .= '<td style="text-align: right;">' . $trend['total_qty'] . '</td>';
        $html .= '<td style="text-align: right;">' . number_format($trend['growth'], 2) . '%</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Sales by Trend Report', $html, 'Sales_by_Trend_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-graph-up-arrow"></i> Sales by Trend Report</h2>
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
                <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                <select name="category_id" class="form-select">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $selectedCategory == $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-bar-chart"></i> Trend Type</label>
                <select name="trend_type" class="form-select">
                    <option value="daily" <?= $trendType === 'daily' ? 'selected' : '' ?>>Daily</option>
                    <option value="weekly" <?= $trendType === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                    <option value="monthly" <?= $trendType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                    <a href="sales_by_trend.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($trendData)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Sales Trend Chart</h5>
            </div>
            <div class="card-body">
                <canvas id="salesTrendChart" height="100"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Sales Trend Data</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="salesByTrendTable">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th class="text-end">Receipts</th>
                        <th class="text-end">Total Sales</th>
                        <th class="text-end">Discount</th>
                        <th class="text-end">Tax</th>
                        <th class="text-end">Quantity</th>
                        <th class="text-end">Growth %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trendData)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($trendData as $trend): ?>
                            <tr>
                                <td><strong><?= escapeHtml($trend['period']) ?></strong></td>
                                <td class="text-end"><?= $trend['receipt_count'] ?></td>
                                <td class="text-end"><strong><?= formatCurrency($trend['total_sales']) ?></strong></td>
                                <td class="text-end"><?= formatCurrency($trend['total_discount']) ?></td>
                                <td class="text-end"><?= formatCurrency($trend['total_tax']) ?></td>
                                <td class="text-end"><?= $trend['total_qty'] ?></td>
                                <td class="text-end">
                                    <span class="badge bg-<?= $trend['growth'] >= 0 ? 'success' : 'danger' ?>">
                                        <?= $trend['growth'] >= 0 ? '+' : '' ?><?= number_format($trend['growth'], 2) ?>%
                                    </span>
                                </td>
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
$(document).ready(function() {
    if (typeof jQuery === 'undefined') {
        console.error('jQuery is not loaded');
        return;
    }
    
    var $ = jQuery;
    
    if ($.fn.DataTable) {
        var table = $('#salesByTrendTable');
        if (table.length === 0) {
            return;
        }
        
        // Destroy existing instance if it exists
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        
        // Check if table has actual data
        var tbody = table.find('tbody');
        var rows = tbody.find('tr');
        var hasData = false;
        
        if (rows.length > 0) {
            rows.each(function() {
                var $row = $(this);
                var hasContent = false;
                $row.find('td').each(function() {
                    if ($(this).text().trim() !== '') {
                        hasContent = true;
                        return false; // break
                    }
                });
                if (hasContent) {
                    hasData = true;
                    return false; // break outer loop
                }
            });
        }
        
        // If no data, clear tbody so DataTables shows empty message
        if (!hasData && rows.length > 0) {
            tbody.empty();
        }
        
        table.DataTable({
            order: [[0, 'asc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false,
            language: {
                emptyTable: "No sales data available for the selected period",
                zeroRecords: "No sales data available for the selected period"
            }
        });
    }
    
    <?php if (!empty($trendData)): ?>
    const salesTrendCtx = document.getElementById('salesTrendChart');
    if (salesTrendCtx && typeof Chart !== 'undefined') {
        new Chart(salesTrendCtx, {
            type: 'line',
            data: {
                labels: [<?= implode(',', array_map(function($t) { return "'" . addslashes($t['period']) . "'"; }, $trendData)) ?>],
                datasets: [{
                    label: 'Total Sales',
                    data: [<?= implode(',', array_column($trendData, 'total_sales')) ?>],
                    borderColor: 'rgba(30, 58, 138, 1)',
                    backgroundColor: 'rgba(30, 58, 138, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Quantity',
                    data: [<?= implode(',', array_column($trendData, 'total_qty')) ?>],
                    borderColor: 'rgba(59, 130, 246, 1)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toFixed(2);
                            }
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>

