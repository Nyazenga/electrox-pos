<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.view');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    redirectTo('modules/products/index.php');
}

$db = Database::getInstance();
$product = $db->getRow("SELECT p.*, pc.name as category_name, b.branch_name FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id LEFT JOIN branches b ON p.branch_id = b.id WHERE p.id = :id", [':id' => $id]);

if (!$product) {
    redirectTo('modules/products/index.php');
}

$productDisplayName = !empty($product['product_name']) ? $product['product_name'] : ($product['brand'] . ' ' . $product['model']);
$pageTitle = 'View Product - ' . escapeHtml($productDisplayName);

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Product Details</h2>
    <div>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        <?php if ($auth->hasPermission('products.edit')): ?>
            <a href="edit.php?id=<?= $product['id'] ?>" class="btn btn-warning"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <?php 
                $images = !empty($product['images']) ? json_decode($product['images'], true) : [];
                if (!empty($images)): 
                ?>
                    <div class="product-image-container mb-3" style="position: relative; display: inline-block; cursor: pointer;" onclick="uploadProductImage(<?= $product['id'] ?>)">
                        <img src="<?= escapeHtml($images[0]) ?>" alt="<?= escapeHtml($product['brand'] . ' ' . $product['model']) ?>" class="img-fluid" style="max-height: 300px; border-radius: 8px;">
                        <div class="image-upload-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; border-radius: 8px;">
                            <i class="bi bi-camera text-white" style="font-size: 32px;"></i>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="product-image-container mb-3" style="position: relative; display: inline-block; cursor: pointer;" onclick="uploadProductImage(<?= $product['id'] ?>)">
                        <?php if (!empty($product['color']) && $product['color'] !== '#ffffff' && $product['color'] !== 'white'): ?>
                            <div class="p-5" style="border-radius: 8px; background-color: <?= escapeHtml($product['color']) ?>; min-height: 200px; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-box-seam" style="font-size: 64px; color: rgba(0,0,0,0.3);"></i>
                            </div>
                        <?php else: ?>
                            <div class="bg-light p-5" style="border-radius: 8px;">
                                <i class="bi bi-box-seam" style="font-size: 64px; color: #9ca3af;"></i>
                            </div>
                        <?php endif; ?>
                        <div class="image-upload-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; border-radius: 8px;">
                            <i class="bi bi-camera text-white" style="font-size: 32px;"></i>
                        </div>
                    </div>
                <?php endif; ?>
                <h4><?= escapeHtml(!empty($product['product_name']) ? $product['product_name'] : ($product['brand'] . ' ' . $product['model'])) ?></h4>
                <p class="text-muted"><?= escapeHtml($product['product_code']) ?></p>
                <?php if (!empty($product['color']) && $product['color'] !== '#ffffff' && $product['color'] !== 'white'): ?>
                    <div class="mt-2">
                        <span class="d-inline-flex align-items-center gap-2">
                            <span class="badge" style="background-color: <?= escapeHtml($product['color']) ?>; width: 40px; height: 40px; border: 2px solid #ddd; border-radius: 4px; display: inline-block;"></span>
                            <span>Color</span>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Product Information</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($product['product_name'])): ?>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <strong>Product Name:</strong> <?= escapeHtml($product['product_name']) ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Brand:</strong> <?= escapeHtml($product['brand'] ?? 'N/A') ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Model:</strong> <?= escapeHtml($product['model'] ?? 'N/A') ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Category:</strong> <?= escapeHtml($product['category_name'] ?? 'N/A') ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Branch:</strong> <?= escapeHtml($product['branch_name'] ?? 'N/A') ?>
                    </div>
                </div>
                <?php if (!empty($product['color'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Color:</strong> 
                        <span class="d-inline-flex align-items-center gap-2">
                            <span class="badge" style="background-color: <?= escapeHtml($product['color']) ?>; width: 40px; height: 40px; border: 2px solid #ddd; border-radius: 4px; display: inline-block;"></span>
                        </span>
                    </div>
                    <?php if (!empty($product['storage'])): ?>
                    <div class="col-md-6">
                        <strong>Storage:</strong> <?= escapeHtml($product['storage']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php elseif (!empty($product['storage'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Storage:</strong> <?= escapeHtml($product['storage']) ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($product['condition'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Condition:</strong> 
                        <span class="badge bg-<?= $product['condition'] == 'New' ? 'success' : ($product['condition'] == 'Refurbished' ? 'info' : 'warning') ?>">
                            <?= escapeHtml($product['condition']) ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($product['serial_number'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Serial Number:</strong> <?= escapeHtml($product['serial_number']) ?>
                    </div>
                    <?php if (!empty($product['imei'])): ?>
                    <div class="col-md-6">
                        <strong>IMEI:</strong> <?= escapeHtml($product['imei']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php elseif (!empty($product['imei'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>IMEI:</strong> <?= escapeHtml($product['imei']) ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($product['sim_configuration'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>SIM Configuration:</strong> <?= escapeHtml($product['sim_configuration']) ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($product['sku'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>SKU:</strong> <?= escapeHtml($product['sku']) ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Cost Price:</strong> <?= formatCurrency($product['cost_price']) ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Selling Price:</strong> <?= formatCurrency($product['selling_price']) ?>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Stock Quantity:</strong> <?= $product['quantity_in_stock'] ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Reorder Level:</strong> <?= $product['reorder_level'] ?>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Status:</strong> 
                        <span class="badge bg-<?= $product['status'] == 'Active' ? 'success' : 'secondary' ?>">
                            <?= escapeHtml($product['status']) ?>
                        </span>
                    </div>
                    <div class="col-md-6">
                        <strong>Barcode:</strong> <?= escapeHtml($product['barcode'] ?? 'N/A') ?>
                    </div>
                </div>
                <?php if ($product['description']): ?>
                    <div class="mb-3">
                        <strong>Description:</strong>
                        <p><?= escapeHtml($product['description']) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($product['specifications']): ?>
                    <div class="mb-3">
                        <strong>Specifications:</strong>
                        <pre class="bg-light p-3 rounded"><?= escapeHtml($product['specifications']) ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>
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
                        <small class="text-muted">You can upload multiple images by selecting multiple files</small>
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
    
    Swal.fire({
        title: 'Uploading...',
        text: 'Please wait while we upload the image',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
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
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An unexpected error occurred', 'error');
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

