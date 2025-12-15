<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.create');

$pageTitle = 'Add Product';

$db = Database::getInstance();
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'product_code' => generateProductCode(),
        'category_id' => $_POST['category_id'] ?? null,
        'brand' => sanitizeInput($_POST['brand'] ?? ''),
        'model' => sanitizeInput($_POST['model'] ?? ''),
        'color' => sanitizeInput($_POST['color'] ?? ''),
        'storage' => sanitizeInput($_POST['storage'] ?? ''),
        'cost_price' => $_POST['cost_price'] ?? 0,
        'selling_price' => $_POST['selling_price'] ?? 0,
        'reorder_level' => $_POST['reorder_level'] ?? 0,
        'branch_id' => $_POST['branch_id'] ?? $_SESSION['branch_id'],
        'quantity_in_stock' => $_POST['quantity_in_stock'] ?? 0,
        'status' => 'Active',
        'created_by' => $_SESSION['user_id'],
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    if ($db->insert('products', $data)) {
        $success = 'Product added successfully!';
        redirectTo('index.php');
    } else {
        $error = 'Failed to add product.';
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">Add New Product</div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= escapeHtml($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Category *</label>
                    <select class="form-control" name="category_id" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= escapeHtml($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Branch *</label>
                    <select class="form-control" name="branch_id" required>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $branch['id'] == $_SESSION['branch_id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Brand *</label>
                    <input type="text" class="form-control" name="brand" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Model *</label>
                    <input type="text" class="form-control" name="model" required>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Color</label>
                    <input type="text" class="form-control" name="color">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Storage</label>
                    <input type="text" class="form-control" name="storage">
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Cost Price *</label>
                    <input type="number" step="0.01" class="form-control" name="cost_price" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Selling Price *</label>
                    <input type="number" step="0.01" class="form-control" name="selling_price" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Initial Stock</label>
                    <input type="number" class="form-control" name="quantity_in_stock" value="0">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Reorder Level</label>
                <input type="number" class="form-control" name="reorder_level" value="10">
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Product</button>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

