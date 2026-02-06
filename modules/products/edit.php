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
$product = $db->getRow(
    "SELECT p.*, pc.name as category_name, pc.tax_id as category_tax_id, b.branch_name 
     FROM products p 
     LEFT JOIN product_categories pc ON p.category_id = pc.id 
     LEFT JOIN branches b ON p.branch_id = b.id 
     WHERE p.id = :id", 
    [':id' => $productId]
);

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
    if (!is_array($taxes)) {
        return [];
    }
    
    // Filter out 5% Non-VAT Withholding Tax - it should NOT be fiscalized
    require_once APP_PATH . '/includes/fiscal_helper.php';
    return filterOut5PercentTax($taxes);
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
    
    // Check if this product requires product_specific_list (smartphones, laptops, tablets, gaming)
    $requiresSpecificList = false;
    if ($newCategoryId) {
        $category = $db->getRow("SELECT name FROM product_categories WHERE id = :id", [':id' => $newCategoryId]);
        if ($category) {
            $categoryName = strtolower($category['name']);
            $requiresSpecificList = (strpos($categoryName, 'smartphone') !== false || 
                              strpos($categoryName, 'phone') !== false || 
                              strpos($categoryName, 'laptop') !== false ||
                                   strpos($categoryName, 'tablet') !== false ||
                                   strpos($categoryName, 'gaming') !== false);
        }
    }
    
    // Products requiring specific list can have normal quantity
    // Individual instances are tracked in product_specific_list
    $quantityInStock = intval($_POST['quantity_in_stock'] ?? $product['quantity_in_stock'] ?? 0);
    $reorderLevel = intval($_POST['reorder_level'] ?? $product['reorder_level'] ?? 0);
    
    $data = [
        'category_id' => $newCategoryId,
        'product_name' => $newIsGeneralCategory ? sanitizeInput($_POST['product_name'] ?? '') : null,
        'brand' => $newIsGeneralCategory ? null : sanitizeInput($_POST['brand'] ?? ''),
        'model' => $newIsGeneralCategory ? null : sanitizeInput($_POST['model'] ?? ''),
        // Note: color, storage, sim_configuration, serial_number, imei, battery_health, 
        // manufacturer, warranty_months, warranty_terms, condition, trade_in_eligible 
        // are no longer stored at product level - they go in product_specific_list
        'expiry_date' => !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
        'weight' => !empty($_POST['weight']) ? floatval($_POST['weight']) : null,
        'unit_of_measure' => sanitizeInput($_POST['unit_of_measure'] ?? ''),
        'batch_number' => sanitizeInput($_POST['batch_number'] ?? ''),
        'cost_price' => $_POST['cost_price'] ?? 0,
        'selling_price' => $_POST['selling_price'] ?? 0,
        'wholesale_price' => !empty($_POST['wholesale_price']) ? floatval($_POST['wholesale_price']) : null,
        'reorder_level' => $reorderLevel,
        'branch_id' => $_POST['branch_id'] ?? $_SESSION['branch_id'],
        'tax_id' => !empty($_POST['tax_id']) ? intval($_POST['tax_id']) : null,
        'quantity_in_stock' => $quantityInStock,
        'requires_specific_list' => $requiresSpecificList ? 1 : 0,
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
    
    // Track price changes before updating
    $oldCostPrice = floatval($product['cost_price'] ?? 0);
    $oldSellingPrice = floatval($product['selling_price'] ?? 0);
    $newCostPrice = floatval($data['cost_price'] ?? 0);
    $newSellingPrice = floatval($data['selling_price'] ?? 0);
    $branchId = intval($data['branch_id'] ?? $product['branch_id'] ?? 0);
    $userId = $_SESSION['user_id'] ?? 0;
    
    if ($db->update('products', $data, ['id' => $productId])) {
        // Track cost price change
        if ($oldCostPrice != $newCostPrice) {
            $primaryDb->insert('price_change_history', [
                'product_id' => $productId,
                'branch_id' => $branchId,
                'old_price' => $oldCostPrice,
                'new_price' => $newCostPrice,
                'price_type' => 'cost_price',
                'changed_by' => $userId,
                'changed_at' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Track selling price change
        if ($oldSellingPrice != $newSellingPrice) {
            $primaryDb->insert('price_change_history', [
                'product_id' => $productId,
                'branch_id' => $branchId,
                'old_price' => $oldSellingPrice,
                'new_price' => $newSellingPrice,
                'price_type' => 'selling_price',
                'changed_by' => $userId,
                'changed_at' => date('Y-m-d H:i:s')
            ]);
        }
        
        $_SESSION['success_message'] = 'Product updated successfully!';
        redirectTo('modules/products/index.php');
    } else {
        $error = 'Failed to update product.';
    }
}

$productImages = !empty($product['images']) ? json_decode($product['images'], true) : [];

// Determine category-specific field visibility
$categoryName = strtolower($product['category_name'] ?? '');
$isSmartphoneOrTablet = strpos($categoryName, 'smartphone') !== false || 
                        strpos($categoryName, 'phone') !== false || 
                        strpos($categoryName, 'tablet') !== false;

// Fields to show/hide based on category
$showIMEI = $isSmartphoneOrTablet;
$showSIMConfig = $isSmartphoneOrTablet; // Only for phones/tablets
$showBatteryHealth = $isSmartphoneOrTablet; // Only for phones/tablets

// Color picker code removed - color is now captured in product_specific_list

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Product</h2>
    <div>
        <a href="view.php?id=<?= $product['id'] ?>" class="btn btn-secondary"><i class="bi bi-eye"></i> View</a>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        <?php 
        $requiresSpecificList = !empty($product['requires_specific_list']);
        if ($requiresSpecificList && $auth->hasPermission('products.edit')): 
        ?>
            <button type="button" class="btn btn-primary" onclick="openManageSpecificItemsModal()">
                <i class="bi bi-list-ul"></i> Manage Specific Items
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">Product Information</div>
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
            <!-- Color Picker - REMOVED: Color is now captured in product_specific_list during GRN/Stock Take -->
            <!-- For products requiring specific list (smartphones, laptops, tablets, gaming), 
                 color is captured per individual instance, not at product level -->
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
                    <label class="form-label">Wholesale Price</label>
                    <input type="number" step="0.01" class="form-control" name="wholesale_price" value="<?= $product['wholesale_price'] ?? '' ?>" placeholder="Optional">
                    <small class="text-muted">Price for dealer/wholesale sales</small>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Stock</label>
                    <?php 
                    // Check if product requires specific list
                    $requiresSpecificList = productRequiresSpecificList($product, $db);
                    ?>
                    <input type="number" class="form-control" name="quantity_in_stock" id="quantityInStock" 
                           value="<?= $product['quantity_in_stock'] ?>" min="0">
                    <?php if ($requiresSpecificList): ?>
                        <small class="text-muted">Quantity should match the number of available product_specific_list entries. Individual instances are managed via GRN or Stock Take.</small>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Grocery/General Fields -->
            <div class="row">
                <div class="col-md-3 mb-3" id="expiryDateField" style="display: <?= $isGeneralCategory ? 'block' : 'none' ?>;">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" class="form-control" name="expiry_date" value="<?= $product['expiry_date'] ?? '' ?>">
                </div>
                <div class="col-md-3 mb-3" id="weightField" style="display: <?= $isGeneralCategory ? 'block' : 'none' ?>;">
                    <label class="form-label">Weight</label>
                    <input type="number" step="0.001" class="form-control" name="weight" value="<?= $product['weight'] ?? '' ?>" placeholder="e.g., 0.5">
                </div>
                <div class="col-md-3 mb-3" id="unitOfMeasureField" style="display: <?= $isGeneralCategory ? 'block' : 'none' ?>;">
                    <label class="form-label">Unit of Measure</label>
                    <select class="form-control" name="unit_of_measure">
                        <option value="">Select</option>
                        <option value="kg" <?= ($product['unit_of_measure'] ?? '') == 'kg' ? 'selected' : '' ?>>Kilogram (kg)</option>
                        <option value="g" <?= ($product['unit_of_measure'] ?? '') == 'g' ? 'selected' : '' ?>>Gram (g)</option>
                        <option value="L" <?= ($product['unit_of_measure'] ?? '') == 'L' ? 'selected' : '' ?>>Liter (L)</option>
                        <option value="mL" <?= ($product['unit_of_measure'] ?? '') == 'mL' ? 'selected' : '' ?>>Milliliter (mL)</option>
                        <option value="piece" <?= ($product['unit_of_measure'] ?? '') == 'piece' ? 'selected' : '' ?>>Piece</option>
                        <option value="pack" <?= ($product['unit_of_measure'] ?? '') == 'pack' ? 'selected' : '' ?>>Pack</option>
                        <option value="box" <?= ($product['unit_of_measure'] ?? '') == 'box' ? 'selected' : '' ?>>Box</option>
                        <option value="bottle" <?= ($product['unit_of_measure'] ?? '') == 'bottle' ? 'selected' : '' ?>>Bottle</option>
                        <option value="can" <?= ($product['unit_of_measure'] ?? '') == 'can' ? 'selected' : '' ?>>Can</option>
                        <option value="bag" <?= ($product['unit_of_measure'] ?? '') == 'bag' ? 'selected' : '' ?>>Bag</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3" id="manufacturerField" style="display: <?= $isGeneralCategory ? 'block' : 'none' ?>;">
                    <label class="form-label">Manufacturer</label>
                    <input type="text" class="form-control" name="manufacturer" value="<?= escapeHtml($product['manufacturer'] ?? '') ?>">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-3" id="batchNumberField" style="display: <?= $isGeneralCategory ? 'block' : 'none' ?>;">
                    <label class="form-label">Batch Number</label>
                    <input type="text" class="form-control" name="batch_number" value="<?= escapeHtml($product['batch_number'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Reorder Level</label>
                    <input type="number" class="form-control" name="reorder_level" id="reorderLevel" 
                           value="<?= $product['reorder_level'] ?>" min="0">
                </div>
                <div class="col-md-3 mb-3">
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
    
    // Get all dynamic fields
    const storageField = document.getElementById('storageField');
    const batteryHealthField = document.getElementById('batteryHealthField');
    const serialNumberField = document.getElementById('serialNumberField');
    const imeiField = document.getElementById('imeiField');
    const simConfigField = document.getElementById('simConfigField');
    const expiryDateField = document.getElementById('expiryDateField');
    const weightField = document.getElementById('weightField');
    const unitOfMeasureField = document.getElementById('unitOfMeasureField');
    const manufacturerField = document.getElementById('manufacturerField');
    const batchNumberField = document.getElementById('batchNumberField');
    
    // Hide all fields first
    [storageField, batteryHealthField, serialNumberField, imeiField, simConfigField,
     expiryDateField, weightField, unitOfMeasureField, manufacturerField, batchNumberField].forEach(field => {
        if (field) field.style.display = 'none';
    });
    
    // Check if this product requires specific list (smartphone, laptop, tablet, gaming)
    const requiresSpecificList = categoryName.includes('smartphone') || 
                            categoryName.includes('phone') || 
                            categoryName.includes('laptop') || 
                                 categoryName.includes('tablet') ||
                                 categoryName.includes('gaming');
    
    // Products requiring specific list can have normal quantity
    // Individual instances are tracked in product_specific_list
    const quantityInput = document.getElementById('quantityInStock');
    if (quantityInput) {
            quantityInput.readOnly = false;
            quantityInput.style.backgroundColor = '';
            quantityInput.style.cursor = '';
            quantityInput.removeAttribute('max');
            // Update help text if exists
        const helpText = quantityInput.nextElementSibling;
            if (helpText && helpText.tagName === 'SMALL') {
            if (requiresSpecificList) {
                helpText.textContent = 'Quantity should match the number of available product_specific_list entries. Individual instances are managed via GRN or Stock Take.';
        } else {
                helpText.textContent = '';
            }
            helpText.className = 'text-muted';
        }
    }
    
    // Show fields based on category (only for non-specific-list products)
    if (isGeneral) {
        // General/Grocery: Show grocery-specific fields
        if (expiryDateField) expiryDateField.style.display = 'block';
        if (weightField) weightField.style.display = 'block';
        if (unitOfMeasureField) unitOfMeasureField.style.display = 'block';
        if (batchNumberField) batchNumberField.style.display = 'block';
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

// Color picker functionality - REMOVED
// Color is now captured in product_specific_list during GRN/Stock Take, not at product level

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

<!-- Manage Specific Items Modal -->
<?php if ($requiresSpecificList): ?>
<?php
// Get product_specific_list entries
$specificListEntries = [];
if ($requiresSpecificList) {
    $specificListEntries = $db->getRows(
        "SELECT * FROM product_specific_list WHERE product_id = :product_id ORDER BY created_at DESC",
        [':product_id' => $product['id']]
    );
    if ($specificListEntries === false) {
        $specificListEntries = [];
    }
}
$productDisplayName = !empty($product['product_name']) ? $product['product_name'] : ($product['brand'] . ' ' . $product['model']);
?>
<div class="modal fade" id="manageSpecificItemsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manage Specific Items - <?= escapeHtml($productDisplayName) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-success" onclick="addSpecificItemRow()">
                        <i class="bi bi-plus-circle"></i> Add Item
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addMultipleItems()">
                        <i class="bi bi-plus-circle-fill"></i> Add Multiple Items
                    </button>
                </div>
                <div id="specificItemsContainer" class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Color</th>
                                <th>Storage</th>
                                <th class="sim-config-header">SIM Config</th>
                                <th>Serial # *</th>
                                <th class="imei-header">IMEI</th>
                                <th>Condition</th>
                                <th class="battery-header">Battery %</th>
                                <th>Manufacturer</th>
                                <th>Warranty (Months)</th>
                                <th>Cost Price</th>
                                <th>Selling Price *</th>
                                <th>Wholesale Price</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="specificItemsTableBody">
                            <!-- Items will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveSpecificItems()">
                    <i class="bi bi-save"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let specificItemsData = [];
const categoryName = '<?= strtolower($product['category_name'] ?? '') ?>';
const showIMEI = categoryName.includes('smartphone') || categoryName.includes('phone') || categoryName.includes('tablet');
const showSIMConfig = showIMEI; // Only for phones/tablets
const showBatteryHealth = showIMEI; // Only for phones/tablets

// Hide columns if not needed
document.addEventListener('DOMContentLoaded', function() {
    if (!showIMEI) {
        const imeiHeaders = document.querySelectorAll('.imei-header');
        imeiHeaders.forEach(h => h.style.display = 'none');
    }
    if (!showSIMConfig) {
        const simHeaders = document.querySelectorAll('.sim-config-header');
        simHeaders.forEach(h => h.style.display = 'none');
    }
    if (!showBatteryHealth) {
        const batteryHeaders = document.querySelectorAll('.battery-header');
        batteryHeaders.forEach(h => h.style.display = 'none');
    }
});

function openManageSpecificItemsModal() {
    loadSpecificItems();
    new bootstrap.Modal(document.getElementById('manageSpecificItemsModal')).show();
}

function loadSpecificItems() {
    fetch('<?= BASE_URL ?>ajax/get_product_specific_list.php?product_id=<?= $product['id'] ?>&branch_id=<?= $product['branch_id'] ?? $_SESSION['branch_id'] ?? '' ?>&show_all=1')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                specificItemsData = data.entries || [];
                renderSpecificItemsTable();
            } else {
                Swal.fire('Error', data.message || 'Failed to load items', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load items', 'error');
        });
}

function renderSpecificItemsTable() {
    const tbody = document.getElementById('specificItemsTableBody');
    tbody.innerHTML = '';
    
    if (specificItemsData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="14" class="text-center text-muted">No items added yet. Click "Add Item" to add one.</td></tr>';
        return;
    }
    
    specificItemsData.forEach((item, index) => {
        const row = document.createElement('tr');
        const colorValue = item.color || '';
        row.innerHTML = `
            <td>
                <input type="text" class="form-control form-control-sm" 
                       id="colorText_${index}"
                       value="${escapeHtml(colorValue)}" 
                       onchange="updateItemField(${index}, 'color', this.value.trim())"
                       maxlength="50"
                       placeholder="Color name (e.g., Red, Blue, Black)">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm" 
                       value="${escapeHtml(item.storage || '')}" 
                       onchange="updateItemField(${index}, 'storage', this.value.trim())"
                       maxlength="50"
                       placeholder="e.g., 128GB">
            </td>
            <td class="sim-config-cell" style="${showSIMConfig ? '' : 'display: none;'}">
                <select class="form-select form-select-sm" 
                        onchange="updateItemField(${index}, 'sim_configuration', this.value)">
                    <option value="">Select</option>
                    <option value="Single SIM" ${item.sim_configuration === 'Single SIM' ? 'selected' : ''}>Single SIM</option>
                    <option value="Dual SIM" ${item.sim_configuration === 'Dual SIM' ? 'selected' : ''}>Dual SIM</option>
                    <option value="eSIM" ${item.sim_configuration === 'eSIM' ? 'selected' : ''}>eSIM</option>
                    <option value="Dual SIM + eSIM" ${item.sim_configuration === 'Dual SIM + eSIM' ? 'selected' : ''}>Dual SIM + eSIM</option>
                </select>
            </td>
            <td>
                <input type="text" class="form-control form-control-sm serial-number-input" 
                       value="${escapeHtml(item.serial_number || '')}" 
                       onchange="updateItemField(${index}, 'serial_number', this.value.trim())"
                       onblur="validateSerialNumber(${index}, this)"
                       maxlength="100"
                       placeholder="Required"
                       data-index="${index}"
                       required>
            </td>
            <td class="imei-cell" style="${showIMEI ? '' : 'display: none;'}">
                <input type="text" class="form-control form-control-sm imei-input" 
                       value="${escapeHtml(item.imei || '')}" 
                       onchange="updateItemField(${index}, 'imei', this.value.trim())"
                       onblur="validateIMEI(${index}, this)"
                       maxlength="15"
                       pattern="[0-9]{15}"
                       placeholder="15 digits"
                       data-index="${index}">
            </td>
            <td>
                <select class="form-select form-select-sm" 
                        onchange="updateItemField(${index}, 'condition', this.value)">
                    <option value="New" ${item.condition === 'New' ? 'selected' : ''}>New</option>
                    <option value="Refurbished" ${item.condition === 'Refurbished' ? 'selected' : ''}>Refurbished</option>
                    <option value="Used" ${item.condition === 'Used' ? 'selected' : ''}>Used</option>
                </select>
            </td>
            <td class="battery-cell" style="${showBatteryHealth ? '' : 'display: none;'}">
                <input type="number" class="form-control form-control-sm" 
                       value="${item.battery_health || ''}" 
                       onchange="validateAndUpdateField(${index}, 'battery_health', this.value, 0, 100)"
                       onblur="validateBatteryHealth(${index}, this)"
                       min="0" max="100" step="1"
                       placeholder="0-100">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm" 
                       value="${escapeHtml(item.manufacturer || '')}" 
                       onchange="updateItemField(${index}, 'manufacturer', this.value.trim())"
                       maxlength="100"
                       placeholder="Manufacturer">
            </td>
            <td>
                <input type="number" class="form-control form-control-sm" 
                       value="${item.warranty_months || 0}" 
                       onchange="validateAndUpdateField(${index}, 'warranty_months', this.value, 0, 999)"
                       onblur="validateWarrantyMonths(${index}, this)"
                       min="0" max="999" step="1"
                       placeholder="Months">
            </td>
            <td>
                <input type="number" step="0.01" class="form-control form-control-sm" 
                       value="${item.cost_price || ''}" 
                       onchange="updateItemField(${index}, 'cost_price', this.value)"
                       min="0"
                       placeholder="0.00">
            </td>
            <td>
                <input type="number" step="0.01" class="form-control form-control-sm" 
                       value="${item.selling_price || ''}" 
                       onchange="updateItemField(${index}, 'selling_price', this.value)"
                       min="0"
                       placeholder="0.00"
                       required>
            </td>
            <td>
                <input type="number" step="0.01" class="form-control form-control-sm" 
                       value="${item.wholesale_price || ''}" 
                       onchange="updateItemField(${index}, 'wholesale_price', this.value)"
                       min="0"
                       placeholder="Optional">
            </td>
            <td>
                <span class="badge bg-${item.status === 'available' ? 'success' : (item.status === 'sold' ? 'danger' : 'secondary')}">
                    ${escapeHtml(item.status || 'available')}
                </span>
            </td>
            <td>
                ${item.id ? `
                    <button type="button" class="btn btn-sm btn-danger" onclick="deleteSpecificItem(${index}, ${item.id})">
                        <i class="bi bi-trash"></i>
                    </button>
                ` : `
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeSpecificItemRow(${index})">
                        <i class="bi bi-x"></i>
                    </button>
                `}
            </td>
        `;
        tbody.appendChild(row);
        
    });
}


function updateItemField(index, field, value) {
    if (index >= 0 && index < specificItemsData.length) {
        specificItemsData[index][field] = value;
    }
}

function validateAndUpdateField(index, field, value, min, max) {
    const numValue = parseFloat(value);
    if (isNaN(numValue) || value === '') {
        if (field === 'battery_health' || field === 'warranty_months') {
            updateItemField(index, field, '');
            return;
        }
    }
    
    if (!isNaN(numValue)) {
        if (numValue < min) {
            value = min;
        } else if (numValue > max) {
            value = max;
        }
    }
    
    updateItemField(index, field, value);
}

function validateBatteryHealth(index, input) {
    const value = parseFloat(input.value);
    if (input.value !== '' && (isNaN(value) || value < 0 || value > 100)) {
        input.classList.add('is-invalid');
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Battery Health',
            text: 'Battery health must be between 0 and 100%',
            timer: 2000,
            showConfirmButton: false
        });
        input.value = '';
        updateItemField(index, 'battery_health', '');
    } else {
        input.classList.remove('is-invalid');
        if (value >= 0 && value <= 100) {
            updateItemField(index, 'battery_health', value);
        }
    }
}

function validateWarrantyMonths(index, input) {
    const value = parseInt(input.value);
    if (input.value !== '' && (isNaN(value) || value < 0 || value > 999)) {
        input.classList.add('is-invalid');
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Warranty',
            text: 'Warranty months must be between 0 and 999',
            timer: 2000,
            showConfirmButton: false
        });
        input.value = 0;
        updateItemField(index, 'warranty_months', 0);
    } else {
        input.classList.remove('is-invalid');
        if (value >= 0 && value <= 999) {
            updateItemField(index, 'warranty_months', value);
        }
    }
}

function validateSerialNumber(index, input) {
    const value = input.value.trim();
    if (!value) {
        input.classList.add('is-invalid');
    } else {
        input.classList.remove('is-invalid');
        updateItemField(index, 'serial_number', value);
    }
}

function validateIMEI(index, input) {
    const value = input.value.trim();
    if (value && !/^\d{15}$/.test(value)) {
        input.classList.add('is-invalid');
        Swal.fire({
            icon: 'warning',
            title: 'Invalid IMEI',
            text: 'IMEI must be exactly 15 digits',
            timer: 2000,
            showConfirmButton: false
        });
    } else {
        input.classList.remove('is-invalid');
        if (value) {
            updateItemField(index, 'imei', value);
        }
    }
}

function addSpecificItemRow() {
    specificItemsData.push({
        id: null,
        product_id: <?= $product['id'] ?>,
        branch_id: <?= $product['branch_id'] ?? $_SESSION['branch_id'] ?? 'null' ?>,
        color: '',
        storage: '',
        sim_configuration: showSIMConfig ? '' : null,
        serial_number: '',
        imei: showIMEI ? '' : null,
        battery_health: showBatteryHealth ? '' : null,
        manufacturer: '',
        warranty_months: 0,
        warranty_terms: '',
        condition: 'New',
        trade_in_eligible: 0,
        cost_price: '',
        selling_price: '',
        wholesale_price: '',
        status: 'available'
    });
    renderSpecificItemsTable();
}

function addMultipleItems() {
    Swal.fire({
        title: 'Add Multiple Items',
        html: `
            <div class="mb-3">
                <label>Number of items to add:</label>
                <input type="number" id="itemCount" class="form-control" value="1" min="1" max="50">
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Add',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
            const count = parseInt(document.getElementById('itemCount').value) || 1;
            if (count < 1 || count > 50) {
                Swal.showValidationMessage('Please enter a number between 1 and 50');
                return false;
            }
            return count;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const count = result.value;
            for (let i = 0; i < count; i++) {
                addSpecificItemRow();
            }
        }
    });
}

function removeSpecificItemRow(index) {
    specificItemsData.splice(index, 1);
    renderSpecificItemsTable();
}

function deleteSpecificItem(index, itemId) {
    Swal.fire({
        title: 'Delete Item?',
        text: 'This will permanently delete this item. Are you sure?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= BASE_URL ?>ajax/manage_product_specific_list.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete&id=${itemId}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    specificItemsData.splice(index, 1);
                    renderSpecificItemsTable();
                    Swal.fire('Success', 'Item deleted successfully', 'success');
                    setTimeout(() => window.location.reload(), 1000);
    } else {
                    Swal.fire('Error', data.message || 'Failed to delete item', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', 'Failed to delete item', 'error');
            });
        }
    });
}

function saveSpecificItems() {
    // Validate all items before saving
    const validationErrors = [];
    
    for (let i = 0; i < specificItemsData.length; i++) {
        const item = specificItemsData[i];
        const itemNum = i + 1;
        
        // Validate serial number or IMEI is required (only for smartphone/tablet)
        if (showIMEI && !item.serial_number && !item.imei) {
            validationErrors.push(`Item ${itemNum}: Must have either Serial Number or IMEI`);
        } else if (!showIMEI && !item.serial_number) {
            validationErrors.push(`Item ${itemNum}: Serial Number is required`);
        }
        
        // Validate serial number if provided
        if (item.serial_number && item.serial_number.trim().length === 0) {
            validationErrors.push(`Item ${itemNum}: Serial Number cannot be empty`);
        }
        
        // Validate IMEI format if provided (only for smartphone/tablet)
        if (showIMEI && item.imei && item.imei.trim() !== '') {
            const imei = item.imei.trim();
            if (!/^\d{15}$/.test(imei)) {
                validationErrors.push(`Item ${itemNum}: IMEI must be exactly 15 digits`);
            }
        }
        
        // Validate selling price is required
        if (!item.selling_price || parseFloat(item.selling_price) <= 0) {
            validationErrors.push(`Item ${itemNum}: Selling Price is required and must be greater than 0`);
        }
        
        // Validate battery health
        if (item.battery_health !== '' && item.battery_health !== null && item.battery_health !== undefined) {
            const battery = parseFloat(item.battery_health);
            if (isNaN(battery) || battery < 0 || battery > 100) {
                validationErrors.push(`Item ${itemNum}: Battery health must be between 0 and 100%`);
            }
        }
        
        // Validate warranty months
        if (item.warranty_months !== '' && item.warranty_months !== null && item.warranty_months !== undefined) {
            const warranty = parseInt(item.warranty_months);
            if (isNaN(warranty) || warranty < 0 || warranty > 999) {
                validationErrors.push(`Item ${itemNum}: Warranty months must be between 0 and 999`);
            }
        }
    }
    
    if (validationErrors.length > 0) {
        Swal.fire({
            icon: 'error',
            title: 'Validation Errors',
            html: validationErrors.join('<br>'),
            width: '600px'
        });
        return;
    }
    
    // Separate new items from existing ones
    const newItems = specificItemsData.filter(item => !item.id);
    const existingItems = specificItemsData.filter(item => item.id);
    
    if (newItems.length === 0 && existingItems.length === 0) {
        Swal.fire('Info', 'No items to save', 'info');
        return;
    }
    
    Swal.fire({
        title: 'Saving...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    // Save new items and update existing items
    const savePromises = [];
    
    if (newItems.length > 0) {
        const formData = new FormData();
        formData.append('action', 'add');
        formData.append('product_id', <?= $product['id'] ?>);
        formData.append('branch_id', <?= $product['branch_id'] ?? $_SESSION['branch_id'] ?? 'null' ?>);
        formData.append('entries', JSON.stringify(newItems));
        
        savePromises.push(
            fetch('<?= BASE_URL ?>ajax/manage_product_specific_list.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json())
        );
    }
    
    // Update existing items
    if (existingItems.length > 0) {
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('entries', JSON.stringify(existingItems));
        
        savePromises.push(
            fetch('<?= BASE_URL ?>ajax/manage_product_specific_list.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json())
        );
    }
    
    Promise.all(savePromises)
        .then(results => {
            let added = 0;
            let updated = 0;
            let errors = [];
            
            results.forEach(result => {
                if (result.success) {
                    if (result.added) added += result.added;
                    if (result.updated) updated += result.updated;
                    if (result.errors) errors = errors.concat(result.errors);
                } else {
                    // Include both message and errors array
                    if (result.errors && result.errors.length > 0) {
                        // Use specific errors from the array
                        errors = errors.concat(result.errors);
                    } else if (result.message) {
                        // If no errors array but there's a message, check if message contains error details
                        if (result.message.includes(':') && result.message.length > 50) {
                            // Message likely contains error details, use it
                            errors.push(result.message);
                        } else {
                            // Generic message, try to be more helpful
                            errors.push(result.message || 'Failed to save');
                        }
                    } else {
                        errors.push('Failed to save');
                    }
                }
            });
            
            if (added > 0 || updated > 0) {
                let message = '';
                let hasErrors = errors.length > 0;
                
                if (added > 0 && updated > 0) {
                    message = `Added ${added} item(s) and updated ${updated} item(s) successfully`;
                } else if (added > 0) {
                    message = `Added ${added} item(s) successfully`;
                } else {
                    message = `Updated ${updated} item(s) successfully`;
                }
                
                if (hasErrors) {
                    message += '<br><br><strong>Some errors occurred:</strong><br>' + errors.join('<br>');
                }
                
                Swal.fire({
                    icon: hasErrors ? 'warning' : 'success',
                    title: hasErrors ? 'Partial Success' : 'Success',
                    html: message,
                    width: '600px'
                }).then(() => {
                    window.location.reload();
                });
            } else {
                // Build error message from all sources
                let errorMessage = '';
                if (errors.length > 0) {
                    errorMessage = errors.join('<br>');
                } else {
                    // Check if result has specific error messages
                    results.forEach(result => {
                        if (!result.success) {
                            if (result.errors && result.errors.length > 0) {
                                if (errorMessage) errorMessage += '<br>';
                                errorMessage += result.errors.join('<br>');
                            } else if (result.message) {
                                if (errorMessage) errorMessage += '<br>';
                                errorMessage += result.message;
                            }
                        }
                    });
                }
                
                if (!errorMessage) {
                    errorMessage = 'Failed to save items';
                }
                
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    html: errorMessage,
                    width: '600px'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to save items', 'error');
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
<?php endif; ?>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

