<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('users.edit');

$pageTitle = 'Edit User';

$db = Database::getInstance();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    redirectTo('modules/users/index.php');
}

$user = $db->getRow("SELECT * FROM users WHERE id = :id AND deleted_at IS NULL", [':id' => $id]);

if (!$user) {
    redirectTo('modules/users/index.php');
}

$roles = $db->getRows("SELECT * FROM roles ORDER BY name");
if ($roles === false) $roles = [];

$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = [
        'username' => $_POST['username'] ?? '',
        'email' => $_POST['email'] ?? '',
        'first_name' => $_POST['first_name'] ?? null,
        'last_name' => $_POST['last_name'] ?? null,
        'phone' => $_POST['phone'] ?? null,
        'branch_id' => !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null,
        'role_id' => !empty($_POST['role_id']) ? intval($_POST['role_id']) : null,
        'status' => $_POST['status'] ?? 'active',
        'updated_by' => $_SESSION['user_id'] ?? null
    ];
    
    // Only update password if provided
    if (!empty($_POST['password'])) {
        $data['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
    }
    
    $result = $db->update('users', $data, ['id' => $id]);
    if ($result !== false) {
        logActivity($_SESSION['user_id'], 'user_updated', ['user_id' => $id]);
        $_SESSION['success_message'] = 'User updated successfully';
        redirectTo('modules/users/index.php');
    } else {
        $error = 'Failed to update user: ' . $db->getLastError();
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit User</h2>
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
                    <label class="form-label">Username *</label>
                    <input type="text" class="form-control" name="username" value="<?= escapeHtml($user['username']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email *</label>
                    <input type="email" class="form-control" name="email" value="<?= escapeHtml($user['email']) ?>" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <input type="password" class="form-control" name="password" placeholder="Leave blank to keep current password">
                    <small class="text-muted">Leave blank to keep current password</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status *</label>
                    <select class="form-select" name="status" required>
                        <option value="active" <?= $user['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $user['status'] == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="locked" <?= $user['status'] == 'locked' ? 'selected' : '' ?>>Locked</option>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" name="first_name" value="<?= escapeHtml($user['first_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Last Name</label>
                    <input type="text" class="form-control" name="last_name" value="<?= escapeHtml($user['last_name'] ?? '') ?>">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone" value="<?= escapeHtml($user['phone'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Branch</label>
                    <select class="form-select" name="branch_id">
                        <option value="">Select Branch</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= ($user['branch_id'] ?? null) == $branch['id'] ? 'selected' : '' ?>>
                                <?= escapeHtml($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Role *</label>
                <select class="form-select" name="role_id" required>
                    <option value="">Select Role</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= $role['id'] ?>" <?= ($user['role_id'] ?? null) == $role['id'] ? 'selected' : '' ?>>
                            <?= escapeHtml($role['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update User</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


