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
$primaryDb = Database::getPrimaryInstance();
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");

$error = '';
$success = '';

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
    
    // Check if this product requires product_specific_list (based on category is_specific flag)
    $requiresSpecificList = false;
    if ($categoryId) {
        $category = $db->getRow("SELECT name, is_specific FROM product_categories WHERE id = :id", [':id' => $categoryId]);
        if ($category) {
            // Use is_specific flag (new system) with legacy fallback
            $requiresSpecificList = !empty($category['is_specific']);
            if (!$requiresSpecificList) {
                $categoryName = strtolower($category['name']);
                $requiresSpecificList = (strpos($categoryName, 'smartphone') !== false || 
                                       strpos($categoryName, 'phone') !== false || 
                                       strpos($categoryName, 'laptop') !== false ||
                                       strpos($categoryName, 'tablet') !== false ||
                                       strpos($categoryName, 'gaming') !== false);
            }
        }
    }
    
    // Get stock control setting
    $stockControlEnabled = isset($_POST['stock_control']) && $_POST['stock_control'] == '1';
    
    // Get selected branches from checkboxes in Inventory section
    $selectedBranches = [];
    $branchQuantities = [];
    $branchReorderLevels = [];
    $branchCostPrices = [];
    $branchSellingPrices = [];
    $branchWholesalePrices = [];
    
    // Get branches from shop_available checkboxes
    if (!empty($_POST['shop_available'])) {
        foreach ($_POST['shop_available'] as $branchId => $value) {
            $branchId = intval($branchId);
            if ($branchId > 0 && $value == '1') {
                $selectedBranches[] = $branchId;
                
                // Get branch-specific prices
                $branchCostPrices[$branchId] = !empty($_POST['branch_cost_price'][$branchId]) ? floatval($_POST['branch_cost_price'][$branchId]) : 0;
                $branchSellingPrices[$branchId] = !empty($_POST['branch_selling_price'][$branchId]) ? floatval($_POST['branch_selling_price'][$branchId]) : 0;
                $branchWholesalePrices[$branchId] = !empty($_POST['branch_wholesale_price'][$branchId]) ? floatval($_POST['branch_wholesale_price'][$branchId]) : null;
                
                // Get branch-specific quantity and reorder level if stock control is enabled
                if ($stockControlEnabled) {
                    $branchQuantities[$branchId] = intval($_POST['branch_qty'][$branchId] ?? 0);
                    $branchReorderLevels[$branchId] = intval($_POST['branch_reorder'][$branchId] ?? 0);
                } else {
                    // Stock control off: set to 0
                    $branchQuantities[$branchId] = 0;
                    $branchReorderLevels[$branchId] = 0;
                }
            }
        }
    }
    
    if (empty($selectedBranches)) {
        $error = 'Please select at least one branch.';
    }
    
    // Base product data (without branch_id - will be set per branch)
    $baseData = [
        'product_code' => generateProductCode(),
        'category_id' => $categoryId,
        'product_name' => $isGeneralCategory ? sanitizeInput($_POST['product_name'] ?? '') : null,
        'brand' => $isGeneralCategory ? null : sanitizeInput($_POST['brand'] ?? ''),
        'model' => $isGeneralCategory ? null : sanitizeInput($_POST['model'] ?? ''),
        // Note: color, storage, sim_configuration, serial_number, imei, battery_health, 
        // manufacturer, warranty_months, warranty_terms, condition, trade_in_eligible 
        // are no longer stored at product level - they go in product_specific_list
        'expiry_date' => !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
        'weight' => !empty($_POST['weight']) ? floatval($_POST['weight']) : null,
        'unit_of_measure' => sanitizeInput($_POST['unit_of_measure'] ?? ''),
        'barcode' => sanitizeInput($_POST['barcode'] ?? ''),
        'description' => sanitizeInput($_POST['description'] ?? ''),
        'specifications' => sanitizeInput($_POST['specifications'] ?? ''),
        'tax_id' => !empty($_POST['tax_id']) ? intval($_POST['tax_id']) : null,
        'requires_specific_list' => $requiresSpecificList ? 1 : 0,
        'status' => 'Active',
        'created_by' => $_SESSION['user_id'],
        'source' => 'manual',
        'created_at' => date('Y-m-d H:i:s'),
        'images' => !empty($uploadedImages) ? json_encode($uploadedImages) : null
    ];
    
    // Validation: For General category, product_name is required; for others, brand and model are required
    if (empty($error)) {
        if ($isGeneralCategory && empty($baseData['product_name'])) {
            $error = 'Product name is required for General category products.';
        } elseif (!$isGeneralCategory && (empty($baseData['brand']) || empty($baseData['model']))) {
            $error = 'Brand and Model are required for this category.';
        } else {
            $db->beginTransaction();
            try {
                $createdProductIds = [];
                $createdBranches = [];
                
                // Create product in each selected branch
                foreach ($selectedBranches as $branchId) {
                    $data = $baseData;
                    $data['branch_id'] = $branchId;
                    // Generate unique product code for each branch instance
                    $data['product_code'] = generateProductCode();
                    // Set branch-specific prices
                    $data['cost_price'] = $branchCostPrices[$branchId] ?? 0;
                    $data['selling_price'] = $branchSellingPrices[$branchId] ?? 0;
                    $data['wholesale_price'] = $branchWholesalePrices[$branchId] ?? null;
                    // Set branch-specific quantity and reorder level
                    $data['quantity_in_stock'] = $branchQuantities[$branchId] ?? 0;
                    $data['reorder_level'] = $branchReorderLevels[$branchId] ?? 0;
                    
                    $productId = $db->insert('products', $data);
                    if (!$productId) {
                        throw new Exception('Failed to add product for branch ID ' . $branchId . ': ' . $db->getLastError());
                    }
                    
                    $createdProductIds[] = $productId;
                    
                    // Get branch name for success message
                    $branch = $db->getRow("SELECT branch_name FROM branches WHERE id = :id", [':id' => $branchId]);
                    $createdBranches[] = $branch ? $branch['branch_name'] : 'Branch ' . $branchId;
                }
                
                $db->commitTransaction();
                
                // Success message
                $branchCount = count($createdBranches);
                $branchNames = implode(', ', $createdBranches);
                if ($branchCount > 1) {
                    $_SESSION['success_message'] = "Product created successfully in {$branchCount} branches: {$branchNames}!";
                } else {
                    $_SESSION['success_message'] = "Product created successfully in {$branchNames}!";
                }
                
                // If product requires specific list, redirect to view page of first created product
                if ($requiresSpecificList && !empty($createdProductIds)) {
                    $_SESSION['success_message'] .= ' Please add individual instances below.';
                    redirectTo('modules/products/view.php?id=' . $createdProductIds[0]);
                } else {
                    redirectTo('modules/products/index.php');
                }
            } catch (Exception $e) {
                $db->rollbackTransaction();
                $error = $e->getMessage();
            }
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
        
        <form method="POST" id="productForm" enctype="multipart/form-data" onsubmit="return validateBranchSelection()">
            <!-- Category -->
            <div class="row">
                <div class="col-md-12 mb-3">
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
                                   data-name="<?= escapeHtml(strtolower($cat['name'])) ?>"
                                   data-is-specific="<?= $cat['is_specific'] ?? 0 ?>">
                                    <?= escapeHtml($cat['name']) ?>
                                    <?php if (!empty($cat['is_specific'])): ?>
                                        <span class="badge bg-info ms-1" style="font-size:0.65rem;">Specific</span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
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
            
            <!-- Color Picker - REMOVED: Color is now captured in product_specific_list during GRN/Stock Take -->
            <!-- For products requiring specific list (smartphones, laptops, tablets, gaming), 
                 color is captured per individual instance, not at product level -->
            
            <!-- Image Upload -->
            <div class="row">
                <div class="col-md-12 mb-3">
                    <label class="form-label">Upload Image</label>
                    <input type="file" class="form-control" name="product_images[]" id="productImages" accept="image/*" multiple>
                    <small class="text-muted">You can upload multiple images</small>
                    <div class="image-preview-container" id="imagePreviewContainer"></div>
                </div>
            </div>
            
            <!-- Note: For products requiring specific list (smartphones, laptops, tablets, gaming),
                 individual instances with color, storage, serial, IMEI, etc. should be added via:
                 - GRN (Goods Received Notes)
                 - Stock Take
                 - Bulk Upload
                 
                 These fields are no longer captured at product creation level. -->
            
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
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-3" id="batchNumberField" style="display: none;">
                    <label class="form-label">Batch Number</label>
                    <input type="text" class="form-control" name="batch_number">
                </div>
            </div>
            
            <!-- Info message for products requiring specific list -->
            <div id="specificListInfo" class="alert alert-info" style="display: none;">
                <i class="bi bi-info-circle"></i> 
                <strong>Note:</strong> This product type requires individual instance tracking. 
                After creating the product, you'll need to add specific instances (with color, storage, serial numbers, etc.) 
                via GRN, Stock Take, or Bulk Upload.
            </div>
            
            <!-- Inventory and Branch Selection -->
            <div class="row">
                <div class="col-md-12 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title mb-3">Inventory</h5>
                            <div class="d-flex align-items-center mb-3">
                                <label class="form-label mb-0 me-3">Stock control</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="stockControl" name="stock_control" value="1" onchange="toggleStockControl()" style="width: 3em; height: 1.5em;">
                                    <label class="form-check-label" for="stockControl"></label>
                                </div>
                            </div>
                            
                            <!-- Branch-specific settings -->
                            <div id="branchStockSettings">
                                <h6 class="mb-3">Branches</h6>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="selectAllBranches" onchange="toggleAllBranchAvailability()">
                                    <label class="form-check-label" for="selectAllBranches">
                                        The item is available for sale in all branches
                                    </label>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="80">Available</th>
                                                <th>Branch</th>
                                                <th width="120">Cost Price *</th>
                                                <th width="120">Selling Price *</th>
                                                <th width="120">Wholesale Price</th>
                                                <th width="100" id="stockHeader" style="display: none;">In stock</th>
                                                <th width="100" id="reorderHeader" style="display: none;">Safety stock</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($branches as $branch): ?>
                                                <tr id="branchRow_<?= $branch['id'] ?>" style="<?= $branch['id'] == $_SESSION['branch_id'] ? '' : 'display: none;' ?>">
                                                    <td>
                                                        <div class="form-check">
                                                            <input class="form-check-input branch-available" 
                                                                   type="checkbox" 
                                                                   name="shop_available[<?= $branch['id'] ?>]" 
                                                                   value="1"
                                                                   id="branch_available_<?= $branch['id'] ?>"
                                                                   <?= $branch['id'] == $_SESSION['branch_id'] ? 'checked' : '' ?>
                                                                   onchange="toggleBranchRow(<?= $branch['id'] ?>)">
                                                        </div>
                                                    </td>
                                                    <td><?= escapeHtml($branch['branch_name']) ?></td>
                                                    <td>
                                                        <input type="number" 
                                                               step="0.01"
                                                               class="form-control form-control-sm" 
                                                               name="branch_cost_price[<?= $branch['id'] ?>]" 
                                                               id="branch_cost_price_<?= $branch['id'] ?>"
                                                               value="0" 
                                                               min="0"
                                                               placeholder="0.00">
                                                    </td>
                                                    <td>
                                                        <input type="number" 
                                                               step="0.01"
                                                               class="form-control form-control-sm" 
                                                               name="branch_selling_price[<?= $branch['id'] ?>]" 
                                                               id="branch_selling_price_<?= $branch['id'] ?>"
                                                               value="0" 
                                                               min="0"
                                                               placeholder="0.00">
                                                    </td>
                                                    <td>
                                                        <input type="number" 
                                                               step="0.01"
                                                               class="form-control form-control-sm" 
                                                               name="branch_wholesale_price[<?= $branch['id'] ?>]" 
                                                               id="branch_wholesale_price_<?= $branch['id'] ?>"
                                                               value="" 
                                                               min="0"
                                                               placeholder="Optional">
                                                    </td>
                                                    <td class="stock-control-cell" style="display: none;">
                                                        <input type="number" 
                                                               class="form-control form-control-sm" 
                                                               name="branch_qty[<?= $branch['id'] ?>]" 
                                                               id="branch_qty_<?= $branch['id'] ?>"
                                                               value="0" 
                                                               min="0"
                                                               placeholder="0">
                                                    </td>
                                                    <td class="stock-control-cell" style="display: none;">
                                                        <input type="number" 
                                                               class="form-control form-control-sm" 
                                                               name="branch_reorder[<?= $branch['id'] ?>]" 
                                                               id="branch_reorder_<?= $branch['id'] ?>"
                                                               value="0" 
                                                               min="0"
                                                               placeholder="0">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tax Selection -->
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Applicable Tax</label>
                    <select class="form-control" name="tax_id" id="taxId">
                        <option value="">Select Tax (Optional)</option>
                        <?php 
                        // Get taxes for default branch (session branch or first branch)
                        $defaultBranchId = $_SESSION['branch_id'] ?? ($branches[0]['id'] ?? null);
                        $applicableTaxes = getApplicableTaxesForBranch($primaryDb, $defaultBranchId);
                        foreach ($applicableTaxes as $tax): 
                            $taxDisplay = sprintf(
                                "%s (%.2f%%) - Code: %s",
                                $tax['taxName'] ?? 'Tax',
                                $tax['taxPercent'] ?? 0,
                                $tax['taxCode'] ?? ''
                            );
                        ?>
                            <option value="<?= $tax['taxID'] ?? '' ?>" data-branch="<?= $defaultBranchId ?>">
                                <?= escapeHtml($taxDisplay) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Select the tax that applies to this product. This will be used when creating fiscal receipts.</small>
                </div>
            </div>
            
            <!-- Additional Fields -->
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Barcode</label>
                    <input type="text" class="form-control" name="barcode" placeholder="For scanning">
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

// Color picker functionality - REMOVED
// Color is now captured in product_specific_list during GRN/Stock Take, not at product level

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
    
    // Check if this product requires specific list (via is_specific flag on category)
    let requiresSpecificList = false;
    const selectedItem = categoryDropdown ? categoryDropdown.querySelector('.category-item[data-id="' + categoryId.value + '"]') : null;
    if (selectedItem && selectedItem.dataset.isSpecific == '1') {
        requiresSpecificList = true;
    }
    // Legacy fallback: check category name
    if (!requiresSpecificList) {
        requiresSpecificList = categoryName.includes('smartphone') || 
                                     categoryName.includes('phone') || 
                                     categoryName.includes('laptop') || 
                                     categoryName.includes('tablet') ||
                                     categoryName.includes('gaming');
    }
    
    // Show info message for products requiring specific list
    const specificListInfo = document.getElementById('specificListInfo');
    if (specificListInfo) {
        specificListInfo.style.display = requiresSpecificList ? 'block' : 'none';
    }
    
    // Quantity and reorder level are now handled per-branch in the stock control section
    
    // Show fields based on category (only for non-specific-list products)
    if (isGeneral) {
        // General/Grocery: Show grocery-specific fields
        if (expiryDateField) expiryDateField.style.display = 'block';
        if (weightField) weightField.style.display = 'block';
        if (unitOfMeasureField) unitOfMeasureField.style.display = 'block';
        if (batchNumberField) batchNumberField.style.display = 'block';
    }
}

// Load applicable taxes when branch selection changes
document.addEventListener('DOMContentLoaded', function() {
    const branchCheckboxes = document.querySelectorAll('.branch-available');
    const taxSelect = document.getElementById('taxId');
    
    if (branchCheckboxes.length > 0 && taxSelect) {
        branchCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                // Use the first checked branch for tax selection
                const checkedBranches = Array.from(document.querySelectorAll('.branch-available:checked'));
                const branchId = checkedBranches.length > 0 ? checkedBranches[0].id.replace('branch_available_', '') : null;
                
                if (!branchId) {
                    taxSelect.innerHTML = '<option value="">Select Tax (Optional)</option>';
                    return;
                }
                
                // Show loading
                taxSelect.innerHTML = '<option value="">Loading taxes...</option>';
                taxSelect.disabled = true;
                
                // Fetch taxes for first selected branch
                fetch('<?= BASE_URL ?>ajax/get_applicable_taxes.php?branch_id=' + branchId)
                    .then(response => response.json())
                    .then(data => {
                        taxSelect.innerHTML = '<option value="">Select Tax (Optional)</option>';
                        
                        if (data.success && data.taxes && data.taxes.length > 0) {
                            data.taxes.forEach(tax => {
                                const option = document.createElement('option');
                                option.value = tax.taxID;
                                option.textContent = `${tax.taxName || 'Tax'} (${tax.taxPercent || 0}%) - Code: ${tax.taxCode || ''}`;
                                taxSelect.appendChild(option);
                            });
                        } else {
                            const option = document.createElement('option');
                            option.value = '';
                            option.textContent = 'No taxes available for selected branch(es)';
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
        });
    }
    
    // Initialize branch rows visibility - show rows for checked branches
    const branchCheckboxes2 = document.querySelectorAll('.branch-available');
    branchCheckboxes2.forEach(cb => {
        const branchId = cb.id.replace('branch_available_', '');
        if (cb.checked) {
            const row = document.getElementById('branchRow_' + branchId);
            if (row) {
                row.style.display = '';
            }
        }
    });
});

// Form validation
function validateBranchSelection() {
    const checkedBranches = document.querySelectorAll('.branch-available:checked');
    if (checkedBranches.length === 0) {
        Swal.fire({
            icon: 'error',
            title: 'Branch Selection Required',
            text: 'Please select at least one branch where this product will be available.'
        });
        return false;
    }
    
    // Validate that all selected branches have cost price and selling price
    let hasError = false;
    let errorMessage = '';
    checkedBranches.forEach(cb => {
        const branchId = cb.id.replace('branch_available_', '');
        const costPrice = document.getElementById('branch_cost_price_' + branchId);
        const sellingPrice = document.getElementById('branch_selling_price_' + branchId);
        
        if (!costPrice || parseFloat(costPrice.value) <= 0) {
            hasError = true;
            errorMessage = 'Cost Price is required for all selected branches.';
        }
        if (!sellingPrice || parseFloat(sellingPrice.value) <= 0) {
            hasError = true;
            errorMessage = 'Selling Price is required for all selected branches.';
        }
    });
    
    if (hasError) {
        Swal.fire({
            icon: 'error',
            title: 'Validation Error',
            text: errorMessage
        });
        return false;
    }
    
    return true;
}

// Branch selection and stock control functions
function toggleAllBranchAvailability() {
    const selectAll = document.getElementById('selectAllBranches');
    const checkboxes = document.querySelectorAll('.branch-available');
    checkboxes.forEach(cb => {
        cb.checked = selectAll.checked;
        const branchId = cb.id.replace('branch_available_', '');
        toggleBranchRow(branchId);
    });
}

function toggleBranchRow(branchId) {
    const checkbox = document.getElementById('branch_available_' + branchId);
    const row = document.getElementById('branchRow_' + branchId);
    
    if (checkbox && checkbox.checked && row) {
        row.style.display = '';
    } else if (row) {
        row.style.display = 'none';
    }
}

function toggleStockControl() {
    const stockControl = document.getElementById('stockControl');
    const stockHeader = document.getElementById('stockHeader');
    const reorderHeader = document.getElementById('reorderHeader');
    const stockCells = document.querySelectorAll('.stock-control-cell');
    
    if (stockControl.checked) {
        if (stockHeader) stockHeader.style.display = '';
        if (reorderHeader) reorderHeader.style.display = '';
        stockCells.forEach(cell => {
            cell.style.display = '';
        });
    } else {
        if (stockHeader) stockHeader.style.display = 'none';
        if (reorderHeader) reorderHeader.style.display = 'none';
        stockCells.forEach(cell => {
            cell.style.display = 'none';
        });
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    if (categoryId.value) {
        const selectedItem = categoryDropdown.querySelector('.category-item[data-id="' + categoryId.value + '"]');
        if (selectedItem) {
            updateDynamicFields(selectedItem.dataset.name);
        }
    }
    
    // Initialize branch rows visibility based on stock control and selected branches
    const stockControl = document.getElementById('stockControl');
    if (stockControl && stockControl.checked) {
        toggleStockControl();
    }
});
</script>


<?php require_once APP_PATH . '/includes/footer.php'; ?>
