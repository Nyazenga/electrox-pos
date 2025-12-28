<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.edit');

$pageTitle = 'Payment Terms';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = sanitizeInput($_POST['name'] ?? '');
        $description = sanitizeInput($_POST['description'] ?? '');
        $days = intval($_POST['days'] ?? 0);
        
        if (empty($name)) {
            $error = 'Payment term name is required';
        } else {
            $data = [
                'name' => $name,
                'description' => $description,
                'days' => $days,
                'is_active' => 1
            ];
            
            if ($primaryDb->insert('payment_terms', $data)) {
                $success = 'Payment term created successfully!';
            } else {
                $error = 'Failed to create payment term: ' . $primaryDb->getLastError();
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = sanitizeInput($_POST['name'] ?? '');
        $description = sanitizeInput($_POST['description'] ?? '');
        $days = intval($_POST['days'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($name)) {
            $error = 'Payment term name is required';
        } else {
            $data = [
                'name' => $name,
                'description' => $description,
                'days' => $days,
                'is_active' => $isActive
            ];
            
            if ($primaryDb->update('payment_terms', $data, ['id' => $id])) {
                $success = 'Payment term updated successfully!';
            } else {
                $error = 'Failed to update payment term: ' . $primaryDb->getLastError();
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        
        // Check if payment term is being used
        $inUse = $db->getRow("SELECT COUNT(*) as count FROM sales WHERE payment_term_id = :id", [':id' => $id]);
        if ($inUse && $inUse['count'] > 0) {
            $error = 'Cannot delete payment term that is in use by sales';
        } else {
            if ($primaryDb->delete('payment_terms', ['id' => $id])) {
                $success = 'Payment term deleted successfully!';
            } else {
                $error = 'Failed to delete payment term: ' . $primaryDb->getLastError();
            }
        }
    }
}

// Get all payment terms
$paymentTerms = $primaryDb->getRows("SELECT * FROM payment_terms ORDER BY days ASC, name ASC");
if ($paymentTerms === false) {
    $paymentTerms = [];
}

// Get payment term for editing
$editTerm = null;
$editId = $_GET['edit'] ?? null;
if ($editId) {
    $editTerm = $primaryDb->getRow("SELECT * FROM payment_terms WHERE id = :id", [':id' => $editId]);
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Payment Terms</h2>
    <div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#paymentTermModal" onclick="resetForm()">
            <i class="bi bi-plus-circle"></i> Add Payment Term
        </button>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i> <?= escapeHtml($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle"></i> <?= escapeHtml($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Payment Terms List</h5>
    </div>
    <div class="card-body">
        <?php if (empty($paymentTerms)): ?>
            <p class="text-muted mb-0">No payment terms found. Click "Add Payment Term" to create one.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="paymentTermsTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Days</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentTerms as $term): ?>
                            <tr>
                                <td><strong><?= escapeHtml($term['name']) ?></strong></td>
                                <td><?= escapeHtml($term['description'] ?? 'N/A') ?></td>
                                <td><?= $term['days'] ?> days</td>
                                <td>
                                    <span class="badge bg-<?= $term['is_active'] ? 'success' : 'secondary' ?>">
                                        <?= $term['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td><?= date('Y-m-d', strtotime($term['created_at'])) ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-warning" onclick="editTerm(<?= $term['id'] ?>, <?= htmlspecialchars(json_encode($term), ENT_QUOTES, 'UTF-8') ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-danger" onclick="deleteTerm(<?= $term['id'] ?>, '<?= escapeHtml($term['name']) ?>')">
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

<!-- Payment Term Modal -->
<div class="modal fade" id="paymentTermModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Payment Term</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="paymentTermForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="termId" value="">
                    
                    <div class="mb-3">
                        <label class="form-label">Name *</label>
                        <input type="text" class="form-control" name="name" id="termName" required 
                               placeholder="e.g., Pay in 7 days, Net 30, etc.">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="termDescription" rows="3" 
                                  placeholder="Optional description"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Days *</label>
                        <input type="number" class="form-control" name="days" id="termDays" required 
                               min="0" value="0" placeholder="Number of days">
                        <small class="text-muted">Number of days until payment is due</small>
                    </div>
                    
                    <div class="form-check" id="activeCheckboxGroup" style="display: none;">
                        <input class="form-check-input" type="checkbox" name="is_active" id="termIsActive" checked>
                        <label class="form-check-label" for="termIsActive">
                            Active
                        </label>
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

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteId" value="">
                    <p>Are you sure you want to delete the payment term "<strong id="deleteName"></strong>"?</p>
                    <p class="text-danger"><small>This action cannot be undone. If the payment term is in use, it cannot be deleted.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('formAction').value = 'create';
    document.getElementById('termId').value = '';
    document.getElementById('termName').value = '';
    document.getElementById('termDescription').value = '';
    document.getElementById('termDays').value = '0';
    document.getElementById('termIsActive').checked = true;
    document.getElementById('modalTitle').textContent = 'Add Payment Term';
    document.getElementById('activeCheckboxGroup').style.display = 'none';
}

function editTerm(id, term) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('termId').value = id;
    document.getElementById('termName').value = term.name;
    document.getElementById('termDescription').value = term.description || '';
    document.getElementById('termDays').value = term.days;
    document.getElementById('termIsActive').checked = term.is_active == 1;
    document.getElementById('modalTitle').textContent = 'Edit Payment Term';
    document.getElementById('activeCheckboxGroup').style.display = 'block';
    
    const modal = new bootstrap.Modal(document.getElementById('paymentTermModal'));
    modal.show();
}

function deleteTerm(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

$(document).ready(function() {
    if ($.fn.DataTable && $('#paymentTermsTable').length) {
        $('#paymentTermsTable').DataTable({
            order: [[2, 'asc']],
            pageLength: 25,
            responsive: true
        });
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

