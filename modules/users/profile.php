<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();

$pageTitle = 'My Profile';

$db = Database::getInstance();
$user = $auth->getCurrentUser();
$role = $db->getRow("SELECT name FROM roles WHERE id = :id", [':id' => $user['role_id']]);
$branch = null;
if ($user['branch_id']) {
    $branch = $db->getRow("SELECT branch_name FROM branches WHERE id = :id", [':id' => $user['branch_id']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = sanitizeInput($_POST['first_name'] ?? '');
    $last_name = sanitizeInput($_POST['last_name'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    
    $db->update('users', [
        'first_name' => $first_name,
        'last_name' => $last_name,
        'phone' => $phone
    ], ['id' => $user['id']]);
    
    $_SESSION['first_name'] = $first_name;
    $_SESSION['last_name'] = $last_name;
    
    $success = 'Profile updated successfully!';
    $user = $auth->getCurrentUser(); // Refresh user data
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>My Profile</h2>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?= escapeHtml($success) ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Profile Information</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label>Username</label>
                        <input type="text" value="<?= escapeHtml($user['username']) ?>" class="form-control" disabled>
                    </div>
                    <div class="mb-3">
                        <label>Email</label>
                        <input type="email" value="<?= escapeHtml($user['email']) ?>" class="form-control" disabled>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>First Name</label>
                            <input type="text" name="first_name" value="<?= escapeHtml($user['first_name']) ?>" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Last Name</label>
                            <input type="text" name="last_name" value="<?= escapeHtml($user['last_name']) ?>" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Phone</label>
                        <input type="text" name="phone" value="<?= escapeHtml($user['phone'] ?? '') ?>" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update Profile</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Account Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Role:</th>
                        <td><span class="badge bg-info"><?= escapeHtml($role['name'] ?? 'User') ?></span></td>
                    </tr>
                    <tr>
                        <th>Branch:</th>
                        <td><?= escapeHtml($branch['branch_name'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><span class="badge bg-<?= $user['status'] == 'active' ? 'success' : 'secondary' ?>"><?= escapeHtml(ucfirst($user['status'])) ?></span></td>
                    </tr>
                    <tr>
                        <th>Last Login:</th>
                        <td><?= $user['last_login'] ? formatDateTime($user['last_login']) : 'Never' ?></td>
                    </tr>
                    <tr>
                        <th>Member Since:</th>
                        <td><?= formatDateTime($user['created_at']) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

