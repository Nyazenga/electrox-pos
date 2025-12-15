<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('customers.create');

$pageTitle = 'Add Customer';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customerCode = 'CUST' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    $data = [
        'customer_code' => $customerCode,
        'customer_type' => $_POST['customer_type'] ?? 'Individual',
        'first_name' => $_POST['first_name'] ?? null,
        'last_name' => $_POST['last_name'] ?? null,
        'company_name' => $_POST['company_name'] ?? null,
        'tin' => $_POST['tin'] ?? null,
        'vat_number' => $_POST['vat_number'] ?? null,
        'phone' => $_POST['phone'] ?? null,
        'email' => $_POST['email'] ?? null,
        'address' => $_POST['address'] ?? null,
        'city' => $_POST['city'] ?? null,
        'credit_limit' => floatval($_POST['credit_limit'] ?? 0),
        'discount_percentage' => floatval($_POST['discount_percentage'] ?? 0),
        'customer_since' => date('Y-m-d'),
        'status' => $_POST['status'] ?? 'Active',
        'notes' => $_POST['notes'] ?? null,
        'created_by' => $_SESSION['user_id'] ?? null
    ];
    
    $id = $db->insert('customers', $data);
    if ($id) {
        $_SESSION['success_message'] = 'Customer added successfully';
        redirectTo('modules/customers/index.php');
    } else {
        $error = 'Failed to add customer';
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Add Customer</h2>
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
                    <label class="form-label">Customer Type *</label>
                    <select class="form-select" name="customer_type" id="customerType" required>
                        <option value="Individual">Individual</option>
                        <option value="Corporate">Corporate</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status *</label>
                    <select class="form-select" name="status" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
            </div>
            
            <div id="individualFields">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">First Name *</label>
                        <input type="text" class="form-control" name="first_name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last Name *</label>
                        <input type="text" class="form-control" name="last_name" required>
                    </div>
                </div>
            </div>
            
            <div id="corporateFields" style="display: none;">
                <div class="mb-3">
                    <label class="form-label">Company Name *</label>
                    <input type="text" class="form-control" name="company_name">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="2"></textarea>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">City</label>
                    <input type="text" class="form-control" name="city">
                </div>
                <div class="col-md-6">
                    <label class="form-label">TIN</label>
                    <input type="text" class="form-control" name="tin">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">VAT Number</label>
                    <input type="text" class="form-control" name="vat_number">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Credit Limit</label>
                    <input type="number" class="form-control" name="credit_limit" step="0.01" min="0" value="0">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Discount Percentage</label>
                <input type="number" class="form-control" name="discount_percentage" step="0.01" min="0" max="100" value="0">
            </div>
            
            <div class="mb-3">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="3"></textarea>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Customer</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('customerType').addEventListener('change', function() {
    const type = this.value;
    document.getElementById('individualFields').style.display = type === 'Individual' ? 'block' : 'none';
    document.getElementById('corporateFields').style.display = type === 'Corporate' ? 'block' : 'none';
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


