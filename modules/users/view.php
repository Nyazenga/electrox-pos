<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('users.view');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    redirectTo('modules/users/index.php');
}

$pageTitle = 'View User';

$db = Database::getInstance();
$user = $db->getRow("SELECT u.*, r.name as role_name, b.branch_name FROM users u LEFT JOIN roles r ON u.role_id = r.id LEFT JOIN branches b ON u.branch_id = b.id WHERE u.id = :id", [':id' => $id]);

if (!$user) {
    redirectTo('modules/users/index.php');
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>User Details</h2>
    <div>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        <?php if ($auth->hasPermission('users.edit')): ?>
            <a href="edit.php?id=<?= $user['id'] ?>" class="btn btn-warning"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">User Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Username:</th>
                        <td><?= escapeHtml($user['username']) ?></td>
                    </tr>
                    <tr>
                        <th>Name:</th>
                        <td><?= escapeHtml($user['first_name'] . ' ' . $user['last_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?= escapeHtml($user['email']) ?></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?= escapeHtml($user['phone'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Role:</th>
                        <td>
                            <span class="badge bg-info"><?= escapeHtml($user['role_name'] ?? 'N/A') ?></span>
                            <?php if ($auth->hasPermission('users.edit')): ?>
                                <a href="edit.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary ms-2">
                                    <i class="bi bi-pencil"></i> Change Role
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Branch:</th>
                        <td><?= escapeHtml($user['branch_name'] ?? 'N/A') ?></td>
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
                        <th>Created:</th>
                        <td><?= formatDateTime($user['created_at']) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// Get user's role permissions
$userPermissions = [];
if ($user['role_id']) {
    $userPerms = $db->getRows(
        "SELECT p.* FROM permissions p 
         INNER JOIN role_permissions rp ON p.id = rp.permission_id 
         WHERE rp.role_id = :role_id 
         ORDER BY p.module, p.permission_name",
        [':role_id' => $user['role_id']]
    );
    if ($userPerms !== false) {
        $userPermissions = $userPerms;
    }
}

// Group permissions by module
$permissionsByModule = [];
foreach ($userPermissions as $permission) {
    $module = $permission['module'] ?? 'Other';
    if (!isset($permissionsByModule[$module])) {
        $permissionsByModule[$module] = [];
    }
    $permissionsByModule[$module][] = $permission;
}
?>

<?php if (!empty($permissionsByModule)): ?>
    <div class="card mt-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">User Permissions (via Role: <?= escapeHtml($user['role_name'] ?? 'N/A') ?>)</h5>
        </div>
        <div class="card-body" style="max-height: 400px; overflow-y: auto;">
            <?php foreach ($permissionsByModule as $module => $modulePermissions): ?>
                <div class="mb-4">
                    <h6 class="text-primary border-bottom pb-2 mb-3">
                        <i class="bi bi-folder"></i> <?= escapeHtml($module) ?>
                        <span class="badge bg-secondary"><?= count($modulePermissions) ?></span>
                    </h6>
                    <div class="row">
                        <?php foreach ($modulePermissions as $permission): ?>
                            <div class="col-md-6 mb-2">
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-check-circle-fill text-success me-2 mt-1"></i>
                                    <div>
                                        <strong><?= escapeHtml($permission['permission_name']) ?></strong>
                                        <?php if ($permission['description']): ?>
                                            <br><small class="text-muted"><?= escapeHtml($permission['description']) ?></small>
                                        <?php endif; ?>
                                        <br><small class="text-muted"><code><?= escapeHtml($permission['permission_key']) ?></code></small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

