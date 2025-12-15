<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.edit');

$pageTitle = 'Edit Product';

$db = Database::getInstance();
$productId = $_GET['id'] ?? 0;
$product = $db->getRow("SELECT * FROM products WHERE id = ?", [$productId]);

if (!$product) {
    redirectTo('index.php');
}

$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
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
        'status' => $_POST['status'] ?? 'Active',
        'updated_by' => $_SESSION['user_id'],
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // Handle image upload
    if (!empty($_FILES['images']['name'][0])) {
        $uploadedImages = [];
        $uploadDir = APP_PATH . '/uploads/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                $fileName = uniqid() . '_' . basename($_FILES['images']['name'][$key]);
                $targetPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($tmpName, $targetPath)) {
                    $uploadedImages[] = BASE_URL . 'uploads/products/' . $fileName;
                }
            }
        }
        
        if (!empty($uploadedImages)) {
            $existingImages = !empty($product['images']) ? json_decode($product['images'], true) : [];
            $data['images'] = json_encode(array_merge($existingImages, $uploadedImages));
        }
    }
    
    if ($db->update('products', $data, ['id' => $productId])) {
        $success = 'Product updated successfully!';
        redirectTo('index.php');
    } else {
        $error = 'Failed to update product.';
    }
}

$productImages = !empty($product['images']) ? json_decode($product['images'], true) : [];

require_once APP_PATH . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">Edit Product</div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= escapeHtml($error) ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Category *</label>
                    <select class="form-control" name="category_id" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $product['category_id'] ? 'selected' : '' ?>><?= escapeHtml($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Branch *</label>
                    <select class="form-control" name="branch_id" required>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $branch['id'] == $product['branch_id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Brand *</label>
                    <input type="text" class="form-control" name="brand" value="<?= escapeHtml($product['brand']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Model *</label>
                    <input type="text" class="form-control" name="model" value="<?= escapeHtml($product['model']) ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Color</label>
                    <input type="text" class="form-control" name="color" value="<?= escapeHtml($product['color'] ?? '') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Storage</label>
                    <input type="text" class="form-control" name="storage" value="<?= escapeHtml($product['storage'] ?? '') ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Cost Price *</label>
                    <input type="number" step="0.01" class="form-control" name="cost_price" value="<?= $product['cost_price'] ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Selling Price *</label>
                    <input type="number" step="0.01" class="form-control" name="selling_price" value="<?= $product['selling_price'] ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Stock</label>
                    <input type="number" class="form-control" name="quantity_in_stock" value="<?= $product['quantity_in_stock'] ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Reorder Level</label>
                    <input type="number" class="form-control" name="reorder_level" value="<?= $product['reorder_level'] ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status">
                        <option value="Active" <?= $product['status'] == 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $product['status'] == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Product Images</label>
                <div class="mb-2">
                    <?php if (!empty($productImages)): ?>
                        <?php foreach ($productImages as $img): ?>
                            <div class="d-inline-block me-2 mb-2 position-relative">
                                <img src="<?= escapeHtml($img) ?>" style="width: 100px; height: 100px; object-fit: cover; border-radius: 8px;" class="border">
                                <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="removeImage(this, '<?= escapeHtml($img) ?>')" style="margin: 2px;">×</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <input type="file" class="form-control" name="images[]" accept="image/*" multiple>
                <small class="text-muted">You can upload multiple images</small>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update Product</button>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<script>
function removeImage(btn, imageUrl) {
    if (confirm('Remove this image?')) {
        fetch('<?= BASE_URL ?>ajax/remove_product_image.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({product_id: <?= $productId ?>, image_url: imageUrl})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.closest('.d-inline-block').remove();
            }
        });
    }
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

