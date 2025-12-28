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

// Get all permissions grouped by module - NO FILTERS, NO LIMITS
// Force fresh query by using a direct query
$permissions = $db->getRows("SELECT id, permission_key, permission_name, module, description FROM permissions ORDER BY module, permission_name");
if ($permissions === false) {
    $permissions = [];
}

// Verify we got all permissions - if less than 100, something is wrong
if (count($permissions) < 100) {
    // Try direct PDO query as fallback
    try {
        $pdo = $db->getConnection();
        $stmt = $pdo->query("SELECT id, permission_key, permission_name, module, description FROM permissions ORDER BY module, permission_name");
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching permissions: " . $e->getMessage());
    }
}

// Define report category mappings
$reportCategoryMappings = [
    'reports.general' => [
        'reports.sales_summary', 'reports.receipts', 'reports.refunds', 
        'reports.sales_by_product', 'reports.sales_by_category', 
        'reports.sales_by_discount', 'reports.sales_by_payment', 
        'reports.taxes', 'reports.shifts'
    ],
    'reports.advanced_sales' => [
        'reports.product_wise_receipt', 'reports.sales_by_trend', 
        'reports.deleted_receipts', 'reports.order_type_wise', 
        'reports.product_wise_tax', 'reports.manual_receipts', 
        'reports.sales_by_order', 'reports.product_wise_order', 
        'reports.sales_by_modifier', 'reports.product_sales_by_staff', 
        'reports.sales_by_staff', 'reports.products_consumed_by_staff', 
        'reports.ecommerce_sales'
    ],
    'reports.suspicious' => [
        'reports.product_wise_deleted', 'reports.refunds_credit_notes', 
        'reports.deleted_products_open_orders'
    ]
];

// Create permission key to ID mapping
$permissionKeyToId = [];
foreach ($permissions as $perm) {
    $permissionKeyToId[$perm['permission_key']] = $perm['id'];
}

// Create category to sub-permission IDs mapping
$categoryToSubPermissionIds = [];
foreach ($reportCategoryMappings as $categoryKey => $subKeys) {
    $categoryId = $permissionKeyToId[$categoryKey] ?? null;
    if ($categoryId) {
        $categoryToSubPermissionIds[$categoryId] = [];
        foreach ($subKeys as $subKey) {
            $subId = $permissionKeyToId[$subKey] ?? null;
            if ($subId) {
                $categoryToSubPermissionIds[$categoryId][] = $subId;
            }
        }
    }
}

// Group permissions by module
$permissionsByModule = [];
foreach ($permissions as $permission) {
    $module = $permission['module'] ?? 'Other';
    if (!isset($permissionsByModule[$module])) {
        $permissionsByModule[$module] = [];
    }
    $permissionsByModule[$module][] = $permission;
}

// Debug: Log module count
error_log("DEBUG: Grouped into " . count($permissionsByModule) . " modules");

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
                <label class="form-label fw-bold">Permissions * <small class="text-muted">(Total: <?= count($permissions) ?> permissions across <?= count($permissionsByModule) ?> modules)</small></label>
                <div class="border rounded p-3" style="max-height: 600px; overflow-y: auto;">
                    <?php if (empty($permissionsByModule)): ?>
                        <div class="alert alert-info">No permissions found. Please seed permissions first.</div>
                    <?php else: ?>
                        <?php foreach ($permissionsByModule as $module => $modulePermissions): ?>
                            <div class="mb-4">
                                <h6 class="text-primary border-bottom pb-2 mb-3">
                                    <?= escapeHtml($module) ?> 
                                    <span class="badge bg-secondary"><?= count($modulePermissions) ?> permission<?= count($modulePermissions) != 1 ? 's' : '' ?></span>
                                </h6>
                                <div class="row">
                                    <?php foreach ($modulePermissions as $permission): ?>
                                        <?php 
                                        $isCategory = isset($categoryToSubPermissionIds[$permission['id']]);
                                        $categoryClass = $isCategory ? 'category-checkbox' : '';
                                        $subPermissionIds = $isCategory ? json_encode($categoryToSubPermissionIds[$permission['id']]) : '[]';
                                        ?>
                                        <div class="col-md-6 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input permission-checkbox <?= $categoryClass ?>" 
                                                       type="checkbox" 
                                                       name="permissions[]" 
                                                       value="<?= $permission['id'] ?>" 
                                                       id="perm_<?= $permission['id'] ?>"
                                                       data-category="<?= $isCategory ? 'true' : 'false' ?>"
                                                       data-sub-permissions="<?= htmlspecialchars($subPermissionIds) ?>">
                                                <label class="form-check-label <?= $isCategory ? 'fw-bold text-primary' : '' ?>" for="perm_<?= $permission['id'] ?>">
                                                    <?php if ($isCategory): ?>
                                                        <i class="bi bi-folder-fill me-1"></i>
                                                    <?php endif; ?>
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

// Category auto-select functionality
document.addEventListener('DOMContentLoaded', function() {
    const categoryCheckboxes = document.querySelectorAll('.category-checkbox');
    const allCheckboxes = document.querySelectorAll('.permission-checkbox');
    
    // When a category checkbox is clicked
    categoryCheckboxes.forEach(categoryCb => {
        categoryCb.addEventListener('change', function() {
            const subPermissionIds = JSON.parse(this.getAttribute('data-sub-permissions') || '[]');
            subPermissionIds.forEach(subId => {
                const subCb = document.getElementById('perm_' + subId);
                if (subCb) {
                    subCb.checked = this.checked;
                }
            });
        });
    });
    
    // When a sub-permission checkbox is clicked, check/uncheck category accordingly
    allCheckboxes.forEach(cb => {
        if (!cb.classList.contains('category-checkbox')) {
            cb.addEventListener('change', function() {
                // Find which category this permission belongs to
                categoryCheckboxes.forEach(categoryCb => {
                    const subPermissionIds = JSON.parse(categoryCb.getAttribute('data-sub-permissions') || '[]');
                    if (subPermissionIds.includes(parseInt(this.value))) {
                        // Check if all sub-permissions are checked
                        const allChecked = subPermissionIds.every(subId => {
                            const subCb = document.getElementById('perm_' + subId);
                            return subCb && subCb.checked;
                        });
                        categoryCb.checked = allChecked;
                    }
                });
            });
        }
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

