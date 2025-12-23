<?php
/**
 * Check Fiscalization Status for All Branches
 * Shows if fiscalization is enabled and device status
 * Uses DataTables for searchable, filterable, paginated display
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

$pageTitle = 'Fiscalization Status by Branch';
$primaryDb = Database::getPrimaryInstance();

// Get all branches with fiscalization status
$branches = $primaryDb->getRows("
    SELECT 
        b.*,
        fd.device_id,
        fd.device_serial_no,
        fd.is_registered,
        fd.is_active,
        fc.qr_url,
        (SELECT COUNT(*) FROM fiscal_receipts WHERE branch_id = b.id) as total_receipts,
        (SELECT COUNT(*) FROM fiscal_receipts WHERE branch_id = b.id AND submission_status = 'Submitted') as submitted_receipts
    FROM branches b
    LEFT JOIN fiscal_devices fd ON b.id = fd.branch_id
    LEFT JOIN fiscal_config fc ON b.id = fc.branch_id AND fd.device_id = fc.device_id
    ORDER BY b.branch_name, fd.device_id
");

require_once APP_PATH . '/includes/header.php';
?>

<style>
    .fiscal-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
    }
    .badge-success { background: #10b981; color: white; }
    .badge-danger { background: #ef4444; color: white; }
    .badge-warning { background: #f59e0b; color: white; }
    .badge-info { background: #3b82f6; color: white; }
    .badge-ready { background: #10b981; color: white; }
</style>

<div class="content-wrapper">
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-12">
                <h1 class="h3 mb-0">Fiscalization Status by Branch</h1>
                <p class="text-muted">Total: <strong><?= count($branches) ?></strong> branches configured</p>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">Quick Actions</h5>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?= BASE_URL ?>view_all_fiscalizations.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-receipt-cutoff"></i> View All Fiscalizations
                    </a>
                    <a href="<?= BASE_URL ?>modules/settings/fiscalization.php" class="btn btn-secondary btn-sm">
                        <i class="bi bi-gear"></i> Configure Fiscalization Settings
                    </a>
                </div>
            </div>
        </div>

        <!-- Data Table -->
        <div class="card">
            <div class="card-body">
                <table id="fiscalStatusTable" class="table table-striped table-hover data-table">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Fiscalization Enabled</th>
                            <th>Device ID</th>
                            <th>Device Serial</th>
                            <th>Registered</th>
                            <th>Active</th>
                            <th>QR URL</th>
                            <th>Total Receipts</th>
                            <th>Submitted</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($branches)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox" style="font-size: 48px;"></i>
                                    <p class="mt-2">No branches found</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($branches as $branch): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($branch['branch_name']) ?></strong></td>
                                    <td>
                                        <?php if ($branch['fiscalization_enabled']): ?>
                                            <span class="fiscal-badge badge-success">Enabled</span>
                                        <?php else: ?>
                                            <span class="fiscal-badge badge-danger">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $branch['device_id'] ? htmlspecialchars($branch['device_id']) : '<span class="text-muted">Not Set</span>' ?></td>
                                    <td><?= $branch['device_serial_no'] ? htmlspecialchars($branch['device_serial_no']) : '<span class="text-muted">Not Set</span>' ?></td>
                                    <td>
                                        <?php if ($branch['is_registered']): ?>
                                            <span class="fiscal-badge badge-success">Yes</span>
                                        <?php else: ?>
                                            <span class="fiscal-badge badge-warning">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($branch['is_active']): ?>
                                            <span class="fiscal-badge badge-success">Yes</span>
                                        <?php else: ?>
                                            <span class="fiscal-badge badge-danger">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($branch['qr_url']): ?>
                                            <small><?= htmlspecialchars($branch['qr_url']) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Not Set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $branch['total_receipts'] ?? 0 ?></td>
                                    <td><?= $branch['submitted_receipts'] ?? 0 ?></td>
                                    <td>
                                        <?php 
                                        $status = [];
                                        if (!$branch['fiscalization_enabled']) {
                                            $status[] = 'Fiscalization Disabled';
                                        }
                                        if (!$branch['device_id']) {
                                            $status[] = 'No Device ID';
                                        }
                                        if (!$branch['is_registered']) {
                                            $status[] = 'Device Not Registered';
                                        }
                                        if (!$branch['is_active']) {
                                            $status[] = 'Device Inactive';
                                        }
                                        
                                        if (empty($status)) {
                                            echo '<span class="fiscal-badge badge-ready">Ready</span>';
                                        } else {
                                            echo '<span class="fiscal-badge badge-warning">' . implode(', ', $status) . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    if ($.fn.DataTable) {
        $('#fiscalStatusTable').DataTable({
            order: [[0, 'asc']], // Sort by Branch name ascending
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            autoWidth: false,
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "No entries to show",
                infoFiltered: "(filtered from _MAX_ total entries)",
                paginate: {
                    first: "First",
                    last: "Last",
                    next: "Next",
                    previous: "Previous"
                }
            }
        });
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
