<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('branches.create');

$pageTitle = 'Add Branch';

$db = Database::getInstance();

$users = $db->getRows("SELECT * FROM users WHERE status = 'active' AND deleted_at IS NULL ORDER BY first_name, last_name");
if ($users === false) $users = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $branchCode = 'BR' . strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $_POST['branch_name']), 0, 3)) . date('Ymd');
    
    $data = [
        'branch_code' => $branchCode,
        'branch_name' => $_POST['branch_name'] ?? '',
        'address' => $_POST['address'] ?? null,
        'city' => $_POST['city'] ?? null,
        'phone' => $_POST['phone'] ?? null,
        'email' => $_POST['email'] ?? null,
        'manager_id' => !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null,
        'status' => $_POST['status'] ?? 'Active',
        'opening_date' => $_POST['opening_date'] ?? null
    ];
    
    $id = $db->insert('branches', $data);
    if ($id) {
        $_SESSION['success_message'] = 'Branch added successfully';
        redirectTo('modules/branches/index.php');
    } else {
        $error = 'Failed to add branch';
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Add Branch</h2>
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
                    <label class="form-label">Branch Name *</label>
                    <input type="text" class="form-control" name="branch_name" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status *</label>
                    <select class="form-select" name="status" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
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
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Manager</label>
                    <select class="form-select" name="manager_id">
                        <option value="">Select Manager</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>">
                                <?= escapeHtml($user['first_name'] . ' ' . $user['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Opening Date</label>
                <input type="date" class="form-control" name="opening_date">
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Branch</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


