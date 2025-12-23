<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('roles.view');

$pageTitle = 'Roles & Permissions';

$db = Database::getInstance();

$roles = $db->getRows("SELECT r.*, COUNT(DISTINCT rp.permission_id) as permission_count, COUNT(DISTINCT u.id) as user_count 
                       FROM roles r 
                       LEFT JOIN role_permissions rp ON r.id = rp.role_id 
                       LEFT JOIN users u ON r.id = u.role_id AND u.deleted_at IS NULL
                       GROUP BY r.id 
                       ORDER BY r.name");
if ($roles === false) $roles = [];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Roles & Permissions</h2>
    <?php if ($auth->hasPermission('roles.create')): ?>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Role</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Role Name</th>
                    <th>Description</th>
                    <th>Permissions</th>
                    <th>Users</th>
                    <th>System Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($roles)): ?>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td><strong><?= escapeHtml($role['name']) ?></strong></td>
                            <td><?= escapeHtml($role['description'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge bg-info"><?= $role['permission_count'] ?? 0 ?> permissions</span>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= $role['user_count'] ?? 0 ?> users</span>
                            </td>
                            <td>
                                <?php if ($role['is_system_role'] ?? 0): ?>
                                    <span class="badge bg-warning">System</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Custom</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="view.php?id=<?= $role['id'] ?>" class="btn btn-info" title="View"><i class="bi bi-eye"></i></a>
                                    <?php if ($auth->hasPermission('roles.edit')): ?>
                                        <a href="edit.php?id=<?= $role['id'] ?>" class="btn btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                    <?php if ($auth->hasPermission('roles.delete')): ?>
                                        <?php if (!($role['is_system_role'] ?? 0)): ?>
                                            <button onclick="deleteRole(<?= $role['id'] ?>)" class="btn btn-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                        <?php else: ?>
                                            <button onclick="deleteRole(<?= $role['id'] ?>)" class="btn btn-danger" title="Delete" disabled><i class="bi bi-trash"></i></button>
                                            <small class="text-muted d-block">System role</small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTables
    if ($.fn.DataTable.isDataTable('.data-table')) {
        $('.data-table').DataTable().destroy();
    }
    $('.data-table').DataTable({
        pageLength: 25,
        order: [[0, 'asc']],
        language: {
            emptyTable: "No roles found"
        }
    });
});

function deleteRole(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= BASE_URL ?>ajax/delete_role.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ role_id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deleted!', data.message, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error!', 'An error occurred', 'error');
            });
        }
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


