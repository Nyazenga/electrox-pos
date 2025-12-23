<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('users.view');

$pageTitle = 'Users';

$db = Database::getInstance();
$users = $db->getRows("SELECT u.*, r.name as role_name, b.branch_name FROM users u LEFT JOIN roles r ON u.role_id = r.id LEFT JOIN branches b ON u.branch_id = b.id WHERE u.deleted_at IS NULL ORDER BY u.created_at DESC");

// Get permission counts for each user
foreach ($users as &$user) {
    $permCount = $db->getCount(
        "SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = :role_id",
        [':role_id' => $user['role_id'] ?? 0]
    );
    $user['permission_count'] = $permCount !== false ? $permCount : 0;
}
unset($user);

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Users</h2>
    <?php if ($auth->hasPermission('users.create')): ?>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add User</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Permissions</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= escapeHtml($user['first_name'] . ' ' . $user['last_name']) ?></td>
                        <td><?= escapeHtml($user['email']) ?></td>
                        <td>
                            <span class="badge bg-info"><?= escapeHtml($user['role_name'] ?? 'N/A') ?></span>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?= $user['permission_count'] ?? 0 ?> permissions</span>
                        </td>
                        <td><?= escapeHtml($user['branch_name'] ?? 'N/A') ?></td>
                        <td><span class="badge bg-<?= $user['status'] == 'active' ? 'success' : 'secondary' ?>"><?= escapeHtml(ucfirst($user['status'])) ?></span></td>
                        <td>
                            <a href="view.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                            <?php if ($auth->hasPermission('users.edit')): ?>
                                <a href="edit.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

