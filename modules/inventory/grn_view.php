<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.view');

$pageTitle = 'GRN Details';

$db = Database::getInstance();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    redirectTo('modules/inventory/grn.php');
}

$grn = $db->getRow("SELECT grn.*, 
                    s.name as supplier_name, s.contact_person, s.phone as supplier_phone, s.email as supplier_email,
                    b.branch_name, b.address as branch_address,
                    u.first_name, u.last_name
                    FROM goods_received_notes grn 
                    LEFT JOIN suppliers s ON grn.supplier_id = s.id 
                    LEFT JOIN branches b ON grn.branch_id = b.id 
                    LEFT JOIN users u ON grn.received_by = u.id
                    WHERE grn.id = :id", [':id' => $id]);

if (!$grn) {
    redirectTo('modules/inventory/grn.php');
}

$grnItems = $db->getRows("SELECT gi.*, p.brand, p.model, p.product_code, c.name as category_name
                          FROM grn_items gi
                          LEFT JOIN products p ON gi.product_id = p.id
                          LEFT JOIN product_categories c ON p.category_id = c.id
                          WHERE gi.grn_id = :id
                          ORDER BY gi.id", [':id' => $id]);
if ($grnItems === false) $grnItems = [];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>GRN Details - <?= escapeHtml($grn['grn_number']) ?></h2>
    <div>
        <?php if ($auth->hasPermission('inventory.edit')): ?>
            <a href="grn_print.php?id=<?= $id ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer"></i> Print</a>
        <?php endif; ?>
        <a href="grn.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">GRN Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="200">GRN Number:</th>
                        <td><?= escapeHtml($grn['grn_number']) ?></td>
                    </tr>
                    <tr>
                        <th>Received Date:</th>
                        <td><?= formatDate($grn['received_date']) ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td>
                            <span class="badge bg-<?= ($grn['status'] ?? 'Draft') == 'Approved' ? 'success' : (($grn['status'] ?? 'Draft') == 'Rejected' ? 'danger' : 'warning') ?>">
                                <?= escapeHtml($grn['status'] ?? 'Draft') ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Branch:</th>
                        <td><?= escapeHtml($grn['branch_name'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Received By:</th>
                        <td><?= escapeHtml(trim(($grn['first_name'] ?? '') . ' ' . ($grn['last_name'] ?? '')) ?: 'N/A') ?></td>
                    </tr>
                    <?php if ($grn['notes']): ?>
                    <tr>
                        <th>Notes:</th>
                        <td><?= nl2br(escapeHtml($grn['notes'])) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        
        <?php if ($grn['supplier_name']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Supplier Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="200">Supplier Name:</th>
                        <td><?= escapeHtml($grn['supplier_name']) ?></td>
                    </tr>
                    <?php if ($grn['contact_person']): ?>
                    <tr>
                        <th>Contact Person:</th>
                        <td><?= escapeHtml($grn['contact_person']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($grn['supplier_phone']): ?>
                    <tr>
                        <th>Phone:</th>
                        <td><?= escapeHtml($grn['supplier_phone']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($grn['supplier_email']): ?>
                    <tr>
                        <th>Email:</th>
                        <td><?= escapeHtml($grn['supplier_email']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
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
                                <th>Cost Price</th>
                                <th>Selling Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grnItems as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= escapeHtml(trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''))) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= escapeHtml($item['product_code'] ?? 'N/A') ?></small>
                                    </td>
                                    <td><?= escapeHtml($item['category_name'] ?? 'N/A') ?></td>
                                    <td><?= $item['quantity'] ?></td>
                                    <td><?= formatCurrency($item['cost_price'] ?? 0) ?></td>
                                    <td><?= formatCurrency($item['selling_price'] ?? 0) ?></td>
                                    <td><?= formatCurrency(($item['cost_price'] ?? 0) * ($item['quantity'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="text-end"><strong>Total Value:</strong></td>
                                <td><strong><?= formatCurrency($grn['total_value'] ?? 0) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


