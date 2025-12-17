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

$pageTitle = 'Shifts Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Build query conditions
$whereConditions = ["DATE(s.opened_at) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

$whereClause = implode(' AND ', $whereConditions);

// Get shifts - select only needed columns (7 columns to match table, but keep s.id for view link)
$shifts = $db->getRows("SELECT s.id, s.opened_at, s.closed_at, s.expected_cash, s.actual_cash,
                       b.branch_name
                       FROM shifts s
                       LEFT JOIN branches b ON s.branch_id = b.id
                       WHERE $whereClause
                       ORDER BY s.opened_at DESC
                       LIMIT 1000", $params);

if ($shifts === false) {
    $shifts = [];
}

// Calculate differences for each shift
foreach ($shifts as &$shift) {
    $shift['difference'] = $shift['actual_cash'] - $shift['expected_cash'];
    $shift['difference_display'] = $shift['difference'] == 0 ? '-' : formatCurrency(abs($shift['difference']));
    $shift['difference_class'] = $shift['difference'] == 0 ? 'text-success' : ($shift['difference'] > 0 ? 'text-success' : 'text-danger');
}
unset($shift);

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Shifts Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Shifts</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Terminal</th><th>Opening Time</th><th>Closing Time</th><th style="text-align: right;">Expected Cash</th><th style="text-align: right;">Actual Cash</th><th style="text-align: right;">Difference</th></tr>';
    foreach ($shifts as $shift) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($shift['branch_name'] ?? 'N/A') . '</td>';
        $html .= '<td>' . date('M d, Y H:i', strtotime($shift['opened_at'])) . '</td>';
        $html .= '<td>' . ($shift['closed_at'] ? date('M d, Y H:i', strtotime($shift['closed_at'])) : 'Open') . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($shift['expected_cash']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($shift['actual_cash']) . '</td>';
        $html .= '<td style="text-align: right;">' . $shift['difference_display'] . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Shifts Report', $html, 'Shifts_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clock-history"></i> Shifts Report</h2>
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
                    <a href="shifts.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Shifts</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="shiftsTable">
                <thead>
                    <tr>
                        <th>Terminal</th>
                        <th>Opening Time</th>
                        <th>Closing Time</th>
                        <th class="text-end">Expected Cash</th>
                        <th class="text-end">Actual Cash</th>
                        <th class="text-end">Difference</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($shifts)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($shifts as $shift): ?>
                            <tr>
                                <td><?= escapeHtml($shift['branch_name'] ?? 'N/A') ?></td>
                                <td><?= date('M d, Y H:i', strtotime($shift['opened_at'])) ?></td>
                                <td><?= $shift['closed_at'] ? date('M d, Y H:i', strtotime($shift['closed_at'])) : '<span class="badge bg-warning">Open</span>' ?></td>
                                <td class="text-end"><?= formatCurrency($shift['expected_cash']) ?></td>
                                <td class="text-end"><?= formatCurrency($shift['actual_cash']) ?></td>
                                <td class="text-end <?= $shift['difference_class'] ?>"><?= $shift['difference_display'] ?></td>
                                <td>
                                    <?php if ($shift['closed_at']): ?>
                                        <a href="<?= BASE_URL ?>modules/pos/shift_report.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-eye"></i> View</a>
                                    <?php endif; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Wait for jQuery to be available
    if (typeof jQuery === 'undefined') {
        console.error('jQuery is not loaded');
        return;
    }
    
    var $ = jQuery;
    
    if ($.fn.DataTable) {
        var table = $('#shiftsTable');
        if ($.fn.DataTable.isDataTable(table)) {
            table.DataTable().destroy();
        }
        table.DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            destroy: true,
            autoWidth: false
        });
    }
});
</script>

