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
    // Handle image upload
    $uploadedImages = [];
    if (!empty($_FILES['product_images']['name'][0])) {
        $uploadDir = APP_PATH . '/uploads/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        foreach ($_FILES['product_images']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['product_images']['error'][$key] === UPLOAD_ERR_OK) {
                $fileName = uniqid() . '_' . basename($_FILES['product_images']['name'][$key]);
                $targetPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($tmpName, $targetPath)) {
                    $uploadedImages[] = BASE_URL . 'uploads/products/' . $fileName;
                }
            }
        }
    }
    
    // Get category to determine if it's General
    $categoryId = $_POST['category_id'] ?? null;
    $isGeneralCategory = false;
    if ($categoryId) {
        $category = $db->getRow("SELECT * FROM product_categories WHERE id = :id", [':id' => $categoryId]);
        $isGeneralCategory = $category && strtolower($category['name']) === 'general';
    }
    
    $data = [
        'product_code' => generateProductCode(),
        'category_id' => $categoryId,
        'product_name' => $isGeneralCategory ? sanitizeInput($_POST['product_name'] ?? '') : null,
        'brand' => $isGeneralCategory ? null : sanitizeInput($_POST['brand'] ?? ''),
        'model' => $isGeneralCategory ? null : sanitizeInput($_POST['model'] ?? ''),
        'color' => sanitizeInput($_POST['color'] ?? ''),
        'storage' => sanitizeInput($_POST['storage'] ?? ''),
        'serial_number' => sanitizeInput($_POST['serial_number'] ?? ''),
        'imei' => sanitizeInput($_POST['imei'] ?? ''),
        'sim_configuration' => sanitizeInput($_POST['sim_configuration'] ?? ''),
        'battery_health' => !empty($_POST['battery_health']) ? intval($_POST['battery_health']) : null,
        'expiry_date' => !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
        'weight' => !empty($_POST['weight']) ? floatval($_POST['weight']) : null,
        'unit_of_measure' => sanitizeInput($_POST['unit_of_measure'] ?? ''),
        'manufacturer' => sanitizeInput($_POST['manufacturer'] ?? ''),
        'batch_number' => sanitizeInput($_POST['batch_number'] ?? ''),
        'barcode' => sanitizeInput($_POST['barcode'] ?? ''),
        'description' => sanitizeInput($_POST['description'] ?? ''),
        'specifications' => sanitizeInput($_POST['specifications'] ?? ''),
        'cost_price' => $_POST['cost_price'] ?? 0,
        'selling_price' => $_POST['selling_price'] ?? 0,
        'reorder_level' => $_POST['reorder_level'] ?? 0,
        'branch_id' => $_POST['branch_id'] ?? $_SESSION['branch_id'],
        'quantity_in_stock' => $_POST['quantity_in_stock'] ?? 0,
        'status' => 'Active',
        'created_by' => $_SESSION['user_id'],
        'created_at' => date('Y-m-d H:i:s'),
        'images' => !empty($uploadedImages) ? json_encode($uploadedImages) : null
    ];
    
    // Validation: For General category, product_name is required; for others, brand and model are required
    if ($isGeneralCategory && empty($data['product_name'])) {
        $error = 'Product name is required for General category products.';
    } elseif (!$isGeneralCategory && (empty($data['brand']) || empty($data['model']))) {
        $error = 'Brand and Model are required for this category.';
    } else {
        if ($db->insert('products', $data)) {
            $_SESSION['success_message'] = 'Product added successfully!';
            redirectTo('modules/products/index.php');
        } else {
            $error = 'Failed to add product: ' . $db->getLastError();
        }
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<style>
.category-search-dropdown {
    max-height: 300px;
    overflow-y: auto;
    z-index: 1050;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}
.color-picker-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
}
.color-preview {
    width: 40px;
    height: 40px;
    border: 2px solid #ddd;
    border-radius: 4px;
    display: inline-block;
    cursor: pointer;
}
.image-preview-container {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}
.image-preview-item {
    position: relative;
    width: 100px;
    height: 100px;
    border: 2px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
}
.image-preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.image-preview-item .remove-image {
    position: absolute;
    top: 2px;
    right: 2px;
    background: rgba(255,0,0,0.8);
    color: white;
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>

<div class="card">
    <div class="card-header">Add New Product</div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= escapeHtml($error) ?></div>
        <?php endif; ?>
        
        <form method="POST" id="productForm" enctype="multipart/form-data">
            <!-- Category and Branch -->
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Category *</label>
                    <div class="position-relative">
                        <input type="text" 
                               class="form-control" 
                               id="categorySearch" 
                               placeholder="Search categories..." 
                               autocomplete="off" 
                               required>
                        <input type="hidden" name="category_id" id="categoryId" required>
                        <div class="dropdown-menu position-absolute w-100 category-search-dropdown" id="categoryDropdown" style="display: none;">
                            <?php foreach ($categories as $cat): ?>
                                <a class="dropdown-item category-item" 
                                   href="#" 
                                   data-id="<?= $cat['id'] ?>" 
                                   data-text="<?= escapeHtml($cat['name']) ?>"
                                   data-name="<?= escapeHtml(strtolower($cat['name'])) ?>">
                                    <?= escapeHtml($cat['name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
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
            
            <!-- Product Name (for General) or Brand/Model (for others) -->
            <div class="row" id="productNameRow" style="display: none;">
                <div class="col-md-12 mb-3">
                    <label class="form-label">Product Name *</label>
                    <input type="text" class="form-control" name="product_name" id="productNameInput" placeholder="e.g., Sugar White 2kg">
                </div>
            </div>
            
            <div class="row" id="brandModelRow">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Brand *</label>
                    <input type="text" class="form-control" name="brand" id="brandInput" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Model *</label>
                    <input type="text" class="form-control" name="model" id="modelInput" required>
                </div>
            </div>
            
            <!-- Color Picker (Always visible) -->
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Choose a Color</label>
                    <div class="color-picker-wrapper">
                        <input type="color" class="form-control form-control-color" name="color" id="colorPicker" value="#ffffff" style="width: 60px; height: 40px; cursor: pointer;">
                        <div class="color-preview" id="colorPreview" style="background-color: #ffffff;"></div>
                        <input type="text" class="form-control" id="colorHex" placeholder="#ffffff" style="max-width: 120px;">
                    </div>
                    <small class="text-muted">Select a color for this product</small>
                </div>
            </div>
            
            <!-- Image Upload -->
            <div class="row">
                <div class="col-md-12 mb-3">
                    <label class="form-label">Upload Image</label>
                    <input type="file" class="form-control" name="product_images[]" id="productImages" accept="image/*" multiple>
                    <small class="text-muted">You can upload multiple images</small>
                    <div class="image-preview-container" id="imagePreviewContainer"></div>
                </div>
            </div>
            
            <!-- Dynamic Fields - Electronics (Storage, Battery, Serial, IMEI, SIM) -->
            <div class="row">
                <div class="col-md-3 mb-3" id="storageField" style="display: none;">
                    <label class="form-label">Storage</label>
                    <input type="text" class="form-control" name="storage" placeholder="e.g., 128GB, 256GB">
                </div>
                <div class="col-md-3 mb-3" id="batteryHealthField" style="display: none;">
                    <label class="form-label">Battery Health (%)</label>
                    <input type="number" class="form-control" name="battery_health" min="0" max="100">
                </div>
                <div class="col-md-3 mb-3" id="serialNumberField" style="display: none;">
                    <label class="form-label">Serial Number</label>
                    <input type="text" class="form-control" name="serial_number">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-3" id="imeiField" style="display: none;">
                    <label class="form-label">IMEI</label>
                    <input type="text" class="form-control" name="imei" maxlength="15">
                </div>
                <div class="col-md-3 mb-3" id="simConfigField" style="display: none;">
                    <label class="form-label">SIM Configuration</label>
                    <select class="form-control" name="sim_configuration">
                        <option value="">Select</option>
                        <option value="Single SIM">Single SIM</option>
                        <option value="Dual SIM">Dual SIM</option>
                        <option value="eSIM">eSIM</option>
                        <option value="Dual SIM + eSIM">Dual SIM + eSIM</option>
                    </select>
                </div>
            </div>
            
            <!-- Grocery/General Fields -->
            <div class="row">
                <div class="col-md-3 mb-3" id="expiryDateField" style="display: none;">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" class="form-control" name="expiry_date">
                </div>
                <div class="col-md-3 mb-3" id="weightField" style="display: none;">
                    <label class="form-label">Weight</label>
                    <input type="number" step="0.001" class="form-control" name="weight" placeholder="e.g., 0.5">
                </div>
                <div class="col-md-3 mb-3" id="unitOfMeasureField" style="display: none;">
                    <label class="form-label">Unit of Measure</label>
                    <select class="form-control" name="unit_of_measure">
                        <option value="">Select</option>
                        <option value="kg">Kilogram (kg)</option>
                        <option value="g">Gram (g)</option>
                        <option value="L">Liter (L)</option>
                        <option value="mL">Milliliter (mL)</option>
                        <option value="piece">Piece</option>
                        <option value="pack">Pack</option>
                        <option value="box">Box</option>
                        <option value="bottle">Bottle</option>
                        <option value="can">Can</option>
                        <option value="bag">Bag</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3" id="manufacturerField" style="display: none;">
                    <label class="form-label">Manufacturer</label>
                    <input type="text" class="form-control" name="manufacturer">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-3" id="batchNumberField" style="display: none;">
                    <label class="form-label">Batch Number</label>
                    <input type="text" class="form-control" name="batch_number">
                </div>
            </div>
            
            <!-- Pricing and Stock -->
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
            
            <!-- Additional Fields -->
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Barcode</label>
                    <input type="text" class="form-control" name="barcode" placeholder="For scanning">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Reorder Level</label>
                    <input type="number" class="form-control" name="reorder_level" value="10">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="3"></textarea>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Specifications</label>
                <textarea class="form-control" name="specifications" rows="3" placeholder="Additional specifications or notes"></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Product</button>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<script>
// Category search functionality
const categorySearch = document.getElementById('categorySearch');
const categoryDropdown = document.getElementById('categoryDropdown');
const categoryId = document.getElementById('categoryId');
let categoryBlurTimeout;

if (categorySearch && categoryDropdown) {
    categorySearch.addEventListener('input', function() {
        filterCategories(this.value);
    });
    
    categorySearch.addEventListener('focus', function() {
        clearTimeout(categoryBlurTimeout);
        showCategoryDropdown();
    });
    
    categorySearch.addEventListener('blur', function() {
        categoryBlurTimeout = setTimeout(() => {
            categoryDropdown.style.display = 'none';
        }, 300);
    });
    
    categoryDropdown.addEventListener('click', function(e) {
        e.preventDefault();
        const item = e.target.closest('.category-item');
        if (item) {
            clearTimeout(categoryBlurTimeout);
            categorySearch.value = item.dataset.text;
            categoryId.value = item.dataset.id;
            categoryDropdown.style.display = 'none';
            updateDynamicFields(item.dataset.name);
        }
    });
}

function filterCategories(searchTerm) {
    if (!categoryDropdown) return;
    const items = categoryDropdown.querySelectorAll('.category-item');
    const term = searchTerm.toLowerCase().trim();
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(term) ? 'block' : 'none';
    });
}

function showCategoryDropdown() {
    if (!categoryDropdown || !categorySearch) return;
    if (categorySearch.value.trim() === '') {
        categoryDropdown.querySelectorAll('.category-item').forEach(item => {
            item.style.display = 'block';
        });
    }
    categoryDropdown.style.display = 'block';
}

// Color picker functionality
const colorPicker = document.getElementById('colorPicker');
const colorPreview = document.getElementById('colorPreview');
const colorHex = document.getElementById('colorHex');

if (colorPicker && colorPreview && colorHex) {
    colorPicker.addEventListener('input', function() {
        const color = this.value;
        colorPreview.style.backgroundColor = color;
        colorHex.value = color;
    });
    
    colorHex.addEventListener('input', function() {
        const color = this.value;
        if (/^#[0-9A-F]{6}$/i.test(color)) {
            colorPicker.value = color;
            colorPreview.style.backgroundColor = color;
        }
    });
}

// Image preview functionality
const productImages = document.getElementById('productImages');
const imagePreviewContainer = document.getElementById('imagePreviewContainer');
const imageFiles = [];

if (productImages) {
    productImages.addEventListener('change', function(e) {
        const files = Array.from(e.target.files);
        imageFiles.length = 0;
        
        imagePreviewContainer.innerHTML = '';
        
        files.forEach((file, index) => {
            if (file.type.startsWith('image/')) {
                imageFiles.push(file);
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'image-preview-item';
                    div.innerHTML = `
                        <img src="${e.target.result}" alt="Preview">
                        <button type="button" class="remove-image" onclick="removeImagePreview(${index})">
                            <i class="bi bi-x"></i>
                        </button>
                    `;
                    imagePreviewContainer.appendChild(div);
                };
                reader.readAsDataURL(file);
            }
        });
    });
}

function removeImagePreview(index) {
    imageFiles.splice(index, 1);
    const dt = new DataTransfer();
    imageFiles.forEach(file => dt.items.add(file));
    productImages.files = dt.files;
    
    // Re-render previews
    imagePreviewContainer.innerHTML = '';
    imageFiles.forEach((file, idx) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const div = document.createElement('div');
            div.className = 'image-preview-item';
            div.innerHTML = `
                <img src="${e.target.result}" alt="Preview">
                <button type="button" class="remove-image" onclick="removeImagePreview(${idx})">
                    <i class="bi bi-x"></i>
                </button>
            `;
            imagePreviewContainer.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
}

// Dynamic fields based on category
function updateDynamicFields(categoryName) {
    if (!categoryName) {
        const selectedItem = categoryDropdown.querySelector('.category-item[data-id="' + categoryId.value + '"]');
        if (selectedItem) {
            categoryName = selectedItem.dataset.name;
        } else {
            categoryName = '';
        }
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
        if (brandInput) brandInput.required = false;
        if (modelInput) modelInput.required = false;
    } else {
        if (productNameRow) productNameRow.style.display = 'none';
        if (brandModelRow) brandModelRow.style.display = 'block';
        if (productNameInput) productNameInput.required = false;
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
    
    // Show fields based on category
    if (categoryName.includes('smartphone') || categoryName.includes('phone')) {
        // Smartphones: Show all electronics fields
        if (storageField) storageField.style.display = 'block';
        if (batteryHealthField) batteryHealthField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
        if (imeiField) imeiField.style.display = 'block';
        if (simConfigField) simConfigField.style.display = 'block';
    } else if (categoryName.includes('laptop')) {
        // Laptops: Storage, Serial Number
        if (storageField) storageField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
    } else if (categoryName.includes('tablet')) {
        // Tablets: Storage, Battery Health, Serial Number
        if (storageField) storageField.style.display = 'block';
        if (batteryHealthField) batteryHealthField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
    } else if (categoryName.includes('audio') || categoryName.includes('wearable')) {
        // Audio Devices & Wearables: Battery Health (for wearables)
        if (categoryName.includes('wearable') && batteryHealthField) {
            batteryHealthField.style.display = 'block';
        }
    } else if (isGeneral) {
        // General/Grocery: Show grocery-specific fields
        if (expiryDateField) expiryDateField.style.display = 'block';
        if (weightField) weightField.style.display = 'block';
        if (unitOfMeasureField) unitOfMeasureField.style.display = 'block';
        if (manufacturerField) manufacturerField.style.display = 'block';
        if (batchNumberField) batchNumberField.style.display = 'block';
    }
}

// Initialize on page load if category is already selected
document.addEventListener('DOMContentLoaded', function() {
    if (categoryId.value) {
        const selectedItem = categoryDropdown.querySelector('.category-item[data-id="' + categoryId.value + '"]');
        if (selectedItem) {
            updateDynamicFields(selectedItem.dataset.name);
        }
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
