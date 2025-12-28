<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.view_stock_transfers_period');

$pageTitle = 'View Stock Transfers for Period';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedStatus = $_GET['status'] ?? 'all';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Build query conditions - check if stock_transfers table exists
$transfersTableExists = false;
try {
    $check = $db->getRow("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'stock_transfers'");
    $transfersTableExists = ($check && $check['count'] > 0);
} catch (Exception $e) {
    $transfersTableExists = false;
}

$transfers = [];
$summary = [
    'total_transfers' => 0,
    'total_items' => 0,
    'total_value' => 0
];

if ($transfersTableExists) {
    $whereConditions = ["DATE(st.transfer_date) BETWEEN :start_date AND :end_date"];
    $params = [':start_date' => $startDate, ':end_date' => $endDate];

    if ($selectedBranch !== 'all' && $selectedBranch) {
        $whereConditions[] = "(st.from_branch_id = :branch_id OR st.to_branch_id = :branch_id)";
        $params[':branch_id'] = $selectedBranch;
    } elseif ($branchId !== null) {
        $whereConditions[] = "(st.from_branch_id = :branch_id OR st.to_branch_id = :branch_id)";
        $params[':branch_id'] = $branchId;
    }

    if ($selectedStatus !== 'all' && $selectedStatus) {
        $whereConditions[] = "st.status = :status";
        $params[':status'] = $selectedStatus;
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Get summary stats
    $summary = $db->getRow("SELECT 
        COUNT(DISTINCT st.id) as total_transfers,
        COALESCE(SUM(sti.quantity), 0) as total_items,
        COALESCE(SUM(sti.quantity * COALESCE(p.cost_price, 0)), 0) as total_value
    FROM stock_transfers st
    LEFT JOIN stock_transfer_items sti ON st.id = sti.transfer_id
    LEFT JOIN products p ON sti.product_id = p.id
    WHERE $whereClause", $params);

    if ($summary === false) {
        $summary = [
            'total_transfers' => 0,
            'total_items' => 0,
            'total_value' => 0
        ];
    }

    // Get transfers
    $transfers = $db->getRows("SELECT 
        st.id,
        st.transfer_number,
        st.transfer_date,
        st.status,
        st.notes,
        b1.branch_name as from_branch,
        b2.branch_name as to_branch,
        u1.first_name as created_by_first,
        u1.last_name as created_by_last,
        (SELECT COUNT(*) FROM stock_transfer_items sti WHERE sti.transfer_id = st.id) as item_count,
        (SELECT COALESCE(SUM(sti.quantity * COALESCE(p.cost_price, 0)), 0) FROM stock_transfer_items sti LEFT JOIN products p ON sti.product_id = p.id WHERE sti.transfer_id = st.id) as total_value
    FROM stock_transfers st
    LEFT JOIN branches b1 ON st.from_branch_id = b1.id
    LEFT JOIN branches b2 ON st.to_branch_id = b2.id
    LEFT JOIN users u1 ON st.created_by = u1.id
    WHERE $whereClause
    ORDER BY st.transfer_date DESC, st.created_at DESC
    LIMIT 1000", $params);

    if ($transfers === false) {
        $transfers = [];
    }
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Stock Transfers for Period</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    if (!$transfersTableExists || empty($transfers)) {
        $html .= '<p style="text-align: center; color: #666;">No stock transfers found for the selected period or stock transfers feature not available.</p>';
    } else {
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
        $html .= '<tr><td>Total Transfers</td><td style="text-align: right;">' . $summary['total_transfers'] . '</td></tr>';
        $html .= '<tr><td>Total Items</td><td style="text-align: right;">' . number_format($summary['total_items'], 2) . '</td></tr>';
        $html .= '<tr><td>Total Value</td><td style="text-align: right;">' . formatCurrency($summary['total_value']) . '</td></tr>';
        $html .= '</table>';
        
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Transfer Details</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 9px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Transfer Number</th><th>Date</th><th>From Branch</th><th>To Branch</th><th>Status</th><th>Items</th><th style="text-align: right;">Value</th><th>Created By</th></tr>';
        foreach ($transfers as $transfer) {
            $statusColor = $transfer['status'] == 'Completed' ? '#10b981' : ($transfer['status'] == 'Pending' ? '#f59e0b' : '#6b7280');
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($transfer['transfer_number']) . '</td>';
            $html .= '<td>' . date('M d, Y', strtotime($transfer['transfer_date'])) . '</td>';
            $html .= '<td>' . escapeHtml($transfer['from_branch'] ?? 'N/A') . '</td>';
            $html .= '<td>' . escapeHtml($transfer['to_branch'] ?? 'N/A') . '</td>';
            $html .= '<td style="color: ' . $statusColor . '; font-weight: bold;">' . escapeHtml($transfer['status']) . '</td>';
            $html .= '<td>' . $transfer['item_count'] . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($transfer['total_value']) . '</td>';
            $html .= '<td>' . escapeHtml(trim(($transfer['created_by_first'] ?? '') . ' ' . ($transfer['created_by_last'] ?? ''))) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    ReportHelper::generatePDF('Stock Transfers for Period', $html, 'Stock_Transfers_Period_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-left-right"></i> View Stock Transfers for Period</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<?php if (!$transfersTableExists): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> Stock transfers feature is not available. The stock_transfers table does not exist in the database.
    </div>
<?php else: ?>
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
                    <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="bi bi-check-circle"></i> Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="Pending" <?= $selectedStatus == 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="In Transit" <?= $selectedStatus == 'In Transit' ? 'selected' : '' ?>>In Transit</option>
                        <option value="Completed" <?= $selectedStatus == 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Cancelled" <?= $selectedStatus == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-12 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-2">Total Transfers</h6>
                    <h3 class="mb-0"><?= $summary['total_transfers'] ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-2">Total Items</h6>
                    <h3 class="mb-0"><?= number_format($summary['total_items'], 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-2">Total Value</h6>
                    <h3 class="mb-0"><?= formatCurrency($summary['total_value']) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="transfersTable">
                    <thead>
                        <tr>
                            <th>Transfer Number</th>
                            <th>Date</th>
                            <th>From Branch</th>
                            <th>To Branch</th>
                            <th>Status</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Value</th>
                            <th>Created By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No stock transfers found for the selected period</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $transfer): ?>
                                <tr>
                                    <td><span class="fw-bold"><?= escapeHtml($transfer['transfer_number']) ?></span></td>
                                    <td><?= date('M d, Y', strtotime($transfer['transfer_date'])) ?></td>
                                    <td><?= escapeHtml($transfer['from_branch'] ?? 'N/A') ?></td>
                                    <td><?= escapeHtml($transfer['to_branch'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php if ($transfer['status'] == 'Completed'): ?>
                                            <span class="badge bg-success">Completed</span>
                                        <?php elseif ($transfer['status'] == 'Pending'): ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php elseif ($transfer['status'] == 'In Transit'): ?>
                                            <span class="badge bg-info">In Transit</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= escapeHtml($transfer['status']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= $transfer['item_count'] ?></td>
                                    <td class="text-end fw-bold"><?= formatCurrency($transfer['total_value']) ?></td>
                                    <td><?= escapeHtml(trim(($transfer['created_by_first'] ?? '') . ' ' . ($transfer['created_by_last'] ?? ''))) ?></td>
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
        $('#transfersTable').DataTable({
            order: [[1, 'desc']],
            pageLength: 25,
            responsive: true
        });
    });
    </script>
<?php endif; ?>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

