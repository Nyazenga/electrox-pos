<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('tradeins.create');

$pageTitle = 'New Trade-In';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

$customers = $db->getRows("SELECT * FROM customers WHERE status = 'Active' ORDER BY first_name, last_name");
$products = $db->getRows("SELECT * FROM products WHERE status = 'Active' ORDER BY brand, model");
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");

// Get category required fields for dynamic form
$categoryFields = [];
foreach ($categories as $cat) {
    $requiredFields = json_decode($cat['required_fields'] ?? '[]', true);
    $categoryFields[$cat['name']] = is_array($requiredFields) ? $requiredFields : [];
}

// Form is now submitted via AJAX, so we don't process POST here

require_once APP_PATH . '/includes/header.php';
?>

<style>
#newProductDropdown,
#customerDropdown {
    max-height: 300px;
    overflow-y: auto;
    z-index: 1050;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

#newProductDropdown .dropdown-item,
#customerDropdown .dropdown-item {
    padding: 10px 15px;
    cursor: pointer;
    white-space: normal;
    word-wrap: break-word;
}

#newProductDropdown .dropdown-item:hover,
#customerDropdown .dropdown-item:hover {
    background-color: #f8f9fa;
}

#newProductDropdown .dropdown-item:active,
#customerDropdown .dropdown-item:active {
    background-color: var(--primary-blue);
    color: white;
}
</style>

<div class="card">
    <div class="card-header">
        <h4 class="mb-0">New Trade-In</h4>
    </div>
    <div class="card-body">
        <form method="POST" id="tradeInForm">
            <!-- Basic Information -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <label class="form-label">Customer</label>
                    <div class="position-relative">
                        <input type="text" 
                               class="form-control form-control-sm" 
                               id="customerSearch" 
                               placeholder="Search customers..."
                               autocomplete="off"
                               value="">
                        <input type="hidden" name="customer_id" id="customerId" value="">
                        <div class="dropdown-menu position-absolute w-100" id="customerDropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                            <a class="dropdown-item customer-item" href="#" data-id="" data-text="Walk-in Customer">Walk-in Customer</a>
                            <?php foreach ($customers as $customer): ?>
                                <a class="dropdown-item customer-item" href="#" data-id="<?= $customer['id'] ?>" data-text="<?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?>">
                                    <?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Device Category *</label>
                    <div class="position-relative">
                        <input type="text" class="form-control form-control-sm" id="deviceCategorySearch" placeholder="Search categories..." autocomplete="off" required>
                        <input type="hidden" name="device_category" id="deviceCategory" required>
                        <div class="dropdown-menu position-absolute w-100" id="deviceCategoryDropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                            <?php foreach ($categories as $category): ?>
                                <a class="dropdown-item category-item" href="#" data-value="<?= escapeHtml($category['name']) ?>" data-text="<?= escapeHtml($category['name']) ?>" data-fields="<?= escapeHtml(json_encode($categoryFields[$category['name']] ?? [])) ?>">
                                    <?= escapeHtml($category['name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Device Condition *</label>
                    <select class="form-select form-select-sm" name="device_condition" required>
                        <option value="A+">A+ (Excellent)</option>
                        <option value="A">A (Very Good)</option>
                        <option value="B" selected>B (Good)</option>
                        <option value="C">C (Fair)</option>
                    </select>
                </div>
            </div>
            
            <!-- Device Details -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <label class="form-label">Brand *</label>
                    <input type="text" class="form-control form-control-sm" name="device_brand" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Model *</label>
                    <input type="text" class="form-control form-control-sm" name="device_model" required>
                </div>
                <div class="col-md-3 mb-3" id="colorField" style="display: none;">
                    <label class="form-label">Color</label>
                    <input type="text" class="form-control form-control-sm" name="device_color">
                </div>
                <div class="col-md-3 mb-3" id="storageField" style="display: none;">
                    <label class="form-label">Storage</label>
                    <input type="text" class="form-control form-control-sm" name="device_storage">
                </div>
                <div class="col-md-3 mb-3" id="batteryHealthField" style="display: none;">
                    <label class="form-label">Battery Health (%)</label>
                    <input type="number" class="form-control form-control-sm" name="battery_health" min="0" max="100">
                </div>
                <div class="col-md-3 mb-3" id="serialNumberField" style="display: none;">
                    <label class="form-label">Serial Number</label>
                    <input type="text" class="form-control form-control-sm" name="serial_number">
                </div>
                <div class="col-md-3 mb-3" id="imeiField" style="display: none;">
                    <label class="form-label">IMEI</label>
                    <input type="text" class="form-control form-control-sm" name="imei" maxlength="15">
                </div>
                <div class="col-md-3 mb-3" id="simConfigField" style="display: none;">
                    <label class="form-label">SIM Config</label>
                    <select class="form-select form-select-sm" name="sim_configuration">
                        <option value="">Select</option>
                        <option value="Single SIM">Single SIM</option>
                        <option value="Dual SIM">Dual SIM</option>
                        <option value="eSIM">eSIM</option>
                        <option value="Dual SIM + eSIM">Dual SIM + eSIM</option>
                    </select>
                </div>
            </div>
            
            <!-- Pricing & Stock -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <label class="form-label">Cost Price *</label>
                    <input type="number" class="form-control form-control-sm" name="cost_price" step="0.01" min="0" value="0" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Selling Price *</label>
                    <input type="number" class="form-control form-control-sm" name="selling_price" step="0.01" min="0" value="0" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Manual Valuation</label>
                    <input type="number" class="form-control form-control-sm" name="manual_valuation" id="manualValuation" step="0.01" min="0" value="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Final Valuation *</label>
                    <input type="number" class="form-control form-control-sm" name="final_valuation" id="finalValuation" step="0.01" min="0" required>
                </div>
            </div>
            
            <!-- Product Selection -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <label class="form-label">Product They're Getting</label>
                    <div class="position-relative">
                        <input type="text" class="form-control form-control-sm" id="newProductSearch" placeholder="Search products..." autocomplete="off">
                        <input type="hidden" name="new_product_id" id="newProductId">
                        <div class="dropdown-menu position-absolute w-100" id="newProductDropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                            <?php foreach ($products as $product): ?>
                                <a class="dropdown-item new-product-item" href="#" data-id="<?= $product['id'] ?>" data-price="<?= $product['selling_price'] ?>" data-text="<?= escapeHtml($product['brand'] . ' ' . $product['model'] . ' - ' . formatCurrency($product['selling_price'])) ?>">
                                    <?= escapeHtml($product['brand'] . ' ' . $product['model'] . ' - ' . formatCurrency($product['selling_price'])) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div id="productDetails" style="display: none;">
                        <div class="alert alert-info py-2 mb-0">
                            <small><strong>Price:</strong> <span id="productPrice">$0.00</span> | 
                            <strong>Trade-In:</strong> <span id="tradeInValue">$0.00</span> | 
                            <strong>Balance:</strong> <span id="balanceToPay" class="fw-bold">$0.00</span></small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Additional Details (Collapsible) -->
            <div class="accordion mb-4" id="detailsAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#productDetailsSection">
                            Product Details
                        </button>
                    </h2>
                    <div id="productDetailsSection" class="accordion-collapse collapse" data-bs-parent="#detailsAccordion">
                        <div class="accordion-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control form-control-sm" name="description" rows="2"></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Specifications</label>
                                    <textarea class="form-control form-control-sm" name="specifications" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#assessmentSection">
                            Device Assessment
                        </button>
                    </h2>
                    <div id="assessmentSection" class="accordion-collapse collapse" data-bs-parent="#detailsAccordion">
                        <div class="accordion-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Cosmetic Issues</label>
                                    <textarea class="form-control form-control-sm" name="cosmetic_issues" rows="2"></textarea>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Functional Issues</label>
                                    <textarea class="form-control form-control-sm" name="functional_issues" rows="2"></textarea>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Accessories</label>
                                    <textarea class="form-control form-control-sm" name="accessories_included" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Date of First Use</label>
                                    <input type="date" class="form-control form-control-sm" name="date_of_first_use" max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Valuation Notes</label>
                                    <textarea class="form-control form-control-sm" name="valuation_notes" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="d-flex gap-2 mt-3">
                <button type="button" class="btn btn-primary" onclick="processTradeIn()">
                    <i class="bi bi-check-circle"></i> Process Trade-In
                </button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
// Searchable customer dropdown functionality
const customerSearch = document.getElementById('customerSearch');
const customerDropdown = document.getElementById('customerDropdown');
const customerId = document.getElementById('customerId');
let customerBlurTimeout;

if (customerSearch && customerDropdown) {
    customerSearch.addEventListener('input', function() {
        filterCustomers(this.value);
    });
    
    customerSearch.addEventListener('focus', function() {
        clearTimeout(customerBlurTimeout);
        showCustomerDropdown();
    });
    
    customerSearch.addEventListener('blur', function() {
        customerBlurTimeout = setTimeout(() => {
            hideCustomerDropdown();
        }, 300);
    });
    
    customerDropdown.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const item = e.target.closest('.customer-item');
        if (item) {
            clearTimeout(customerBlurTimeout);
            
            const id = item.dataset.id;
            const text = item.dataset.text;
            
            customerSearch.value = text;
            customerId.value = id;
            
            hideCustomerDropdown();
        }
    });
    
    customerDropdown.addEventListener('mousedown', function(e) {
        e.preventDefault();
    });
}

function filterCustomers(searchTerm) {
    if (!customerDropdown) return;
    
    const items = customerDropdown.querySelectorAll('.customer-item');
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

function showCustomerDropdown() {
    if (!customerDropdown || !customerSearch) return;
    
    if (customerSearch.value.trim() === '') {
        customerDropdown.querySelectorAll('.customer-item').forEach(item => {
            item.style.display = 'block';
        });
    }
    customerDropdown.style.display = 'block';
}

function hideCustomerDropdown() {
    if (customerDropdown) {
        customerDropdown.style.display = 'none';
    }
}

// Searchable category dropdown functionality
const categorySearch = document.getElementById('deviceCategorySearch');
const categoryDropdown = document.getElementById('deviceCategoryDropdown');
const categoryHidden = document.getElementById('deviceCategory');
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
            hideCategoryDropdown();
        }, 300);
    });
    
    categoryDropdown.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const item = e.target.closest('.category-item');
        if (item) {
            clearTimeout(categoryBlurTimeout);
            
            const value = item.dataset.value;
            const text = item.dataset.text;
            
            categorySearch.value = text;
            categoryHidden.value = value;
            
            hideCategoryDropdown();
            updateDynamicFields();
        }
    });
    
    categoryDropdown.addEventListener('mousedown', function(e) {
        e.preventDefault();
    });
}

function filterCategories(searchTerm) {
    if (!categoryDropdown) return;
    
    const items = categoryDropdown.querySelectorAll('.category-item');
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

function showCategoryDropdown() {
    if (!categoryDropdown || !categorySearch) return;
    
    if (categorySearch.value.trim() === '') {
        categoryDropdown.querySelectorAll('.category-item').forEach(item => {
            item.style.display = 'block';
        });
    }
    categoryDropdown.style.display = 'block';
}

function hideCategoryDropdown() {
    if (categoryDropdown) {
        categoryDropdown.style.display = 'none';
    }
}

// Category-based dynamic fields
function updateDynamicFields() {
    const categoryInput = document.getElementById('deviceCategory');
    if (!categoryInput) return;
    
    const categoryName = categoryInput.value.toLowerCase();
    
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
        // Smartphones: Show all relevant fields
        if (colorField) colorField.style.display = 'block';
        if (storageField) storageField.style.display = 'block';
        if (batteryHealthField) batteryHealthField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
        if (imeiField) imeiField.style.display = 'block';
        if (simConfigField) simConfigField.style.display = 'block';
    } else if (categoryName.includes('laptop')) {
        // Laptops: Color, Storage, Serial Number
        if (colorField) colorField.style.display = 'block';
        if (storageField) storageField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
    } else if (categoryName.includes('tablet')) {
        // Tablets: Color, Storage, Battery Health, Serial Number
        if (colorField) colorField.style.display = 'block';
        if (storageField) storageField.style.display = 'block';
        if (batteryHealthField) batteryHealthField.style.display = 'block';
        if (serialNumberField) serialNumberField.style.display = 'block';
    } else if (categoryName.includes('audio') || categoryName.includes('wearable')) {
        // Audio Devices & Wearables: Color, Battery Health (for wearables)
        if (colorField) colorField.style.display = 'block';
        if (categoryName.includes('wearable') && batteryHealthField) {
            batteryHealthField.style.display = 'block';
        }
    } else if (categoryName.includes('charging') || categoryName.includes('adapter') || 
               categoryName.includes('gaming') || categoryName.includes('networking') || 
               categoryName.includes('accessor')) {
        // Charging Adapters, Gaming, Networking, Accessories: Minimal fields (just brand/model)
        // No additional fields shown
    }
}

// Searchable product dropdown functionality
const newProductSearch = document.getElementById('newProductSearch');
const newProductDropdown = document.getElementById('newProductDropdown');
const newProductId = document.getElementById('newProductId');
let blurTimeout;

if (newProductSearch && newProductDropdown) {
    // Handle input filtering
    newProductSearch.addEventListener('input', function() {
        filterNewProducts(this.value);
    });
    
    // Handle focus - show dropdown
    newProductSearch.addEventListener('focus', function() {
        clearTimeout(blurTimeout);
        showNewProductDropdown();
    });
    
    // Handle blur - hide dropdown after delay
    newProductSearch.addEventListener('blur', function() {
        blurTimeout = setTimeout(() => {
            hideNewProductDropdown();
        }, 300);
    });
    
    // Handle clicks on dropdown items using event delegation
    newProductDropdown.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const item = e.target.closest('.new-product-item');
        if (item) {
            clearTimeout(blurTimeout);
            
            const productId = item.dataset.id;
            const productText = item.dataset.text;
            const productPrice = parseFloat(item.dataset.price) || 0;
            
            // Set values
            newProductSearch.value = productText;
            newProductId.value = productId;
            
            // Hide dropdown
            hideNewProductDropdown();
            
            // Update product details
            updateProductDetails(productPrice);
        }
    });
    
    // Prevent dropdown from closing when clicking inside it
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
        // Show all products when focused and empty
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

// Update product details when final valuation changes
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

// Process trade-in function (AJAX submission)
function processTradeIn() {
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
        title: 'Processing Trade-In...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Create trade-in
    fetch('<?= BASE_URL ?>ajax/create_tradein.php', {
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
        console.log('Create trade-in response:', result);
        if (result.success && result.trade_in_id) {
            console.log('Trade-in created with ID:', result.trade_in_id);
            // Process the trade-in immediately
            return fetch('<?= BASE_URL ?>ajax/process_tradein.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({trade_in_id: result.trade_in_id})
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('Network response was not ok');
                }
                return r.json();
            })
            .then(processResult => {
                console.log('Process trade-in response:', processResult);
                if (processResult && processResult.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: 'Trade-in processed successfully. Device added to stock and product deducted.',
                        allowOutsideClick: false,
                        allowEscapeKey: false
                    }).then(() => {
                        window.location.href = 'index.php';
                    });
                } else {
                    console.error('Process trade-in error:', processResult);
                    const errorMsg = (processResult && processResult.message) ? processResult.message : 'Failed to process trade-in. Please check the console for details.';
                    Swal.fire('Error', errorMsg, 'error');
                }
            });
        } else {
            console.error('Create trade-in error:', result);
            Swal.fire('Error', result.message || 'Failed to create trade-in. Trade-in ID: ' + (result.trade_in_id || 'not provided'), 'error');
        }
    })
    .catch(error => {
        console.error('Trade-in error:', error);
        Swal.fire('Error', 'Failed to process trade-in: ' + error.message, 'error');
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
