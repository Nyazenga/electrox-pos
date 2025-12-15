<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('tradeins.edit');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    redirectTo('modules/tradeins/index.php');
}

$pageTitle = 'Edit Trade-In';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Load existing trade-in
$tradein = $db->getRow("SELECT t.*, p.brand as new_product_brand, p.model as new_product_model, p.product_code as new_product_code, p.selling_price as new_product_price
                        FROM trade_ins t 
                        LEFT JOIN products p ON t.new_product_id = p.id
                        WHERE t.id = :id", [':id' => $id]);

if (!$tradein) {
    redirectTo('modules/tradeins/index.php');
}

// Don't allow editing processed trade-ins
if ($tradein['status'] === 'Processed') {
    $_SESSION['error'] = 'Cannot edit a processed trade-in';
    redirectTo('modules/tradeins/view.php?id=' . $id);
}

// Extract and parse product details JSON from valuation_notes
$productDetails = [];
$valuationNotes = $tradein['valuation_notes'] ?? '';

if (!empty($valuationNotes) && strpos($valuationNotes, 'PRODUCT_DETAILS_JSON:') !== false) {
    $parts = explode('PRODUCT_DETAILS_JSON:', $valuationNotes);
    $valuationNotes = trim($parts[0]);
    
    if (isset($parts[1])) {
        $jsonPart = trim($parts[1]);
        $productDetails = json_decode($jsonPart, true) ?? [];
    }
}

$customers = $db->getRows("SELECT * FROM customers WHERE status = 'Active' ORDER BY first_name, last_name");
$products = $db->getRows("SELECT * FROM products WHERE status = 'Active' ORDER BY brand, model");
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");

// Get category required fields for dynamic form
$categoryFields = [];
foreach ($categories as $cat) {
    $requiredFields = json_decode($cat['required_fields'] ?? '[]', true);
    $categoryFields[$cat['name']] = is_array($requiredFields) ? $requiredFields : [];
}

require_once APP_PATH . '/includes/header.php';
?>

<style>
#newProductDropdown {
    max-height: 300px;
    overflow-y: auto;
    z-index: 1050;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

#newProductDropdown .dropdown-item {
    padding: 10px 15px;
    cursor: pointer;
    white-space: normal;
    word-wrap: break-word;
}

#newProductDropdown .dropdown-item:hover {
    background-color: #f8f9fa;
}

#newProductDropdown .dropdown-item:active {
    background-color: var(--primary-blue);
    color: white;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Trade-In</h2>
    <div>
        <a href="view.php?id=<?= $tradein['id'] ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<form method="POST" id="tradeInForm" class="row">
    <input type="hidden" name="trade_in_id" value="<?= $tradein['id'] ?>">
    
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Customer & Device Information</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Customer</label>
                    <select class="form-select" name="customer_id">
                        <option value="">Walk-in Customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= $customer['id'] ?>" <?= $tradein['customer_id'] == $customer['id'] ? 'selected' : '' ?>>
                                <?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Device Category *</label>
                    <select class="form-select" name="device_category" id="deviceCategory" required onchange="updateDynamicFields()">
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= escapeHtml($category['name']) ?>" 
                                    data-fields="<?= escapeHtml(json_encode($categoryFields[$category['name']] ?? [])) ?>"
                                    <?= $tradein['device_category'] == $category['name'] ? 'selected' : '' ?>>
                                <?= escapeHtml($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Device Brand *</label>
                    <input type="text" class="form-control" name="device_brand" value="<?= escapeHtml($tradein['device_brand']) ?>" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Device Model *</label>
                    <input type="text" class="form-control" name="device_model" value="<?= escapeHtml($tradein['device_model']) ?>" required>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3" id="colorField" style="display: none;">
                        <label class="form-label">Color</label>
                        <input type="text" class="form-control" name="device_color" value="<?= escapeHtml($tradein['device_color'] ?? ($productDetails['device_color'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6 mb-3" id="storageField" style="display: none;">
                        <label class="form-label">Storage</label>
                        <input type="text" class="form-control" name="device_storage" value="<?= escapeHtml($tradein['device_storage'] ?? ($productDetails['device_storage'] ?? '')) ?>">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Device Condition *</label>
                    <select class="form-select" name="device_condition" required>
                        <option value="A+" <?= $tradein['device_condition'] == 'A+' ? 'selected' : '' ?>>A+ (Excellent)</option>
                        <option value="A" <?= $tradein['device_condition'] == 'A' ? 'selected' : '' ?>>A (Very Good)</option>
                        <option value="B" <?= ($tradein['device_condition'] == 'B' || !$tradein['device_condition']) ? 'selected' : '' ?>>B (Good)</option>
                        <option value="C" <?= $tradein['device_condition'] == 'C' ? 'selected' : '' ?>>C (Fair)</option>
                    </select>
                </div>
                
                <div class="mb-3" id="batteryHealthField" style="display: none;">
                    <label class="form-label">Battery Health (%)</label>
                    <input type="number" class="form-control" name="battery_health" min="0" max="100" value="<?= escapeHtml($tradein['battery_health'] ?? ($productDetails['battery_health'] ?? '')) ?>">
                </div>
                
                <div class="mb-3" id="serialNumberField" style="display: none;">
                    <label class="form-label">Serial Number</label>
                    <input type="text" class="form-control" name="serial_number" value="<?= escapeHtml($productDetails['serial_number'] ?? '') ?>">
                </div>
                
                <div class="mb-3" id="imeiField" style="display: none;">
                    <label class="form-label">IMEI</label>
                    <input type="text" class="form-control" name="imei" maxlength="15" value="<?= escapeHtml($productDetails['imei'] ?? '') ?>">
                </div>
                
                <div class="mb-3" id="simConfigField" style="display: none;">
                    <label class="form-label">SIM Configuration</label>
                    <select class="form-select" name="sim_configuration">
                        <option value="">Select</option>
                        <option value="Single SIM" <?= ($productDetails['sim_configuration'] ?? '') == 'Single SIM' ? 'selected' : '' ?>>Single SIM</option>
                        <option value="Dual SIM" <?= ($productDetails['sim_configuration'] ?? '') == 'Dual SIM' ? 'selected' : '' ?>>Dual SIM</option>
                        <option value="eSIM" <?= ($productDetails['sim_configuration'] ?? '') == 'eSIM' ? 'selected' : '' ?>>eSIM</option>
                        <option value="Dual SIM + eSIM" <?= ($productDetails['sim_configuration'] ?? '') == 'Dual SIM + eSIM' ? 'selected' : '' ?>>Dual SIM + eSIM</option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Product Details (For Stock Entry)</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Cost Price *</label>
                        <input type="number" class="form-control" name="cost_price" step="0.01" min="0" value="<?= escapeHtml($productDetails['cost_price'] ?? 0) ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Selling Price *</label>
                        <input type="number" class="form-control" name="selling_price" step="0.01" min="0" value="<?= escapeHtml($productDetails['selling_price'] ?? 0) ?>" required>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="3" placeholder="Product description..."><?= escapeHtml($productDetails['description'] ?? '') ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Specifications</label>
                    <textarea class="form-control" name="specifications" rows="3" placeholder="Technical specifications..."><?= escapeHtml($productDetails['specifications'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
        
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Device Assessment</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Cosmetic Issues</label>
                    <textarea class="form-control" name="cosmetic_issues" rows="3"><?= escapeHtml($tradein['cosmetic_issues'] ?? ($productDetails['cosmetic_issues'] ?? '')) ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Functional Issues</label>
                    <textarea class="form-control" name="functional_issues" rows="3"><?= escapeHtml($tradein['functional_issues'] ?? ($productDetails['functional_issues'] ?? '')) ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Accessories Included</label>
                    <textarea class="form-control" name="accessories_included" rows="2"><?= escapeHtml($tradein['accessories_included'] ?? ($productDetails['accessories_included'] ?? '')) ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Date of First Use</label>
                    <input type="date" class="form-control" name="date_of_first_use" value="<?= escapeHtml($tradein['date_of_first_use'] ?? ($productDetails['date_of_first_use'] ?? '')) ?>">
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">New Product Selection</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Product They're Getting</label>
                    <div class="position-relative">
                        <input type="text" 
                               class="form-control" 
                               id="newProductSearch" 
                               placeholder="Type to search products..."
                               autocomplete="off"
                               value="<?= $tradein['new_product_brand'] ? escapeHtml($tradein['new_product_brand'] . ' ' . $tradein['new_product_model'] . ' - ' . formatCurrency($tradein['new_product_price'])) : '' ?>">
                        <input type="hidden" name="new_product_id" id="newProductId" value="<?= escapeHtml($tradein['new_product_id'] ?? '') ?>">
                        <div class="dropdown-menu position-absolute w-100" id="newProductDropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                            <?php foreach ($products as $product): ?>
                                <a class="dropdown-item new-product-item" 
                                   href="#" 
                                   data-id="<?= $product['id'] ?>"
                                   data-price="<?= $product['selling_price'] ?>"
                                   data-text="<?= escapeHtml($product['brand'] . ' ' . $product['model'] . ' - ' . formatCurrency($product['selling_price'])) ?>">
                                    <?= escapeHtml($product['brand'] . ' ' . $product['model'] . ' - ' . formatCurrency($product['selling_price'])) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div id="productDetails" style="display: <?= $tradein['new_product_id'] ? 'block' : 'none' ?>;">
                    <div class="alert alert-info">
                        <strong>Product Price:</strong> <span id="productPrice">$<?= $tradein['new_product_price'] ? number_format($tradein['new_product_price'], 2) : '0.00' ?></span><br>
                        <strong>Trade-In Value:</strong> <span id="tradeInValue">$<?= number_format($tradein['final_valuation'] ?? 0, 2) ?></span><br>
                        <strong>Balance to Pay:</strong> <span id="balanceToPay" class="fw-bold">$<?= number_format(max(0, ($tradein['new_product_price'] ?? 0) - ($tradein['final_valuation'] ?? 0)), 2) ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Valuation</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Manual Valuation</label>
                    <input type="number" class="form-control" name="manual_valuation" id="manualValuation" step="0.01" min="0" value="<?= escapeHtml($tradein['manual_valuation'] ?? 0) ?>">
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Final Valuation *</label>
                    <input type="number" class="form-control" name="final_valuation" id="finalValuation" step="0.01" min="0" value="<?= escapeHtml($tradein['final_valuation'] ?? 0) ?>" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Valuation Notes</label>
                    <textarea class="form-control" name="valuation_notes" rows="3"><?= escapeHtml($valuationNotes) ?></textarea>
                </div>
            </div>
        </div>
        
        <div class="d-grid">
            <button type="button" class="btn btn-primary btn-lg" onclick="updateTradeIn()">
                <i class="bi bi-check-circle"></i> Update Trade-In
            </button>
            <a href="view.php?id=<?= $tradein['id'] ?>" class="btn btn-secondary mt-2">Cancel</a>
        </div>
    </div>
</form>

<script>
// Category-based dynamic fields
function updateDynamicFields() {
    const categorySelect = document.getElementById('deviceCategory');
    if (!categorySelect) return;
    
    const selectedOption = categorySelect.options[categorySelect.selectedIndex];
    const categoryName = selectedOption.value.toLowerCase();
    
    // Get all dynamic fields
    const colorField = document.getElementById('colorField');
    const storageField = document.getElementById('storageField');
    const batteryHealthField = document.getElementById('batteryHealthField');
    const serialNumberField = document.getElementById('serialNumberField');
    const imeiField = document.getElementById('imeiField');
    const simConfigField = document.getElementById('simConfigField');
    
    // Hide all fields first
    if (colorField) colorField.style.display = 'none';
    if (storageField) storageField.style.display = 'none';
    if (batteryHealthField) batteryHealthField.style.display = 'none';
    if (serialNumberField) serialNumberField.style.display = 'none';
    if (imeiField) imeiField.style.display = 'none';
    if (simConfigField) simConfigField.style.display = 'none';
    
    // Show fields based on category
    if (categoryName.includes('smartphone') || categoryName.includes('phone')) {
        if (colorField) colorField.style.display = 'block';
        if (storageField) storageField.style.display = 'block';
        if (batteryHealthField) batteryHealthField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
        if (imeiField) imeiField.style.display = 'block';
        if (simConfigField) simConfigField.style.display = 'block';
    } else if (categoryName.includes('laptop')) {
        if (colorField) colorField.style.display = 'block';
        if (storageField) storageField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
    } else if (categoryName.includes('tablet')) {
        if (colorField) colorField.style.display = 'block';
        if (storageField) storageField.style.display = 'block';
        if (batteryHealthField) batteryHealthField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
    } else if (categoryName.includes('audio') || categoryName.includes('wearable')) {
        if (colorField) colorField.style.display = 'block';
        if (categoryName.includes('wearable') && batteryHealthField) {
            batteryHealthField.style.display = 'block';
        }
    }
}

// Initialize dynamic fields on page load
document.addEventListener('DOMContentLoaded', function() {
    updateDynamicFields();
});

// Searchable product dropdown functionality
const newProductSearch = document.getElementById('newProductSearch');
const newProductDropdown = document.getElementById('newProductDropdown');
const newProductId = document.getElementById('newProductId');
let blurTimeout;

if (newProductSearch && newProductDropdown) {
    newProductSearch.addEventListener('input', function() {
        filterNewProducts(this.value);
    });
    
    newProductSearch.addEventListener('focus', function() {
        clearTimeout(blurTimeout);
        showNewProductDropdown();
    });
    
    newProductSearch.addEventListener('blur', function() {
        blurTimeout = setTimeout(() => {
            hideNewProductDropdown();
        }, 300);
    });
    
    newProductDropdown.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const item = e.target.closest('.new-product-item');
        if (item) {
            clearTimeout(blurTimeout);
            
            const productId = item.dataset.id;
            const productText = item.dataset.text;
            const productPrice = parseFloat(item.dataset.price) || 0;
            
            newProductSearch.value = productText;
            newProductId.value = productId;
            
            hideNewProductDropdown();
            updateProductDetails(productPrice);
        }
    });
    
    newProductDropdown.addEventListener('mousedown', function(e) {
        e.preventDefault();
    });
}

function filterNewProducts(searchTerm) {
    if (!newProductDropdown) return;
    
    const items = newProductDropdown.querySelectorAll('.new-product-item');
    const term = searchTerm.toLowerCase().trim();
    
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(term)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

function showNewProductDropdown() {
    if (!newProductDropdown || !newProductSearch) return;
    
    if (newProductSearch.value.trim() === '') {
        newProductDropdown.querySelectorAll('.new-product-item').forEach(item => {
            item.style.display = 'block';
        });
    }
    newProductDropdown.style.display = 'block';
}

function hideNewProductDropdown() {
    if (newProductDropdown) {
        newProductDropdown.style.display = 'none';
    }
}

function updateProductDetails(productPrice) {
    const productDetails = document.getElementById('productDetails');
    const finalValuation = parseFloat(document.getElementById('finalValuation').value) || 0;
    
    if (productPrice > 0) {
        productDetails.style.display = 'block';
        document.getElementById('productPrice').textContent = '$' + productPrice.toFixed(2);
        document.getElementById('tradeInValue').textContent = '$' + finalValuation.toFixed(2);
        document.getElementById('balanceToPay').textContent = '$' + Math.max(0, productPrice - finalValuation).toFixed(2);
    } else {
        productDetails.style.display = 'none';
    }
}

document.getElementById('finalValuation').addEventListener('input', function() {
    const productId = newProductId.value;
    if (productId) {
        const selectedProduct = Array.from(document.querySelectorAll('.new-product-item')).find(item => 
            item.dataset.id === productId
        );
        if (selectedProduct) {
            const productPrice = parseFloat(selectedProduct.dataset.price) || 0;
            updateProductDetails(productPrice);
        }
    }
});

// Update trade-in function (AJAX submission)
function updateTradeIn() {
    const form = document.getElementById('tradeInForm');
    if (!form) {
        Swal.fire('Error', 'Trade-in form not found', 'error');
        return;
    }
    
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    
    // Convert empty strings to null for optional fields
    if (data.new_product_id === '') data.new_product_id = null;
    if (data.customer_id === '') data.customer_id = null;
    
    // Convert numeric strings to numbers
    if (data.cost_price) data.cost_price = parseFloat(data.cost_price) || 0;
    if (data.selling_price) data.selling_price = parseFloat(data.selling_price) || 0;
    if (data.final_valuation) data.final_valuation = parseFloat(data.final_valuation) || 0;
    if (data.manual_valuation) data.manual_valuation = parseFloat(data.manual_valuation) || 0;
    if (data.battery_health) data.battery_health = parseInt(data.battery_health) || null;
    
    // Validation
    if (!data.device_brand || !data.device_model || !data.final_valuation || !data.device_category) {
        Swal.fire('Error', 'Please fill in all required fields (Category, Brand, Model, Final Valuation)', 'error');
        return;
    }
    
    if (!data.cost_price || !data.selling_price) {
        Swal.fire('Error', 'Please enter Cost Price and Selling Price for stock entry', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Updating Trade-In...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Update trade-in via AJAX
    fetch('<?= BASE_URL ?>ajax/update_tradein.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => {
        if (!r.ok) {
            throw new Error('Network response was not ok');
        }
        return r.json();
    })
    .then(result => {
        console.log('Update trade-in response:', result);
        if (result && result.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: 'Trade-in updated successfully.',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                window.location.href = 'view.php?id=<?= $tradein['id'] ?>';
            });
        } else {
            console.error('Update trade-in error:', result);
            const errorMsg = (result && result.message) ? result.message : 'Failed to update trade-in. Please check the console for details.';
            Swal.fire('Error', errorMsg, 'error');
        }
    })
    .catch(error => {
        console.error('Trade-in update error:', error);
        Swal.fire('Error', 'Failed to update trade-in: ' + error.message, 'error');
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

