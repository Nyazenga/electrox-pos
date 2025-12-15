<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.view');

$pageTitle = 'Transfer Details';

$db = Database::getInstance();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    redirectTo('modules/inventory/transfers.php');
}

$transfer = $db->getRow("SELECT st.*, 
                        b1.branch_name as from_branch, b1.address as from_address,
                        b2.branch_name as to_branch, b2.address as to_address,
                        u1.first_name as initiated_first, u1.last_name as initiated_last,
                        u2.first_name as approved_first, u2.last_name as approved_last,
                        u3.first_name as received_first, u3.last_name as received_last
                        FROM stock_transfers st 
                        LEFT JOIN branches b1 ON st.from_branch_id = b1.id 
                        LEFT JOIN branches b2 ON st.to_branch_id = b2.id 
                        LEFT JOIN users u1 ON st.initiated_by = u1.id 
                        LEFT JOIN users u2 ON st.approved_by = u2.id 
                        LEFT JOIN users u3 ON st.received_by = u3.id
                        WHERE st.id = :id", [':id' => $id]);

if (!$transfer) {
    redirectTo('modules/inventory/transfers.php');
}

$transferItems = $db->getRows("SELECT ti.*, p.brand, p.model, p.product_code, c.name as category_name
                              FROM transfer_items ti
                              LEFT JOIN products p ON ti.product_id = p.id
                              LEFT JOIN product_categories c ON p.category_id = c.id
                              WHERE ti.transfer_id = :id
                              ORDER BY ti.id", [':id' => $id]);
if ($transferItems === false) $transferItems = [];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Transfer Details - <?= escapeHtml($transfer['transfer_number']) ?></h2>
    <div>
        <a href="transfers.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Transfer Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="200">Transfer Number:</th>
                        <td><?= escapeHtml($transfer['transfer_number']) ?></td>
                    </tr>
                    <tr>
                        <th>Transfer Date:</th>
                        <td><?= formatDate($transfer['transfer_date']) ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td>
                            <span class="badge bg-<?= ($transfer['status'] ?? 'Pending') == 'Completed' ? 'success' : (($transfer['status'] ?? 'Pending') == 'Rejected' ? 'danger' : 'warning') ?>">
                                <?= escapeHtml($transfer['status'] ?? 'Pending') ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>From Branch:</th>
                        <td><?= escapeHtml($transfer['from_branch'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>To Branch:</th>
                        <td><?= escapeHtml($transfer['to_branch'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Initiated By:</th>
                        <td><?= escapeHtml(trim(($transfer['initiated_first'] ?? '') . ' ' . ($transfer['initiated_last'] ?? '')) ?: 'N/A') ?></td>
                    </tr>
                    <?php if ($transfer['approved_by']): ?>
                    <tr>
                        <th>Approved By:</th>
                        <td><?= escapeHtml(trim(($transfer['approved_first'] ?? '') . ' ' . ($transfer['approved_last'] ?? '')) ?: 'N/A') ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($transfer['received_by']): ?>
                    <tr>
                        <th>Received By:</th>
                        <td><?= escapeHtml(trim(($transfer['received_first'] ?? '') . ' ' . ($transfer['received_last'] ?? '')) ?: 'N/A') ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($transfer['notes']): ?>
                    <tr>
                        <th>Notes:</th>
                        <td><?= nl2br(escapeHtml($transfer['notes'])) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Items</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transferItems as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= escapeHtml(trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''))) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= escapeHtml($item['product_code'] ?? 'N/A') ?></small>
                                    </td>
                                    <td><?= escapeHtml($item['category_name'] ?? 'N/A') ?></td>
                                    <td><?= $item['quantity'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="text-end"><strong>Total Items:</strong></td>
                                <td><strong><?= $transfer['total_items'] ?? 0 ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


