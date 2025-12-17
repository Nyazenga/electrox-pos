<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.view');

$pageTitle = 'Products';

$db = Database::getInstance();
$products = $db->getRows("SELECT p.*, pc.name as category_name FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id ORDER BY p.created_at DESC");

// Get success message from session if exists
$successMessage = '';
if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

require_once APP_PATH . '/includes/header.php';
?>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i> <?= escapeHtml($successMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Products</h2>
    <?php if ($auth->hasPermission('products.create')): ?>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Product</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Product Code</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Brand</th>
                    <th>Model</th>
                    <th>Stock</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): 
                    $productImages = !empty($product['images']) ? json_decode($product['images'], true) : [];
                    $firstImage = !empty($productImages) ? $productImages[0] : null;
                ?>
                    <tr>
                        <td>
                            <div class="product-image-container" style="width: 60px; height: 60px; position: relative; cursor: pointer;" onclick="uploadProductImage(<?= $product['id'] ?>)">
                                <?php if ($firstImage): ?>
                                    <img src="<?= escapeHtml($firstImage) ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;" class="border">
                                <?php elseif (!empty($product['color']) && $product['color'] !== '#ffffff' && $product['color'] !== 'white'): ?>
                                    <div class="d-flex align-items-center justify-content-center border rounded" style="width: 100%; height: 100%; background-color: <?= escapeHtml($product['color']) ?>;">
                                        <i class="bi bi-box" style="font-size: 24px; color: rgba(0,0,0,0.3);"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex align-items-center justify-content-center bg-light border rounded" style="width: 100%; height: 100%;">
                                        <i class="bi bi-box" style="font-size: 24px; color: #999;"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="image-upload-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; border-radius: 4px;">
                                    <i class="bi bi-camera text-white"></i>
                                </div>
                            </div>
                        </td>
                        <td><?= escapeHtml($product['product_code']) ?></td>
                        <td><?= escapeHtml(!empty($product['product_name']) ? $product['product_name'] : ($product['brand'] . ' ' . $product['model'])) ?></td>
                        <td><?= escapeHtml($product['category_name']) ?></td>
                        <td><?= escapeHtml($product['brand'] ?? 'N/A') ?></td>
                        <td><?= escapeHtml($product['model'] ?? 'N/A') ?></td>
                        <td><?= $product['quantity_in_stock'] ?></td>
                        <td><?= formatCurrency($product['selling_price']) ?></td>
                        <td><span class="badge bg-<?= $product['status'] == 'Active' ? 'success' : 'secondary' ?>"><?= escapeHtml($product['status']) ?></span></td>
                        <td>
                            <a href="view.php?id=<?= $product['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                            <?php if ($auth->hasPermission('products.edit')): ?>
                                <a href="edit.php?id=<?= $product['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if ($auth->hasPermission('products.delete')): ?>
                                <a href="delete.php?id=<?= $product['id'] ?>" class="btn btn-sm btn-danger delete-btn"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.product-image-container:hover .image-upload-overlay {
    display: flex !important;
}
</style>

<!-- Image Upload Modal -->
<div class="modal fade" id="imageUploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Upload Product Image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="imageUploadForm" enctype="multipart/form-data">
                    <input type="hidden" id="uploadProductId" name="product_id">
                    <div class="mb-3">
                        <label class="form-label">Select Image</label>
                        <input type="file" class="form-control" name="image" accept="image/*" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitImageUpload()">Upload</button>
            </div>
        </div>
    </div>
</div>

<script>
function uploadProductImage(productId) {
    document.getElementById('uploadProductId').value = productId;
    new bootstrap.Modal(document.getElementById('imageUploadModal')).show();
}

function submitImageUpload() {
    const form = document.getElementById('imageUploadForm');
    const formData = new FormData(form);
    
    fetch('<?= BASE_URL ?>ajax/upload_product_image.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', 'Image uploaded successfully', 'success').then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to upload image', 'error');
        }
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

