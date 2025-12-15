<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('branches.edit');

$pageTitle = 'Edit Branch';

$db = Database::getInstance();
$branchId = $_GET['id'] ?? null;

if (!$branchId) {
    $_SESSION['error_message'] = 'Branch ID is required';
    redirectTo('modules/branches/index.php');
}

$branch = $db->getRow("SELECT * FROM branches WHERE id = :id", [':id' => $branchId]);

if (!$branch) {
    $_SESSION['error_message'] = 'Branch not found';
    redirectTo('modules/branches/index.php');
}

$users = $db->getRows("SELECT * FROM users WHERE status = 'active' AND deleted_at IS NULL ORDER BY first_name, last_name");
if ($users === false) $users = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = [
        'branch_name' => $_POST['branch_name'] ?? '',
        'address' => $_POST['address'] ?? null,
        'city' => $_POST['city'] ?? null,
        'phone' => $_POST['phone'] ?? null,
        'email' => $_POST['email'] ?? null,
        'manager_id' => !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null,
        'status' => $_POST['status'] ?? 'Active',
        'opening_date' => $_POST['opening_date'] ?? null
    ];
    
    $result = $db->update('branches', $data, ['id' => $branchId]);
    if ($result !== false) {
        $_SESSION['success_message'] = 'Branch updated successfully';
        redirectTo('modules/branches/index.php');
    } else {
        $error = 'Failed to update branch';
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Branch</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= escapeHtml($_SESSION['error_message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= escapeHtml($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Branch Code</label>
                    <input type="text" class="form-control" value="<?= escapeHtml($branch['branch_code']) ?>" disabled>
                    <small class="text-muted">Branch code cannot be changed</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Branch Name *</label>
                    <input type="text" class="form-control" name="branch_name" value="<?= escapeHtml($branch['branch_name']) ?>" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Status *</label>
                    <select class="form-select" name="status" required>
                        <option value="Active" <?= $branch['status'] == 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $branch['status'] == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Opening Date</label>
                    <input type="date" class="form-control" name="opening_date" value="<?= $branch['opening_date'] ? date('Y-m-d', strtotime($branch['opening_date'])) : '' ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="address" rows="2"><?= escapeHtml($branch['address'] ?? '') ?></textarea>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">City</label>
                    <input type="text" class="form-control" name="city" value="<?= escapeHtml($branch['city'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone" value="<?= escapeHtml($branch['phone'] ?? '') ?>">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" value="<?= escapeHtml($branch['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Manager</label>
                    <select class="form-select" name="manager_id">
                        <option value="">Select Manager</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>" <?= $branch['manager_id'] == $user['id'] ? 'selected' : '' ?>>
                                <?= escapeHtml($user['first_name'] . ' ' . $user['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update Branch</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


