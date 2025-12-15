<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.view');

$pageTitle = 'Product Categories';

$db = Database::getInstance();
$categories = $db->getRows("SELECT pc.*, COUNT(p.id) as product_count FROM product_categories pc LEFT JOIN products p ON pc.id = p.category_id GROUP BY pc.id ORDER BY pc.name");

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Product Categories</h2>
    <?php if ($auth->hasPermission('products.create')): ?>
        <button class="btn btn-primary" onclick="showAddCategory()"><i class="bi bi-plus-circle"></i> Add Category</button>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Products</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td><?= escapeHtml($category['name']) ?></td>
                        <td><?= escapeHtml($category['description'] ?? 'N/A') ?></td>
                        <td><span class="badge bg-primary"><?= $category['product_count'] ?></span></td>
                        <td><?= formatDate($category['created_at']) ?></td>
                        <td>
                            <?php if ($auth->hasPermission('products.edit')): ?>
                                <button class="btn btn-sm btn-warning" onclick="editCategory(<?= $category['id'] ?>)"><i class="bi bi-pencil"></i></button>
                            <?php endif; ?>
                            <?php if ($auth->hasPermission('products.delete') && $category['product_count'] == 0): ?>
                                <button class="btn btn-sm btn-danger" onclick="deleteCategory(<?= $category['id'] ?>)"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryModalTitle">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="categoryForm">
                <div class="modal-body">
                    <input type="hidden" id="categoryId" name="id">
                    <div class="mb-3">
                        <label class="form-label">Category Name</label>
                        <input type="text" class="form-control" id="categoryName" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="categoryDescription" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAddCategory() {
    document.getElementById('categoryModalTitle').textContent = 'Add Category';
    document.getElementById('categoryForm').reset();
    document.getElementById('categoryId').value = '';
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

function editCategory(id) {
    fetch('<?= BASE_URL ?>ajax/get_category.php?id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('categoryModalTitle').textContent = 'Edit Category';
                document.getElementById('categoryId').value = data.category.id;
                document.getElementById('categoryName').value = data.category.name;
                document.getElementById('categoryDescription').value = data.category.description || '';
                new bootstrap.Modal(document.getElementById('categoryModal')).show();
            }
        });
}

function deleteCategory(id) {
    Swal.fire({
        title: 'Delete Category?',
        text: 'This action cannot be undone',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= BASE_URL ?>ajax/delete_category.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success', 'Category deleted', 'success').then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

document.getElementById('categoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    fetch('<?= BASE_URL ?>ajax/save_category.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            Swal.fire('Success', 'Category saved', 'success').then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire('Error', result.message, 'error');
        }
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

