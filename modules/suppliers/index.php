<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('suppliers.view');

$pageTitle = 'Suppliers';

$db = Database::getInstance();

$suppliers = $db->getRows("SELECT * FROM suppliers ORDER BY name");
if ($suppliers === false) $suppliers = [];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Suppliers</h2>
    <?php if ($auth->hasPermission('suppliers.create')): ?>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Supplier</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="7" class="text-center">No suppliers found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $supplier): ?>
                        <tr>
                            <td><?= escapeHtml($supplier['supplier_code'] ?? 'N/A') ?></td>
                            <td><?= escapeHtml($supplier['name']) ?></td>
                            <td><?= escapeHtml($supplier['contact_person'] ?? 'N/A') ?></td>
                            <td><?= escapeHtml($supplier['phone'] ?? 'N/A') ?></td>
                            <td><?= escapeHtml($supplier['email'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge bg-<?= ($supplier['status'] ?? 'Active') == 'Active' ? 'success' : 'secondary' ?>">
                                    <?= escapeHtml($supplier['status'] ?? 'Active') ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if ($auth->hasPermission('suppliers.view')): ?>
                                        <a href="view.php?id=<?= $supplier['id'] ?>" class="btn btn-info" title="View"><i class="bi bi-eye"></i></a>
                                    <?php endif; ?>
                                    <?php if ($auth->hasPermission('suppliers.edit')): ?>
                                        <a href="edit.php?id=<?= $supplier['id'] ?>" class="btn btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <?php endif; ?>
                                    <?php if ($auth->hasPermission('suppliers.delete')): ?>
                                        <button onclick="deleteSupplier(<?= $supplier['id'] ?>)" class="btn btn-danger" title="Delete"><i class="bi bi-trash"></i></button>
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
function deleteSupplier(id) {
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
            fetch('<?= BASE_URL ?>ajax/delete_supplier.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ supplier_id: id })
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


