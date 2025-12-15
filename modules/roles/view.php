<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('roles.view');

$pageTitle = 'View Role';

$db = Database::getInstance();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    redirectTo('modules/roles/index.php');
}

// Get role details
$role = $db->getRow("SELECT * FROM roles WHERE id = :id", [':id' => $id]);

if (!$role) {
    redirectTo('modules/roles/index.php');
}

// Get role permissions
$rolePermissions = $db->getRows("SELECT p.* FROM permissions p 
                                 INNER JOIN role_permissions rp ON p.id = rp.permission_id 
                                 WHERE rp.role_id = :role_id 
                                 ORDER BY p.module, p.permission_name", [':role_id' => $id]);
if ($rolePermissions === false) $rolePermissions = [];

// Group permissions by module
$permissionsByModule = [];
foreach ($rolePermissions as $permission) {
    $module = $permission['module'] ?? 'Other';
    if (!isset($permissionsByModule[$module])) {
        $permissionsByModule[$module] = [];
    }
    $permissionsByModule[$module][] = $permission;
}

// Get users with this role
$users = $db->getRows("SELECT id, username, email, first_name, last_name, status 
                       FROM users 
                       WHERE role_id = :role_id AND deleted_at IS NULL 
                       ORDER BY first_name, last_name", [':role_id' => $id]);
if ($users === false) $users = [];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Role Details</h2>
    <div>
        <?php if ($auth->hasPermission('roles.edit')): ?>
            <a href="edit.php?id=<?= $id ?>" class="btn btn-warning">
                <i class="bi bi-pencil"></i> Edit
            </a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Role Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Role Name:</th>
                        <td><strong><?= escapeHtml($role['name']) ?></strong></td>
                    </tr>
                    <tr>
                        <th>Description:</th>
                        <td><?= escapeHtml($role['description'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Type:</th>
                        <td>
                            <?php if ($role['is_system_role'] ?? 0): ?>
                                <span class="badge bg-warning">System Role</span>
                            <?php else: ?>
                                <span class="badge bg-success">Custom Role</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Permissions:</th>
                        <td><span class="badge bg-info"><?= count($rolePermissions) ?> permissions</span></td>
                    </tr>
                    <tr>
                        <th>Users:</th>
                        <td><span class="badge bg-secondary"><?= count($users) ?> users</span></td>
                    </tr>
                    <tr>
                        <th>Created:</th>
                        <td><?= $role['created_at'] ? formatDateTime($role['created_at']) : 'N/A' ?></td>
                    </tr>
                    <?php if ($role['updated_at']): ?>
                        <tr>
                            <th>Last Updated:</th>
                            <td><?= formatDateTime($role['updated_at']) ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Permissions (<?= count($rolePermissions) ?>)</h5>
            </div>
            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                <?php if (empty($permissionsByModule)): ?>
                    <div class="alert alert-warning">No permissions assigned to this role.</div>
                <?php else: ?>
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
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($users)): ?>
    <div class="card mt-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">Users with this Role (<?= count($users) ?>)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= escapeHtml(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'N/A') ?></td>
                                <td><?= escapeHtml($user['username']) ?></td>
                                <td><?= escapeHtml($user['email']) ?></td>
                                <td>
                                    <?php if ($user['status'] === 'active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><?= ucfirst($user['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($auth->hasPermission('users.view')): ?>
                                        <a href="<?= BASE_URL ?>modules/users/view.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-info" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

