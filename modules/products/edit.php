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
$primaryDb = Database::getPrimaryInstance();
$productId = $_GET['id'] ?? 0;
$product = $db->getRow("SELECT * FROM products WHERE id = ?", [$productId]);

if (!$product) {
    redirectTo('index.php');
}

$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");

// Get current product's category
$currentCategory = null;
if ($product['category_id']) {
    $currentCategory = $db->getRow("SELECT * FROM product_categories WHERE id = ?", [$product['category_id']]);
}
$isGeneralCategory = $currentCategory && (strtolower($currentCategory['name']) === 'general' || 
                                           strpos(strtolower($currentCategory['name']), 'grocery') !== false || 
                                           strpos(strtolower($currentCategory['name']), 'food') !== false ||
                                           strpos(strtolower($currentCategory['name']), 'consumable') !== false ||
                                           strpos(strtolower($currentCategory['name']), 'beverage') !== false);

// Function to get applicable taxes for a branch
function getApplicableTaxesForBranch($primaryDb, $branchId) {
    if (!$branchId) {
        return [];
    }
    
    // Get device for branch
    $device = $primaryDb->getRow(
        "SELECT device_id FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1 LIMIT 1",
        [':branch_id' => $branchId]
    );
    
    if (!$device) {
        return [];
    }
    
    // Get fiscal config
    $config = $primaryDb->getRow(
        "SELECT applicable_taxes FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
        [':branch_id' => $branchId, ':device_id' => $device['device_id']]
    );
    
    if (!$config || empty($config['applicable_taxes'])) {
        return [];
    }
    
    $taxes = json_decode($config['applicable_taxes'], true);
    return is_array($taxes) ? $taxes : [];
}

// Get taxes for product's branch
$applicableTaxes = getApplicableTaxesForBranch($primaryDb, $product['branch_id'] ?? null);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if new category is General
    $newCategoryId = $_POST['category_id'] ?? null;
    $newIsGeneralCategory = false;
    if ($newCategoryId) {
        $newCategory = $db->getRow("SELECT * FROM product_categories WHERE id = ?", [$newCategoryId]);
        $newIsGeneralCategory = $newCategory && (strtolower($newCategory['name']) === 'general' || 
                                                 strpos(strtolower($newCategory['name']), 'grocery') !== false || 
                                                 strpos(strtolower($newCategory['name']), 'food') !== false ||
                                                 strpos(strtolower($newCategory['name']), 'consumable') !== false ||
                                                 strpos(strtolower($newCategory['name']), 'beverage') !== false);
    }
    
    $data = [
        'category_id' => $newCategoryId,
        'product_name' => $newIsGeneralCategory ? sanitizeInput($_POST['product_name'] ?? '') : null,
        'brand' => $newIsGeneralCategory ? null : sanitizeInput($_POST['brand'] ?? ''),
        'model' => $newIsGeneralCategory ? null : sanitizeInput($_POST['model'] ?? ''),
        'color' => sanitizeInput($_POST['color'] ?? ''),
        'storage' => sanitizeInput($_POST['storage'] ?? ''),
        'cost_price' => $_POST['cost_price'] ?? 0,
        'selling_price' => $_POST['selling_price'] ?? 0,
        'reorder_level' => $_POST['reorder_level'] ?? 0,
        'branch_id' => $_POST['branch_id'] ?? $_SESSION['branch_id'],
        'tax_id' => !empty($_POST['tax_id']) ? intval($_POST['tax_id']) : null,
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
        $_SESSION['success_message'] = 'Product updated successfully!';
        redirectTo('modules/products/index.php');
    } else {
        $error = 'Failed to update product.';
    }
}

$productImages = !empty($product['images']) ? json_decode($product['images'], true) : [];

// Ensure color is in hex format with # for the color picker
// If existing color is not in hex format, default to #ffffff
$productColor = $product['color'] ?? '#ffffff';
if (!empty($productColor)) {
    // Check if it's already a hex color (starts with # and has 3 or 6 hex digits)
    if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $productColor)) {
        // Already valid hex, use as is
        $productColor = $productColor;
    } elseif (preg_match('/^[A-Fa-f0-9]{6}$/', $productColor)) {
        // Hex without # prefix, add it
        $productColor = '#' . $productColor;
    } else {
        // Text color name (like "Blue", "White"), default to white for picker
        // The actual color value will still be shown in the text input
        $productColor = '#ffffff';
    }
} else {
    $productColor = '#ffffff';
}

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
                    <select class="form-control" name="category_id" id="categoryId" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" 
                                    data-name="<?= escapeHtml(strtolower($cat['name'])) ?>"
                                    <?= $cat['id'] == $product['category_id'] ? 'selected' : '' ?>>
                                <?= escapeHtml($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Branch *</label>
                    <select class="form-control" name="branch_id" id="branchId" required>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $branch['id'] == $product['branch_id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Product Name (for General) or Brand/Model (for others) -->
            <div class="row" id="productNameRow" style="display: <?= $isGeneralCategory ? 'block' : 'none' ?>;">
                <div class="col-md-12 mb-3">
                    <label class="form-label">Product Name *</label>
                    <input type="text" class="form-control" name="product_name" id="productNameInput" 
                           value="<?= escapeHtml($product['product_name'] ?? '') ?>" 
                           <?= $isGeneralCategory ? 'required' : '' ?>
                           placeholder="e.g., Sugar White 2kg">
                </div>
            </div>
            
            <div class="row" id="brandModelRow" style="display: <?= $isGeneralCategory ? 'none' : 'block' ?>;">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Brand *</label>
                    <input type="text" class="form-control" name="brand" id="brandInput" 
                           value="<?= escapeHtml($product['brand'] ?? '') ?>" 
                           <?= $isGeneralCategory ? '' : 'required' ?>>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Model *</label>
                    <input type="text" class="form-control" name="model" id="modelInput" 
                           value="<?= escapeHtml($product['model'] ?? '') ?>" 
                           <?= $isGeneralCategory ? '' : 'required' ?>>
                </div>
            </div>
            <!-- Color Picker -->
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Choose a Color</label>
                    <div class="color-picker-wrapper">
                        <input type="color" class="form-control form-control-color" name="color" id="colorPicker" 
                               value="<?= escapeHtml($productColor) ?>" 
                               style="width: 60px; height: 40px; cursor: pointer;">
                        <div class="color-preview" id="colorPreview" 
                             style="background-color: <?= escapeHtml($productColor) ?>;"></div>
                        <input type="text" class="form-control" id="colorHex" 
                               value="<?= escapeHtml($product['color'] ?? $productColor) ?>" 
                               placeholder="#ffffff" style="max-width: 120px;">
                    </div>
                    <small class="text-muted">Select a color for this product</small>
                </div>
                <div class="col-md-6 mb-3" id="storageField" style="display: none;">
                    <label class="form-label">Storage</label>
                    <input type="text" class="form-control" name="storage" value="<?= escapeHtml($product['storage'] ?? '') ?>" placeholder="e.g., 128GB, 256GB">
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
            
            <!-- Tax Selection -->
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Applicable Tax</label>
                    <select class="form-control" name="tax_id" id="taxId">
                        <option value="">Select Tax (Optional)</option>
                        <?php 
                        foreach ($applicableTaxes as $tax): 
                            $taxDisplay = sprintf(
                                "%s (%.2f%%) - Code: %s",
                                $tax['taxName'] ?? 'Tax',
                                $tax['taxPercent'] ?? 0,
                                $tax['taxCode'] ?? ''
                            );
                            $selected = ($product['tax_id'] ?? null) == ($tax['taxID'] ?? null) ? 'selected' : '';
                        ?>
                            <option value="<?= $tax['taxID'] ?? '' ?>" <?= $selected ?>>
                                <?= escapeHtml($taxDisplay) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Select the tax that applies to this product. This will be used when creating fiscal receipts.</small>
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

// Dynamic fields based on category
function updateDynamicFields(categoryName) {
    if (!categoryName && categoryId.value) {
        const selectedOption = categoryId.options[categoryId.selectedIndex];
        categoryName = selectedOption ? selectedOption.getAttribute('data-name') : '';
    }
    
    if (!categoryName) {
        return;
    }
    
    categoryName = categoryName.toLowerCase();
    const isGeneral = categoryName === 'general' || categoryName.includes('grocery') || categoryName.includes('food') || 
                      categoryName.includes('consumable') || categoryName.includes('beverage');
    
    // Show/hide Product Name vs Brand/Model
    const productNameRow = document.getElementById('productNameRow');
    const brandModelRow = document.getElementById('brandModelRow');
    const productNameInput = document.getElementById('productNameInput');
    const brandInput = document.getElementById('brandInput');
    const modelInput = document.getElementById('modelInput');
    
    if (isGeneral) {
        if (productNameRow) productNameRow.style.display = 'block';
        if (brandModelRow) brandModelRow.style.display = 'none';
        if (productNameInput) productNameInput.required = true;
        if (brandInput) {
            brandInput.required = false;
            brandInput.value = '';
        }
        if (modelInput) {
            modelInput.required = false;
            modelInput.value = '';
        }
    } else {
        if (productNameRow) productNameRow.style.display = 'none';
        if (brandModelRow) brandModelRow.style.display = 'block';
        if (productNameInput) {
            productNameInput.required = false;
            productNameInput.value = '';
        }
        if (brandInput) brandInput.required = true;
        if (modelInput) modelInput.required = true;
    }
    
    // Show/hide storage field for electronics
    const storageField = document.getElementById('storageField');
    if (categoryName.includes('smartphone') || categoryName.includes('phone') || 
        categoryName.includes('laptop') || categoryName.includes('tablet')) {
        if (storageField) storageField.style.display = 'block';
    } else {
        if (storageField) storageField.style.display = 'none';
    }
}

// Category change handler
const categoryId = document.getElementById('categoryId');
if (categoryId) {
    categoryId.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const categoryName = selectedOption ? selectedOption.getAttribute('data-name') : '';
        updateDynamicFields(categoryName);
    });
}

// Load applicable taxes when branch changes
const branchSelect = document.getElementById('branchId');
const taxSelect = document.getElementById('taxId');

if (branchSelect && taxSelect) {
    branchSelect.addEventListener('change', function() {
        const branchId = this.value;
        if (!branchId) {
            taxSelect.innerHTML = '<option value="">Select Tax (Optional)</option>';
            return;
        }
        
        // Show loading
        taxSelect.innerHTML = '<option value="">Loading taxes...</option>';
        taxSelect.disabled = true;
        
        // Fetch taxes for selected branch
        fetch('<?= BASE_URL ?>ajax/get_applicable_taxes.php?branch_id=' + branchId)
            .then(response => response.json())
            .then(data => {
                taxSelect.innerHTML = '<option value="">Select Tax (Optional)</option>';
                
                if (data.success && data.taxes && data.taxes.length > 0) {
                    data.taxes.forEach(tax => {
                        const option = document.createElement('option');
                        option.value = tax.taxID;
                        const currentTaxId = <?= json_encode($product['tax_id'] ?? null) ?>;
                        if (currentTaxId == tax.taxID) {
                            option.selected = true;
                        }
                        option.textContent = `${tax.taxName || 'Tax'} (${tax.taxPercent || 0}%) - Code: ${tax.taxCode || ''}`;
                        taxSelect.appendChild(option);
                    });
                } else {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = 'No taxes available for this branch';
                    taxSelect.appendChild(option);
                }
                
                taxSelect.disabled = false;
            })
            .catch(error => {
                console.error('Error loading taxes:', error);
                taxSelect.innerHTML = '<option value="">Error loading taxes</option>';
                taxSelect.disabled = false;
            });
    });
}

// Color picker functionality
const colorPicker = document.getElementById('colorPicker');
const colorPreview = document.getElementById('colorPreview');
const colorHex = document.getElementById('colorHex');

if (colorPicker && colorPreview && colorHex) {
    // Update preview and hex input when color picker changes
    colorPicker.addEventListener('input', function() {
        const color = this.value;
        colorPreview.style.backgroundColor = color;
        colorHex.value = color;
    });
    
    // Update color picker and preview when hex input changes
    colorHex.addEventListener('input', function() {
        const hex = this.value;
        if (/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/.test(hex)) {
            // Normalize 3-digit hex to 6-digit
            if (hex.length === 4) {
                hex = '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3];
            }
            colorPicker.value = hex;
            colorPreview.style.backgroundColor = hex;
            this.value = hex;
        }
    });
    
    // Update preview when clicking on it
    colorPreview.addEventListener('click', function() {
        colorPicker.click();
    });
    
    // Initialize preview on page load
    const initialPickerColor = colorPicker.value;
    const initialHexValue = colorHex.value;
    
    // If hex input already has a value (might be original color), try to sync it
    if (initialHexValue && /^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/.test(initialHexValue)) {
        // Valid hex color in hex input - normalize and sync to picker
        let normalizedHex = initialHexValue;
        if (initialHexValue.length === 4) {
            normalizedHex = '#' + initialHexValue[1] + initialHexValue[1] + initialHexValue[2] + initialHexValue[2] + initialHexValue[3] + initialHexValue[3];
        }
        colorPicker.value = normalizedHex;
        colorPreview.style.backgroundColor = normalizedHex;
        colorHex.value = normalizedHex;
    } else if (initialHexValue && /^[A-Fa-f0-9]{6}$/.test(initialHexValue)) {
        // Hex without # prefix
        const normalizedHex = '#' + initialHexValue;
        colorPicker.value = normalizedHex;
        colorPreview.style.backgroundColor = normalizedHex;
        colorHex.value = normalizedHex;
    } else {
        // Use picker value for preview (picker already has valid hex)
        // Keep hex input value as-is (might be text color name like "Blue")
        colorPreview.style.backgroundColor = initialPickerColor;
        if (!initialHexValue) {
            colorHex.value = initialPickerColor;
        }
    }
}

// Initialize dynamic fields on page load
document.addEventListener('DOMContentLoaded', function() {
    if (categoryId && categoryId.value) {
        const selectedOption = categoryId.options[categoryId.selectedIndex];
        if (selectedOption) {
            const categoryName = selectedOption.getAttribute('data-name');
            updateDynamicFields(categoryName);
        }
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

