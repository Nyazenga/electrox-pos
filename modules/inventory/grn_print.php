<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.view');

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

$companyName = getSetting('company_name', SYSTEM_NAME);
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');
$companyEmail = getSetting('company_email', '');

$pageTitle = 'GRN Print - ' . $grn['grn_number'];
require_once APP_PATH . '/includes/header.php';
?>

<style>
@media print {
    .no-print, .sidebar, .topbar, header, footer, .navbar {
        display: none !important;
    }
    body {
        margin: 0;
        padding: 0;
        background: white !important;
    }
    .content-area {
        display: block !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .print-container {
        padding: 20px;
    }
}
.print-container {
    max-width: 210mm;
    margin: 0 auto;
    padding: 20px;
    background: white;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h2>GRN Print - <?= escapeHtml($grn['grn_number']) ?></h2>
    <div>
        <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button>
        <a href="grn.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<div class="print-container">
    <div class="text-center mb-4">
        <h3><?= escapeHtml($companyName) ?></h3>
        <p><?= nl2br(escapeHtml($companyAddress)) ?></p>
        <?php if ($companyPhone): ?>
            <p><strong>Phone:</strong> <?= escapeHtml($companyPhone) ?></p>
        <?php endif; ?>
        <?php if ($companyEmail): ?>
            <p><strong>Email:</strong> <?= escapeHtml($companyEmail) ?></p>
        <?php endif; ?>
    </div>
    
    <hr>
    
    <h4 class="text-center mb-4">GOODS RECEIVED NOTE (GRN)</h4>
    
    <div class="row mb-4">
        <div class="col-md-6">
            <table class="table table-borderless">
                <tr>
                    <th width="150">GRN Number:</th>
                    <td><?= escapeHtml($grn['grn_number']) ?></td>
                </tr>
                <tr>
                    <th>Date:</th>
                    <td><?= formatDate($grn['received_date']) ?></td>
                </tr>
                <tr>
                    <th>Branch:</th>
                    <td><?= escapeHtml($grn['branch_name'] ?? 'N/A') ?></td>
                </tr>
                <tr>
                    <th>Received By:</th>
                    <td><?= escapeHtml(trim(($grn['first_name'] ?? '') . ' ' . ($grn['last_name'] ?? '')) ?: 'N/A') ?></td>
                </tr>
            </table>
        </div>
        <div class="col-md-6">
            <?php if ($grn['supplier_name']): ?>
            <table class="table table-borderless">
                <tr>
                    <th width="150">Supplier:</th>
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
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>Category</th>
                <th>Quantity</th>
                <th>Cost Price</th>
                <th>Selling Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php $counter = 1; foreach ($grnItems as $item): ?>
                <tr>
                    <td><?= $counter++ ?></td>
                    <td>
                        <strong><?= escapeHtml(trim(($item['brand'] ?? '') . ' ' . ($item['model'] ?? ''))) ?></strong>
                        <br>
                        <small><?= escapeHtml($item['product_code'] ?? 'N/A') ?></small>
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
                <td colspan="6" class="text-end"><strong>Total Value:</strong></td>
                <td><strong><?= formatCurrency($grn['total_value'] ?? 0) ?></strong></td>
            </tr>
        </tfoot>
    </table>
    
    <?php if ($grn['notes']): ?>
    <div class="mt-4">
        <strong>Notes:</strong>
        <p><?= nl2br(escapeHtml($grn['notes'])) ?></p>
    </div>
    <?php endif; ?>
    
    <div class="mt-4">
        <p><strong>Status:</strong> 
            <span class="badge bg-<?= ($grn['status'] ?? 'Draft') == 'Approved' ? 'success' : (($grn['status'] ?? 'Draft') == 'Rejected' ? 'danger' : 'warning') ?>">
                <?= escapeHtml($grn['status'] ?? 'Draft') ?>
            </span>
        </p>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


