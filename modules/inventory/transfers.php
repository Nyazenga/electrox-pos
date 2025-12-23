<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.view');

$pageTitle = 'Stock Transfers';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

$transfers = $db->getRows("SELECT st.*, 
                           b1.branch_name as from_branch, 
                           b2.branch_name as to_branch,
                           u.first_name, u.last_name
                           FROM stock_transfers st 
                           LEFT JOIN branches b1 ON st.from_branch_id = b1.id 
                           LEFT JOIN branches b2 ON st.to_branch_id = b2.id 
                           LEFT JOIN users u ON st.initiated_by = u.id 
                           ORDER BY st.created_at DESC");
if ($transfers === false) $transfers = [];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Stock Transfers</h2>
    <?php if ($auth->hasPermission('inventory.create')): ?>
        <a href="transfer_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New Transfer</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Transfer #</th>
                    <th>Date</th>
                    <th>From Branch</th>
                    <th>To Branch</th>
                    <th>Items</th>
                    <th>Status</th>
                    <th>Transferred By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transfers as $transfer): ?>
                <tr>
                    <td><?= escapeHtml($transfer['transfer_number'] ?? 'N/A') ?></td>
                    <td><?= formatDate($transfer['transfer_date'] ?? '') ?></td>
                    <td><?= escapeHtml($transfer['from_branch'] ?? 'N/A') ?></td>
                    <td><?= escapeHtml($transfer['to_branch'] ?? 'N/A') ?></td>
                    <td><?= $transfer['total_items'] ?? 0 ?> items</td>
                    <td>
                        <span class="badge bg-<?= ($transfer['status'] ?? 'Pending') == 'Completed' ? 'success' : (($transfer['status'] ?? 'Pending') == 'Rejected' ? 'danger' : 'warning') ?>">
                            <?= escapeHtml($transfer['status'] ?? 'Pending') ?>
                        </span>
                    </td>
                    <td><?= escapeHtml(trim(($transfer['first_name'] ?? '') . ' ' . ($transfer['last_name'] ?? '')) ?: 'N/A') ?></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="transfer_view.php?id=<?= $transfer['id'] ?>" class="btn btn-info" title="View"><i class="bi bi-eye"></i></a>
                            <?php if ($auth->hasPermission('transfers.change_status') && ($transfer['status'] ?? 'Pending') == 'Pending'): ?>
                                <button type="button" class="btn btn-success" onclick='approveTransfer(<?= $transfer['id'] ?>, <?= json_encode($transfer['transfer_number'] ?? '') ?>)' title="Approve">
                                    <i class="bi bi-check-circle"></i>
                                </button>
                                <button type="button" class="btn btn-danger" onclick='rejectTransfer(<?= $transfer['id'] ?>, <?= json_encode($transfer['transfer_number'] ?? '') ?>)' title="Reject">
                                    <i class="bi bi-x-circle"></i>
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

<script>
const BASE_URL_TRANSFER = <?= json_encode(BASE_URL) ?>;

function approveTransfer(transferId, transferNumber) {
    Swal.fire({
        title: 'Approve Transfer?',
        text: 'Are you sure you want to approve transfer ' + transferNumber + '?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, approve it!'
    }).then((result) => {
        if (result.isConfirmed) {
            updateTransferStatus(transferId, 'Approved');
        }
    });
}

function rejectTransfer(transferId, transferNumber) {
    Swal.fire({
        title: 'Reject Transfer?',
        text: 'Are you sure you want to reject transfer ' + transferNumber + '?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, reject it!'
    }).then((result) => {
        if (result.isConfirmed) {
            updateTransferStatus(transferId, 'Rejected');
        }
    });
}

function updateTransferStatus(transferId, status) {
    fetch(BASE_URL_TRANSFER + 'ajax/update_transfer_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            transfer_id: transferId,
            status: status
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'Transfer status updated successfully',
                confirmButtonColor: '#1e3a8a'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: data.message || 'Failed to update transfer status',
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

