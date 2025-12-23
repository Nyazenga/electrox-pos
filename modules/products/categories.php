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
$primaryDb = Database::getPrimaryInstance();

// Function to get applicable taxes for all branches (for category tax assignment)
function getAllApplicableTaxes($primaryDb) {
    $configs = $primaryDb->getRows(
        "SELECT DISTINCT applicable_taxes FROM fiscal_config WHERE applicable_taxes IS NOT NULL AND applicable_taxes != ''"
    );
    
    $allTaxes = [];
    $seenTaxIds = [];
    
    foreach ($configs as $config) {
        $taxes = json_decode($config['applicable_taxes'], true);
        if (is_array($taxes)) {
            foreach ($taxes as $tax) {
                $taxId = $tax['taxID'] ?? null;
                if ($taxId && !in_array($taxId, $seenTaxIds)) {
                    $allTaxes[] = $tax;
                    $seenTaxIds[] = $taxId;
                }
            }
        }
    }
    
    return $allTaxes;
}

$allTaxes = getAllApplicableTaxes($primaryDb);
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
                    <td>
                        <span class="badge bg-primary"><?= $category['product_count'] ?></span>
                        <?php if ($category['tax_id']): 
                            $categoryTax = null;
                            foreach ($allTaxes as $tax) {
                                if (($tax['taxID'] ?? null) == $category['tax_id']) {
                                    $categoryTax = $tax;
                                    break;
                                }
                            }
                            if ($categoryTax):
                        ?>
                            <br><small class="text-muted">Tax: <?= escapeHtml($categoryTax['taxName'] ?? 'Tax') ?> (<?= $categoryTax['taxPercent'] ?? 0 ?>%)</small>
                        <?php endif; endif; ?>
                    </td>
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
                    <div class="mb-3">
                        <label class="form-label">Default Tax (Optional)</label>
                        <select class="form-control" id="categoryTaxId" name="tax_id">
                            <option value="">No Default Tax</option>
                            <?php foreach ($allTaxes as $tax): 
                                $taxDisplay = sprintf(
                                    "%s (%.2f%%) - Code: %s",
                                    $tax['taxName'] ?? 'Tax',
                                    $tax['taxPercent'] ?? 0,
                                    $tax['taxCode'] ?? ''
                                );
                            ?>
                                <option value="<?= $tax['taxID'] ?? '' ?>"><?= escapeHtml($taxDisplay) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">This tax will apply to all products in this category unless a product has its own tax assigned.</small>
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
                document.getElementById('categoryTaxId').value = data.category.tax_id || '';
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

