<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('roles.create');

$pageTitle = 'Add Role';

$db = Database::getInstance();

// Get all permissions grouped by module
$permissions = $db->getRows("SELECT * FROM permissions ORDER BY module, permission_name");
if ($permissions === false) $permissions = [];

// Group permissions by module
$permissionsByModule = [];
foreach ($permissions as $permission) {
    $module = $permission['module'] ?? 'Other';
    if (!isset($permissionsByModule[$module])) {
        $permissionsByModule[$module] = [];
    }
    $permissionsByModule[$module][] = $permission;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Add Role</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form id="roleForm">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Role Name *</label>
                    <input type="text" class="form-control" id="roleName" name="name" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" id="roleDescription" name="description">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Permissions *</label>
                <div class="border rounded p-3" style="max-height: 500px; overflow-y: auto;">
                    <?php if (empty($permissionsByModule)): ?>
                        <div class="alert alert-info">No permissions found. Please seed permissions first.</div>
                    <?php else: ?>
                        <?php foreach ($permissionsByModule as $module => $modulePermissions): ?>
                            <div class="mb-4">
                                <h6 class="text-primary border-bottom pb-2 mb-3"><?= escapeHtml($module) ?></h6>
                                <div class="row">
                                    <?php foreach ($modulePermissions as $permission): ?>
                                        <div class="col-md-6 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input permission-checkbox" type="checkbox" 
                                                       name="permissions[]" 
                                                       value="<?= $permission['id'] ?>" 
                                                       id="perm_<?= $permission['id'] ?>">
                                                <label class="form-check-label" for="perm_<?= $permission['id'] ?>">
                                                    <strong><?= escapeHtml($permission['permission_name']) ?></strong>
                                                    <?php if ($permission['description']): ?>
                                                        <br><small class="text-muted"><?= escapeHtml($permission['description']) ?></small>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllPermissions()">Select All</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllPermissions()">Deselect All</button>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Role
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('roleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = {
        name: document.getElementById('roleName').value.trim(),
        description: document.getElementById('roleDescription').value.trim(),
        permissions: Array.from(document.querySelectorAll('.permission-checkbox:checked')).map(cb => parseInt(cb.value))
    };
    
    if (!formData.name) {
        Swal.fire('Error', 'Role name is required', 'error');
        return;
    }
    
    if (formData.permissions.length === 0) {
        Swal.fire('Error', 'Please select at least one permission', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Saving...',
        text: 'Please wait',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/save_role.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', data.message, 'success').then(() => {
                window.location.href = 'index.php';
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to save role', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An error occurred while saving the role', 'error');
    });
});

function selectAllPermissions() {
    document.querySelectorAll('.permission-checkbox').forEach(cb => cb.checked = true);
}

function deselectAllPermissions() {
    document.querySelectorAll('.permission-checkbox').forEach(cb => cb.checked = false);
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

