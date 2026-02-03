<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('invoicing.customize');

$pageTitle = 'Add Terms & Conditions';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($title) || empty($content)) {
        $_SESSION['error_message'] = 'Title and content are required.';
    } else {
        $db = Database::getInstance();
        $result = $db->insert('proforma_terms', [
            'title' => $title,
            'content' => $content,
            'is_active' => $isActive
        ]);
        
        if ($result) {
            $_SESSION['success_message'] = 'Terms & conditions added successfully.';
            redirectTo('modules/invoicing/terms/index.php');
        } else {
            $_SESSION['error_message'] = 'Failed to add terms & conditions.';
        }
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-plus-circle"></i> Add Terms & Conditions</h2>
    <a href="index.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Back
    </a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Title *</label>
                <input type="text" name="title" class="form-control" required 
                       placeholder="e.g., Standard Payment Terms" 
                       value="<?= escapeHtml($_POST['title'] ?? '') ?>">
            </div>
            
            <div class="mb-3">
                <label class="form-label">Content *</label>
                <textarea name="content" class="form-control" rows="10" required 
                          placeholder="Enter the terms & conditions text..."><?= escapeHtml($_POST['content'] ?? '') ?></textarea>
                <small class="text-muted">This content will be displayed on proforma invoices when selected.</small>
            </div>
            
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" id="isActive" 
                           <?= (!isset($_POST['is_active']) || $_POST['is_active']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="isActive">
                        Active (available for selection)
                    </label>
                </div>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Terms
                </button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
