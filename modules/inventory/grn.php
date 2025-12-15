<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.view');

$pageTitle = 'Goods Received Notes (GRN)';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

$grns = $db->getRows("SELECT grn.*, 
                      COALESCE(s.name, 'N/A') as supplier_name, 
                      b.branch_name 
                      FROM goods_received_notes grn 
                      LEFT JOIN suppliers s ON grn.supplier_id = s.id 
                      LEFT JOIN branches b ON grn.branch_id = b.id 
                      ORDER BY grn.created_at DESC");
if ($grns === false) $grns = [];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Goods Received Notes (GRN)</h2>
    <?php if ($auth->hasPermission('inventory.create')): ?>
        <a href="grn_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New GRN</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>GRN Number</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>Branch</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grns as $grn): ?>
                <tr>
                    <td><?= escapeHtml($grn['grn_number'] ?? 'N/A') ?></td>
                    <td><?= formatDate($grn['received_date'] ?? '') ?></td>
                    <td><?= escapeHtml($grn['supplier_name'] ?? 'N/A') ?></td>
                    <td><?= escapeHtml($grn['branch_name'] ?? 'N/A') ?></td>
                    <td><?= formatCurrency($grn['total_value'] ?? 0) ?></td>
                    <td>
                        <span class="badge bg-<?= ($grn['status'] ?? 'Draft') == 'Approved' ? 'success' : (($grn['status'] ?? 'Draft') == 'Rejected' ? 'danger' : 'warning') ?>">
                            <?= escapeHtml($grn['status'] ?? 'Draft') ?>
                        </span>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="grn_view.php?id=<?= $grn['id'] ?>" class="btn btn-info" title="View"><i class="bi bi-eye"></i></a>
                            <a href="grn_print.php?id=<?= $grn['id'] ?>" class="btn btn-primary" target="_blank" title="Print"><i class="bi bi-printer"></i></a>
                            <?php if ($auth->hasPermission('inventory.edit') && ($grn['status'] ?? 'Draft') == 'Draft'): ?>
                                <a href="grn_add.php?id=<?= $grn['id'] ?>" class="btn btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                                <button type="button" class="btn btn-secondary" onclick='showStatusModal(<?= $grn['id'] ?>, <?= json_encode($grn['status'] ?? 'Draft') ?>, <?= json_encode($grn['grn_number'] ?? '') ?>)' title="Change Status">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Status Change Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change GRN Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>GRN:</strong> <span id="statusGRNNumber"></span></p>
                <p><strong>Current Status:</strong> <span id="statusCurrentStatus"></span></p>
                <div class="mb-3">
                    <label class="form-label">New Status</label>
                    <select id="newStatusSelect" class="form-select">
                        <option value="Draft">Draft</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="updateGRNStatus()">Update Status</button>
            </div>
        </div>
    </div>
</div>

<script>
const BASE_URL_GRN = <?= json_encode(BASE_URL) ?>;
let currentGRNId = null;

function showStatusModal(grnId, currentStatus, grnNumber) {
    currentGRNId = grnId;
    document.getElementById('statusGRNNumber').textContent = grnNumber;
    document.getElementById('statusCurrentStatus').textContent = currentStatus;
    document.getElementById('newStatusSelect').value = currentStatus;
    
    const modal = new bootstrap.Modal(document.getElementById('statusModal'));
    modal.show();
}

function updateGRNStatus() {
    if (!currentGRNId) return;
    
    const newStatus = document.getElementById('newStatusSelect').value;
    
    fetch(BASE_URL_GRN + 'ajax/update_grn_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            grn_id: currentGRNId,
            status: newStatus
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'GRN status updated successfully',
                confirmButtonColor: '#1e3a8a'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: data.message || 'Failed to update GRN status',
                confirmButtonColor: '#d33'
            });
        }
    })
    .catch(error => {
        console.error('Status update error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'An error occurred while updating the status',
            confirmButtonColor: '#d33'
        });
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

