<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('invoicing.customize'); // Use customize permission for now

$db = Database::getInstance();
$terms = $db->getRows("SELECT * FROM proforma_terms ORDER BY created_at DESC");
if ($terms === false) $terms = [];

$pageTitle = 'Terms & Conditions';

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-file-text"></i> Terms & Conditions for Proforma Invoices</h2>
    <a href="add.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Add New Terms
    </a>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($terms)): ?>
            <div class="text-center py-5">
                <i class="bi bi-file-text" style="font-size: 48px; color: #ccc;"></i>
                <p class="text-muted mt-3">No terms & conditions found. <a href="add.php">Add your first terms</a></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="20%">Title</th>
                            <th width="50%">Content</th>
                            <th width="10%">Status</th>
                            <th width="15%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($terms as $index => $term): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><strong><?= escapeHtml($term['title']) ?></strong></td>
                                <td>
                                    <div style="max-height: 60px; overflow: hidden; text-overflow: ellipsis;">
                                        <?= escapeHtml(substr($term['content'], 0, 150)) ?><?= strlen($term['content']) > 150 ? '...' : '' ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($term['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="edit.php?id=<?= $term['id'] ?>" class="btn btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteTerm(<?= $term['id'] ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function deleteTerm(id) {
    Swal.fire({
        title: 'Delete Terms?',
        text: 'This will permanently delete this terms & conditions. Are you sure?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc3545'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= BASE_URL ?>ajax/delete_proforma_term.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success', 'Terms & conditions deleted successfully', 'success').then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to delete terms', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred while deleting terms', 'error');
            });
        }
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
