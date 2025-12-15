<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('suppliers.edit');

$pageTitle = 'Edit Supplier';

$db = Database::getInstance();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    redirectTo('modules/suppliers/index.php');
}

$supplier = $db->getRow("SELECT * FROM suppliers WHERE id = :id", [':id' => $id]);

if (!$supplier) {
    redirectTo('modules/suppliers/index.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'supplier_code' => $_POST['supplier_code'] ?? null,
        'name' => trim($_POST['name'] ?? ''),
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'tin' => trim($_POST['tin'] ?? ''),
        'payment_terms' => trim($_POST['payment_terms'] ?? ''),
        'credit_limit' => floatval($_POST['credit_limit'] ?? 0),
        'status' => $_POST['status'] ?? 'Active'
    ];
    
    if (empty($data['name'])) {
        $error = 'Supplier name is required';
    } else {
        $result = $db->update('suppliers', $data, ['id' => $id]);
        if ($result !== false) {
            logActivity($_SESSION['user_id'], 'supplier_updated', ['supplier_id' => $id]);
            redirectTo('modules/suppliers/index.php');
        } else {
            $error = 'Failed to update supplier: ' . $db->getLastError();
        }
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Supplier</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= escapeHtml($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Supplier Code</label>
                    <input type="text" class="form-control" name="supplier_code" value="<?= escapeHtml($supplier['supplier_code'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Supplier Name *</label>
                    <input type="text" class="form-control" name="name" value="<?= escapeHtml($supplier['name']) ?>" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Contact Person</label>
                    <input type="text" class="form-control" name="contact_person" value="<?= escapeHtml($supplier['contact_person'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone" value="<?= escapeHtml($supplier['phone'] ?? '') ?>">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" value="<?= escapeHtml($supplier['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">TIN Number</label>
                    <input type="text" class="form-control" name="tin" value="<?= escapeHtml($supplier['tin'] ?? '') ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="3"><?= escapeHtml($supplier['address'] ?? '') ?></textarea>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Payment Terms</label>
                    <input type="text" class="form-control" name="payment_terms" value="<?= escapeHtml($supplier['payment_terms'] ?? '') ?>" placeholder="e.g., Net 30">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Credit Limit</label>
                    <input type="number" class="form-control" name="credit_limit" step="0.01" value="<?= $supplier['credit_limit'] ?? 0 ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="Active" <?= ($supplier['status'] ?? 'Active') == 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= ($supplier['status'] ?? 'Active') == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update Supplier</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


