<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/settings_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
// This page matches sidebar invoice creation menu items
$auth->requirePermission('invoicing.create');

$invoiceType = 'proforma'; // Only Proforma invoices are allowed
$invoiceTypeEnum = 'Proforma';

$pageTitle = ucfirst($invoiceType) . ' Invoice';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Get data
$customers = $db->getRows("SELECT * FROM customers WHERE status = 'Active' ORDER BY first_name, last_name");
if ($customers === false) $customers = [];

// Get products - handle both General category (product_name) and others (brand/model)
// IMPORTANT: Only load products if branch is selected - otherwise leave empty
// Products will be loaded dynamically when branch is selected
$products = [];
if ($branchId) {
    $products = $db->getRows("SELECT p.*, 
                             COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as display_name,
                             pc.name as category_name,
                             p.tax_id as product_tax_id,
                             pc.tax_id as category_tax_id
                             FROM products p
                             LEFT JOIN product_categories pc ON p.category_id = pc.id
                             WHERE p.status = 'Active' AND p.branch_id = :branch_id
                             ORDER BY COALESCE(p.product_name, p.brand, ''), p.model", 
                             [':branch_id' => $branchId]);
    if ($products === false) $products = [];
}

$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get terms & conditions for proforma invoices
$proformaTerms = $db->getRows("SELECT * FROM proforma_terms WHERE is_active = 1 ORDER BY title");
if ($proformaTerms === false) $proformaTerms = [];

// Get applicable taxes from fiscal_config for tax per product
$applicableTaxes = [];
$primaryDb = Database::getPrimaryInstance();
if ($branchId) {
    $fiscalConfig = $primaryDb->getRow(
        "SELECT applicable_taxes FROM fiscal_config WHERE branch_id = :branch_id LIMIT 1",
        [':branch_id' => $branchId]
    );
    if ($fiscalConfig && !empty($fiscalConfig['applicable_taxes'])) {
        $applicableTaxes = json_decode($fiscalConfig['applicable_taxes'], true);
        if (!is_array($applicableTaxes)) {
            $applicableTaxes = [];
        } else {
            // Filter out 5% Non-VAT Withholding Tax - it should NOT be fiscalized
            require_once APP_PATH . '/includes/fiscal_helper.php';
            $applicableTaxes = filterOut5PercentTax($applicableTaxes);
        }
    }
}

// Create a tax lookup map (taxID => taxPercent)
// Use both string and int keys to handle type mismatches
$taxMap = [];
foreach ($applicableTaxes as $tax) {
    if (isset($tax['taxID'])) {
        $taxId = intval($tax['taxID']); // Normalize to int
        $taxPercent = isset($tax['taxPercent']) ? floatval($tax['taxPercent']) : 0;
        // Store with both int and string keys for compatibility
        $taxMap[$taxId] = $taxPercent;
        $taxMap[(string)$taxId] = $taxPercent;
    }
}

// Get default tax rate (same as POS uses)
$defaultTaxRate = getDefaultTaxRate();

// Add tax information to products
foreach ($products as &$product) {
    $productTaxId = $product['product_tax_id'] ?? null;
    $categoryTaxId = $product['category_tax_id'] ?? null;
    
    // Priority: product tax_id > category tax_id > default tax rate
    $finalTaxId = $productTaxId ?: $categoryTaxId;
    $taxPercent = $defaultTaxRate; // Default to default tax rate (same as POS)
    
    if ($finalTaxId) {
        // Try both int and string keys
        $taxIdInt = intval($finalTaxId);
        if (isset($taxMap[$taxIdInt])) {
            $taxPercent = $taxMap[$taxIdInt];
        } elseif (isset($taxMap[(string)$taxIdInt])) {
            $taxPercent = $taxMap[(string)$taxIdInt];
        } elseif (isset($taxMap[$finalTaxId])) {
            $taxPercent = $taxMap[$finalTaxId];
        }
    }
    
    $product['tax_percent'] = $taxPercent;
    $product['tax_id'] = $finalTaxId;
}
unset($product);

require_once APP_PATH . '/includes/header.php';
?>

<style>
.invoice-form-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.product-search-dropdown {
    max-height: 300px;
    overflow-y: auto;
    z-index: 1050;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.invoice-items-table {
    margin-top: 20px;
}

.invoice-items-table th {
    background: var(--primary-blue) !important;
    color: white !important;
    font-weight: 600;
}

.invoice-items-table thead th {
    color: white !important;
    background: var(--primary-blue) !important;
}

.invoice-summary {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
}

.customer-search-dropdown {
    max-height: 300px;
    overflow-y: auto;
    z-index: 1050;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= ucfirst($invoiceType) ?> Invoice</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="invoice-form-container">
    <form id="invoiceForm" method="POST">
        <input type="hidden" name="invoice_type" value="<?= $invoiceTypeEnum ?>">
        
        <div class="row mb-4">
            <div class="col-md-6">
                <h5 class="mb-3">Customer Information</h5>
                <div class="mb-3">
                    <label class="form-label">Customer *</label>
                    <div class="position-relative">
                        <input type="text" 
                               class="form-control" 
                               id="customerSearch" 
                               placeholder="Type to search customers..."
                               autocomplete="off"
                               required>
                        <input type="hidden" name="customer_id" id="customerId">
                        <div class="dropdown-menu position-absolute w-100 customer-search-dropdown" id="customerDropdown" style="display: none;">
                            <a class="dropdown-item customer-item" href="#" data-id="" data-text="Walk-in Customer">
                                Walk-in Customer
                            </a>
                            <?php foreach ($customers as $customer): ?>
                                <a class="dropdown-item customer-item" 
                                   href="#" 
                                   data-id="<?= $customer['id'] ?>"
                                   data-text="<?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?>">
                                    <?= escapeHtml($customer['first_name'] . ' ' . $customer['last_name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Branch *</label>
                    <select name="branch_id" id="branchSelect" class="form-select" required>
                        <option value="">Select Branch</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $branchId == $branch['id'] ? 'selected' : '' ?>>
                                <?= escapeHtml($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="col-md-6">
                <h5 class="mb-3">Invoice Details</h5>
                <div class="mb-3">
                    <label class="form-label">Invoice Date *</label>
                    <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Due Date *</label>
                    <input type="date" 
                           name="due_date" 
                           id="dueDate" 
                           class="form-control" 
                           value="<?= date('Y-m-d') ?>"
                           min="<?= date('Y-m-d') ?>"
                           required>
                    <small class="text-muted">Due date cannot be earlier than today</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                </div>
            </div>
        </div>
        
        <hr class="my-4">
        
        <h5 class="mb-3">Invoice Items</h5>
        <div class="row mb-3">
            <div class="col-md-8">
                <label class="form-label">Add Product</label>
                <div class="position-relative">
                    <input type="text" 
                           class="form-control" 
                           id="productSearch" 
                           placeholder="Type to search products..."
                           autocomplete="off">
                    <div class="dropdown-menu position-absolute w-100 product-search-dropdown" id="productDropdown" style="display: none;">
                        <!-- Products will be loaded dynamically via JavaScript based on selected branch -->
                        <?php if (empty($products)): ?>
                            <div class="dropdown-item text-muted">
                                <?php if ($branchId): ?>
                                    No products available for selected branch
                                <?php else: ?>
                                    Please select a branch to view products
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($products as $product): 
                                // Use display_name which handles both General (product_name) and others (brand + model)
                                $productDisplayName = $product['display_name'] ?? ($product['product_name'] ?? trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? '')));
                                if (empty($productDisplayName)) {
                                    $productDisplayName = 'Product #' . $product['id'];
                                }
                            ?>
                                <a class="dropdown-item product-item" 
                                   href="#" 
                                   data-id="<?= $product['id'] ?>"
                                   data-name="<?= escapeHtml($productDisplayName) ?>"
                                   data-price="<?= $product['selling_price'] ?>"
                                   data-stock="<?= $product['quantity_in_stock'] ?>"
                                   data-tax-percent="<?= $product['tax_percent'] ?? 0 ?>"
                                   data-tax-id="<?= $product['tax_id'] ?? '' ?>">
                                    <?= escapeHtml($productDisplayName) ?> - 
                                    <?= formatCurrency($product['selling_price']) ?> 
                                    (Stock: <?= $product['quantity_in_stock'] ?>)
                                    <?php if (!empty($product['category_name'])): ?>
                                        <small class="text-muted"> - <?= escapeHtml($product['category_name']) ?></small>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="button" class="btn btn-primary w-100" onclick="addManualItem()">
                    <i class="bi bi-plus-circle"></i> Add Manual Item
                </button>
            </div>
        </div>
        
        <div class="mb-3">
            <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0">Apply Discount to All Items:</label>
                <div class="input-group" style="width: 200px;">
                    <input type="number" 
                           class="form-control form-control-sm" 
                           id="applyDiscountAll" 
                           step="0.01" 
                           min="0" 
                           max="100" 
                           placeholder="0.00"
                           value="">
                    <span class="input-group-text">%</span>
                    <button type="button" 
                            class="btn btn-sm btn-primary" 
                            onclick="applyDiscountToAll()">
                        Apply
                    </button>
                </div>
            </div>
        </div>
        
        <div class="table-responsive invoice-items-table">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="30%">Item Description</th>
                        <th width="10%">Qty</th>
                        <th width="15%">Unit Price</th>
                        <th width="10%">Tax %</th>
                        <th width="15%">Line Total</th>
                        <th width="5%">Action</th>
                    </tr>
                </thead>
                <tbody id="invoiceItemsBody">
                    <!-- Items will be added here -->
                </tbody>
            </table>
        </div>
        
        <div class="row">
            <div class="col-md-8">
                <div class="mb-3">
                    <label class="form-label">Terms & Conditions</label>
                    <select name="terms_id" id="termsId" class="form-select mb-2" onchange="loadTermsContent()">
                        <option value="">-- Select Terms & Conditions --</option>
                        <?php foreach ($proformaTerms as $term): ?>
                            <option value="<?= $term['id'] ?>" data-content="<?= escapeHtml($term['content']) ?>">
                                <?= escapeHtml($term['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <textarea name="terms" id="termsContent" class="form-control" rows="4" placeholder="Payment terms, delivery terms, etc..."><?= getSetting('invoice_default_terms', '') ?></textarea>
                    <small class="text-muted">Select from pre-defined terms or edit manually</small>
                </div>
                
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="banking_details_included" id="bankingDetailsIncluded" checked style="width: 3em; height: 1.5em;">
                        <label class="form-check-label" for="bankingDetailsIncluded">
                            <strong>Include Banking Details</strong>
                        </label>
                        <small class="d-block text-muted">Show banking details on the proforma invoice</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="invoice-summary">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <strong id="invoiceSubtotal">$0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Discount:</span>
                        <strong id="invoiceDiscount" class="text-warning">$0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Tax:</span>
                        <strong id="invoiceTax">$0.00</strong>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <span><strong>Total:</strong></span>
                        <strong id="invoiceTotal" style="font-size: 20px; color: var(--primary-blue);">$0.00</strong>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-4 d-flex gap-2">
            <button type="submit" id="saveInvoiceBtn" class="btn btn-primary btn-lg" disabled>
                <i class="bi bi-save"></i> Save Invoice
            </button>
            <button type="button" class="btn btn-secondary btn-lg" onclick="window.location.href='index.php'">
                Cancel
            </button>
        </div>
    </form>
</div>

<script>
let invoiceItems = [];
let itemCounter = 0;

// Store initial products for filtering
let allProducts = <?= json_encode($products) ?>;

// Function to load products by branch
function loadProductsByBranch(branchId) {
    console.log('Loading products for branch_id:', branchId);
    
    if (!branchId) {
        // Clear products if no branch selected
        console.log('No branch ID provided, clearing products');
        updateProductDropdown([]);
        return;
    }
    
    // Show loading indicator
    const productSearch = document.getElementById('productSearch');
    if (productSearch) {
        productSearch.placeholder = 'Loading products...';
        productSearch.disabled = true;
    }
    
    fetch('<?= BASE_URL ?>ajax/get_products_by_branch.php?branch_id=' + branchId)
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Products loaded response:', data);
            console.log('Response success:', data.success);
            console.log('Products count:', data.products ? data.products.length : 0);
            console.log('Branch ID:', data.branch_id);
            console.log('Branch Name:', data.branch_name);
            
            if (data.success && Array.isArray(data.products)) {
                allProducts = data.products;
                console.log('✓ Updating dropdown with', data.products.length, 'products for branch', branchId, '(' + (data.branch_name || '') + ')');
                updateProductDropdown(data.products);
                
                // Show success message if products loaded
                if (data.products.length > 0) {
                    console.log('✓ Products loaded successfully');
                } else {
                    console.log('⚠ No products found for this branch');
                }
            } else {
                console.error('✗ Failed to load products:', data.message || 'Invalid response');
                console.error('Response data:', data);
                updateProductDropdown([]);
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Error', 'Failed to load products: ' + (data.message || 'Unknown error'), 'error');
                }
            }
        })
        .catch(error => {
            console.error('Error loading products:', error);
            updateProductDropdown([]);
            if (typeof Swal !== 'undefined') {
                Swal.fire('Error', 'Failed to load products. Please try again.', 'error');
            }
        })
        .finally(() => {
            // Restore search input
            if (productSearch) {
                productSearch.placeholder = 'Type to search products...';
                productSearch.disabled = false;
            }
        });
}

// Function to update product dropdown
function updateProductDropdown(products) {
    const productDropdown = document.getElementById('productDropdown');
    if (!productDropdown) {
        console.error('✗ Product dropdown element not found!');
        return;
    }
    
    console.log('updateProductDropdown called with', products.length, 'products');
    // Clear existing content (including PHP-rendered products)
    productDropdown.innerHTML = '';
    
    if (products.length === 0) {
        const noProducts = document.createElement('div');
        noProducts.className = 'dropdown-item text-muted';
        noProducts.textContent = 'No products available for this branch';
        productDropdown.appendChild(noProducts);
        console.log('✓ Dropdown updated: No products message');
        return;
    }
    
    products.forEach(product => {
        const productDisplayName = product.display_name || 
            (product.product_name || (product.brand || '') + ' ' + (product.model || '')) || 
            'Product #' + product.id;
        
        const item = document.createElement('a');
        item.className = 'dropdown-item product-item';
        item.href = '#';
        item.setAttribute('data-id', product.id);
        item.setAttribute('data-name', productDisplayName);
        item.setAttribute('data-price', product.selling_price || 0);
        item.setAttribute('data-stock', product.quantity_in_stock || 0);
        item.setAttribute('data-tax-percent', product.tax_percent || 0);
        item.setAttribute('data-tax-id', product.tax_id || '');
        item.setAttribute('data-requires-specific-list', product.requires_specific_list || 0);
        
        item.innerHTML = productDisplayName + ' - ' + 
            (product.selling_price ? '$' + parseFloat(product.selling_price).toFixed(2) : '$0.00') + 
            ' (Stock: ' + (product.quantity_in_stock || 0) + ')' +
            (product.category_name ? ' <small class="text-muted"> - ' + product.category_name + '</small>' : '');
        
        productDropdown.appendChild(item);
    });
    
    console.log('✓ Dropdown updated with', products.length, 'product items');
    // Click handler is already attached via event delegation, no need to re-attach
}

// Initialize: Disable save button on page load
document.addEventListener('DOMContentLoaded', function() {
    const saveBtn = document.getElementById('saveInvoiceBtn');
    if (saveBtn) {
        saveBtn.disabled = true;
    }
    
    // Handle branch change
    const branchSelect = document.getElementById('branchSelect');
    if (branchSelect) {
        // Store initial branch ID
        let currentBranchId = branchSelect.value;
        
        // Load initial products if branch is already selected
        if (currentBranchId) {
            console.log('Initial branch selected:', currentBranchId);
            console.log('Initial products from PHP:', allProducts.length);
            // Products are already loaded from PHP for initial branch, just populate dropdown
            if (allProducts.length > 0) {
                console.log('Populating dropdown with initial products');
                updateProductDropdown(allProducts);
            } else {
                console.log('No initial products, loading via AJAX');
                // If no products from PHP, load via AJAX (might be a different branch selected)
                loadProductsByBranch(currentBranchId);
            }
        } else {
            // No branch selected initially, clear products
            console.log('No initial branch selected, clearing products');
            updateProductDropdown([]);
        }
        
        // Handle branch change
        branchSelect.addEventListener('change', function() {
            const newBranchId = this.value;
            const previousBranchId = currentBranchId;
            
            console.log('Branch changed from', previousBranchId, 'to', newBranchId);
            
            // If no branch selected, clear products immediately
            if (!newBranchId) {
                console.log('Branch cleared, clearing products');
                updateProductDropdown([]);
                currentBranchId = '';
                return;
            }
            
            // Clear existing items if branch changes
            if (invoiceItems.length > 0) {
                Swal.fire({
                    title: 'Branch Changed',
                    text: 'Changing branch will clear all items. Continue?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Clear Items',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        invoiceItems = [];
                        renderItems();
                        // Update current branch ID
                        currentBranchId = newBranchId;
                        // Load products for new branch AFTER clearing items
                        console.log('Loading products for new branch:', newBranchId);
                        loadProductsByBranch(newBranchId);
                    } else {
                        // Revert branch selection
                        console.log('Reverting to previous branch:', previousBranchId);
                        this.value = previousBranchId || '';
                        // Reload products for previous branch
                        if (previousBranchId) {
                            loadProductsByBranch(previousBranchId);
                        } else {
                            updateProductDropdown([]);
                        }
                    }
                });
            } else {
                // No items to clear, just load products for new branch immediately
                currentBranchId = newBranchId;
                console.log('No items to clear, loading products for new branch:', newBranchId);
                loadProductsByBranch(newBranchId);
            }
        });
    }
});

// Customer search
const customerSearch = document.getElementById('customerSearch');
const customerDropdown = document.getElementById('customerDropdown');
const customerId = document.getElementById('customerId');

if (customerSearch && customerDropdown) {
    customerSearch.addEventListener('input', function() {
        filterDropdown(this.value, customerDropdown, '.customer-item');
    });
    
    customerSearch.addEventListener('focus', function() {
        customerDropdown.style.display = 'block';
    });
    
    customerSearch.addEventListener('blur', function() {
        setTimeout(() => customerDropdown.style.display = 'none', 300);
    });
    
    customerDropdown.addEventListener('click', function(e) {
        e.preventDefault();
        const item = e.target.closest('.customer-item');
        if (item) {
            customerSearch.value = item.dataset.text;
            customerId.value = item.dataset.id;
            customerDropdown.style.display = 'none';
        }
    });
}

// Product search - moved inside DOMContentLoaded to ensure elements exist
document.addEventListener('DOMContentLoaded', function() {
    const productSearch = document.getElementById('productSearch');
    const productDropdown = document.getElementById('productDropdown');
    const branchSelect = document.getElementById('branchSelect');
    
    if (productSearch && productDropdown) {
        productSearch.addEventListener('input', function() {
            filterDropdown(this.value, productDropdown, '.product-item');
        });
        
        productSearch.addEventListener('focus', function() {
            // Check if branch is selected before showing dropdown
            if (branchSelect && !branchSelect.value) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Warning', 'Please select a branch first to view products', 'warning');
                }
                this.blur();
                return;
            }
            productDropdown.style.display = 'block';
        });
        
        productSearch.addEventListener('blur', function() {
            setTimeout(() => productDropdown.style.display = 'none', 300);
        });
        
        // Use event delegation for dynamically loaded products
        productDropdown.addEventListener('click', function(e) {
            e.preventDefault();
            const item = e.target.closest('.product-item');
            if (item) {
                const requiresSpecificList = parseInt(item.dataset.requiresSpecificList || 0) === 1;
                
                if (requiresSpecificList) {
                    // Show selection modal for products requiring specific list
                    showSpecificListSelectionModalForInvoice({
                        product_id: item.dataset.id,
                        description: item.dataset.name,
                        unit_price: parseFloat(item.dataset.price),
                        stock: parseInt(item.dataset.stock),
                        tax_percent: parseFloat(item.dataset.taxPercent || 0),
                        tax_id: item.dataset.taxId || null
                    });
                } else {
                    // Normal product - add directly
                addProductItem({
                    product_id: item.dataset.id,
                    description: item.dataset.name,
                    unit_price: parseFloat(item.dataset.price),
                    stock: parseInt(item.dataset.stock),
                    tax_percent: parseFloat(item.dataset.taxPercent || 0),
                    tax_id: item.dataset.taxId || null
                });
                }
                productSearch.value = '';
                productDropdown.style.display = 'none';
            }
        });
    }
});

function filterDropdown(searchTerm, dropdown, itemSelector) {
    const items = dropdown.querySelectorAll(itemSelector);
    const term = searchTerm.toLowerCase().trim();
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(term) ? 'block' : 'none';
    });
}

function addProductItem(product, specificListEntries = null) {
    const item = {
        id: itemCounter++,
        product_id: product.product_id || null,
        description: product.description || '',
        quantity: 1,
        unit_price: product.unit_price || 0,
        discount_percentage: 0,
        stock: product.stock || 0,
        tax_percent: product.tax_percent || 0,
        tax_id: product.tax_id || null,
        requires_specific_list: product.requires_specific_list || false,
        specific_list_entries: specificListEntries || []
    };
    invoiceItems.push(item);
    renderItems();
}

function showSpecificListSelectionModalForInvoice(product) {
    const branchId = document.getElementById('branchSelect')?.value;
    if (!branchId) {
        Swal.fire('Error', 'Please select a branch first', 'error');
        return;
    }
    
    // Check if product can be sold (qty must equal count of specific list)
    fetch('<?= BASE_URL ?>ajax/get_product_specific_list.php?product_id=' + product.product_id + '&status=available&branch_id=' + branchId)
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.specific_list || data.specific_list.length === 0) {
                Swal.fire('Error', 'No available items for this product. Please ensure product_specific_list entries exist.', 'error');
                return;
            }
            
            const entries = data.specific_list;
            if (entries.length !== product.stock) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Stock Mismatch',
                    html: `Product quantity (${product.stock}) does not match available items (${entries.length}).<br>Please ensure quantity equals the number of product_specific_list entries.`,
                    confirmButtonText: 'OK'
                });
                return;
            }
            
            // Show selection modal
            let modalHtml = `
                <div class="mb-3">
                    <h5>${escapeHtml(product.description)}</h5>
                    <p class="text-muted">Select items for invoice (${entries.length} available)</p>
                </div>
                <div style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th width="30"><input type="checkbox" id="selectAllInvoice" onchange="toggleSelectAllInvoiceItems()"></th>
                                <th>Serial</th>
                                <th>Color</th>
                                <th>Storage</th>
                                <th>IMEI</th>
                            </tr>
                        </thead>
                        <tbody id="specificListTableBodyInvoice">
            `;
            
            entries.forEach((entry, index) => {
                modalHtml += `
                    <tr>
                        <td><input type="checkbox" class="specific-item-checkbox-invoice" value="${entry.id}" data-entry='${JSON.stringify(entry)}'></td>
                        <td>${escapeHtml(entry.serial_number || 'N/A')}</td>
                        <td>${escapeHtml(entry.color || 'N/A')}</td>
                        <td>${escapeHtml(entry.storage || 'N/A')}</td>
                        <td>${escapeHtml(entry.imei || 'N/A')}</td>
                    </tr>
                `;
            });
            
            modalHtml += `
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <label>Quantity: <span id="selectedCountInvoice">0</span></label>
                    <input type="number" class="form-control" id="specificQuantityInvoice" min="1" max="${entries.length}" value="1" onchange="updateSpecificSelectionInvoice()">
                </div>
            `;
            
            Swal.fire({
                title: 'Select Items for Invoice',
                html: modalHtml,
                showCancelButton: true,
                confirmButtonText: 'Add to Invoice',
                cancelButtonText: 'Cancel',
                width: '800px',
                didOpen: () => {
                    document.querySelectorAll('.specific-item-checkbox-invoice').forEach(cb => {
                        cb.addEventListener('change', updateSpecificSelectionInvoice);
                    });
                    updateSpecificSelectionInvoice();
                },
                preConfirm: () => {
                    const selected = Array.from(document.querySelectorAll('.specific-item-checkbox-invoice:checked'))
                        .map(cb => JSON.parse(cb.dataset.entry));
                    const quantity = parseInt(document.getElementById('specificQuantityInvoice').value) || 1;
                    
                    if (selected.length !== quantity) {
                        Swal.showValidationMessage(`Please select exactly ${quantity} item(s)`);
                        return false;
                    }
                    
                    return {selected, quantity};
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    const {selected, quantity} = result.value;
                    // Add to invoice with specific list entries
                    product.requires_specific_list = true;
                    addProductItem(product, selected);
                }
            });
        })
        .catch(error => {
            console.error('Error loading specific list:', error);
            Swal.fire('Error', 'Failed to load product details', 'error');
        });
}

function toggleSelectAllInvoiceItems() {
    const selectAll = document.getElementById('selectAllInvoice');
    const checkboxes = document.querySelectorAll('.specific-item-checkbox-invoice');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    updateSpecificSelectionInvoice();
}

function updateSpecificSelectionInvoice() {
    const selected = document.querySelectorAll('.specific-item-checkbox-invoice:checked').length;
    const quantity = parseInt(document.getElementById('specificQuantityInvoice').value) || 1;
    document.getElementById('selectedCountInvoice').textContent = selected;
    
    // Auto-select/deselect based on quantity
    const checkboxes = Array.from(document.querySelectorAll('.specific-item-checkbox-invoice'));
    checkboxes.forEach((cb, index) => {
        cb.checked = index < quantity;
    });
    
    const selectAll = document.getElementById('selectAllInvoice');
    if (selectAll) {
        selectAll.checked = selected === checkboxes.length;
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function addManualItem() {
    addProductItem({
        product_id: null,
        description: '',
        unit_price: 0,
        stock: 0,
        tax_percent: 0,
        tax_id: null
    });
}

function removeItem(id) {
    invoiceItems = invoiceItems.filter(item => item.id !== id);
    renderItems();
}

function applyDiscountToAll() {
    const discountInput = document.getElementById('applyDiscountAll');
    const discountValue = parseFloat(discountInput.value) || 0;
    
    if (discountValue < 0 || discountValue > 100) {
        Swal.fire('Error', 'Discount must be between 0 and 100', 'error');
        return;
    }
    
    if (invoiceItems.length === 0) {
        Swal.fire('Info', 'Please add items to the invoice first', 'info');
        return;
    }
    
    // Just recalculate totals - discount is applied globally
    renderItems();
    
    Swal.fire('Success', `Applied ${discountValue}% discount to all items`, 'success');
}

function updateItem(id, field, value) {
    const item = invoiceItems.find(i => i.id === id);
    if (item) {
        if (field === 'quantity' || field === 'unit_price') {
            item[field] = parseFloat(value) || 0;
        } else {
            item[field] = value;
        }
        renderItems();
    }
}

// Update totals when discount changes
document.addEventListener('DOMContentLoaded', function() {
    const discountInput = document.getElementById('applyDiscountAll');
    if (discountInput) {
        discountInput.addEventListener('input', function() {
            calculateTotals();
        });
    }
});

function renderItems() {
    const tbody = document.getElementById('invoiceItemsBody');
    tbody.innerHTML = '';
    
    // Get global discount percentage
    const globalDiscountPercent = parseFloat(document.getElementById('applyDiscountAll').value) || 0;
    
    // Check if prices include tax (same as POS)
    const pricesIncludeTax = <?= (getSetting('prices_include_tax', '1') == '1') ? 'true' : 'false' ?>;
    
    invoiceItems.forEach(item => {
        const lineSubtotal = item.quantity * item.unit_price; // This is WITH tax if pricesIncludeTax (original price)
        
        // Calculate tax from original price (for item display - matching POS behavior)
        let lineTax = 0;
        let priceWithoutTax = 0;
        
        if (pricesIncludeTax) {
            // Prices include tax - EXTRACT tax from original price
            const taxPercent = item.tax_percent || 0;
            if (taxPercent > 0) {
                const taxDecimal = taxPercent / 100;
                priceWithoutTax = lineSubtotal / (1 + taxDecimal);
                lineTax = lineSubtotal - priceWithoutTax;
            } else {
                priceWithoutTax = lineSubtotal;
                lineTax = 0;
            }
        } else {
            // Prices do NOT include tax - ADD tax on top
            priceWithoutTax = lineSubtotal;
            lineTax = lineSubtotal * ((item.tax_percent || 0) / 100);
        }
        
        // Round to 2 decimal places (matching POS behavior)
        lineTax = Math.round(lineTax * 100) / 100;
        priceWithoutTax = Math.round(priceWithoutTax * 100) / 100;
        
        // Item rows show original prices (before discount) - matching POS receipt
        // The "Total (Incl VAT)" column shows the original price
        const lineTotalInclVAT = lineSubtotal; // Original price WITH tax
        
        const row = `
            <tr>
                <td>${item.id + 1}</td>
                <td>
                    <input type="text" 
                           class="form-control form-control-sm" 
                           value="${item.description}"
                           onchange="updateItem(${item.id}, 'description', this.value)"
                           required>
                </td>
                <td>
                    <input type="number" 
                           class="form-control form-control-sm" 
                           value="${item.quantity}"
                           min="1"
                           onchange="updateItem(${item.id}, 'quantity', this.value)"
                           required>
                </td>
                <td>
                    <input type="number" 
                           class="form-control form-control-sm" 
                           step="0.01"
                           value="${item.unit_price.toFixed(2)}"
                           onchange="updateItem(${item.id}, 'unit_price', this.value)"
                           required>
                </td>
                <td>
                    <small class="text-muted">${(item.tax_percent || 0).toFixed(2)}%</small>
                </td>
                <td><strong>$${lineTotalInclVAT.toFixed(2)}</strong></td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${item.id})">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        tbody.innerHTML += row;
    });
    
    // Enable/disable save button based on items
    const saveBtn = document.getElementById('saveInvoiceBtn');
    if (saveBtn) {
        saveBtn.disabled = invoiceItems.length === 0;
    }
    
    calculateTotals();
}

function calculateTotals() {
    // Check if prices include tax (same as POS)
    const pricesIncludeTax = <?= (getSetting('prices_include_tax', '1') == '1') ? 'true' : 'false' ?>;
    
    let subtotal = 0;
    let totalTax = 0;
    
    // Get global discount percentage
    const globalDiscountPercent = parseFloat(document.getElementById('applyDiscountAll').value) || 0;
    
    // Group taxes by rate (like POS receipt)
    const taxGroups = {};
    
    invoiceItems.forEach(item => {
        const lineSubtotal = item.quantity * item.unit_price; // This is WITH tax if pricesIncludeTax (original price)
        
        // Get tax percent (needed for both calculation and grouping)
        const taxPercent = item.tax_percent || 0;
        
        // Calculate tax from original price (for item display)
        let lineTax = 0;
        let priceWithoutTax = 0;
        
        if (pricesIncludeTax) {
            // Prices include tax - EXTRACT tax from original price
            if (taxPercent > 0) {
                const taxDecimal = taxPercent / 100;
                priceWithoutTax = lineSubtotal / (1 + taxDecimal);
                lineTax = lineSubtotal - priceWithoutTax;
            } else {
                priceWithoutTax = lineSubtotal;
                lineTax = 0;
            }
        } else {
            // Prices do NOT include tax - ADD tax on top
            priceWithoutTax = lineSubtotal;
            lineTax = lineSubtotal * (taxPercent / 100);
        }
        
        // Round tax to 2 decimal places (matching POS behavior)
        lineTax = Math.round(lineTax * 100) / 100;
        priceWithoutTax = Math.round(priceWithoutTax * 100) / 100;
        
        // Add to subtotal (Excl VAT) - sum of item "Total (Excl VAT)" values
        subtotal += priceWithoutTax;
        
        // Group taxes by rate
        const taxKey = taxPercent.toFixed(1);
        if (!taxGroups[taxKey]) {
            taxGroups[taxKey] = { rate: taxPercent, amount: 0 };
        }
        taxGroups[taxKey].amount += lineTax;
    });
    
    // Round tax group amounts
    Object.keys(taxGroups).forEach(key => {
        taxGroups[key].amount = Math.round(taxGroups[key].amount * 100) / 100;
    });
    
    // Sum all taxes
    totalTax = Object.values(taxGroups).reduce((sum, group) => sum + group.amount, 0);
    
    // Calculate subtotal WITH tax (sum of original prices including tax)
    let subtotalInclTax = 0;
    invoiceItems.forEach(item => {
        const lineSubtotal = item.quantity * item.unit_price; // WITH tax if pricesIncludeTax
        subtotalInclTax += lineSubtotal;
    });
    
    // Calculate total discount from subtotal INCLUDING tax (matching POS behavior)
    const totalDiscount = subtotalInclTax * (globalDiscountPercent / 100);
    
    // Total (Incl VAT) = Subtotal - Discount + Sum of Taxes (matching POS behavior)
    const total = subtotal - totalDiscount + totalTax;
    
    document.getElementById('invoiceSubtotal').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('invoiceDiscount').textContent = '$' + totalDiscount.toFixed(2);
    document.getElementById('invoiceTax').textContent = '$' + totalTax.toFixed(2);
    document.getElementById('invoiceTotal').textContent = '$' + total.toFixed(2);
}

// Validate due date
function validateDueDate() {
    const dueDateInput = document.getElementById('dueDate') || document.getElementById('validUntilDate');
    if (!dueDateInput) return true;
    
    const selectedDate = new Date(dueDateInput.value);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    selectedDate.setHours(0, 0, 0, 0);
    
    if (selectedDate < today) {
        Swal.fire('Error', 'Due date cannot be earlier than today\'s date', 'error');
        dueDateInput.focus();
        return false;
    }
    
    return true;
}

// Add validation on date change and prevent past date selection
document.addEventListener('DOMContentLoaded', function() {
    const dueDateInput = document.getElementById('dueDate');
    const validUntilInput = document.getElementById('validUntilDate');
    
    // Use server timezone to match PHP (avoid UTC timezone issues)
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const today = `${year}-${month}-${day}`;
    
    // Set min attribute to today (enforces in date picker) - use server value from PHP
    const serverToday = '<?= date('Y-m-d') ?>';
    const minDate = serverToday; // Use PHP's date to match server timezone
    
    if (dueDateInput) {
        // Ensure min is set to today
        dueDateInput.setAttribute('min', minDate);
        
        // If current value is less than today, set it to today
        if (dueDateInput.value && dueDateInput.value < minDate) {
            dueDateInput.value = minDate;
        }
        
        dueDateInput.addEventListener('change', function() {
            if (this.value < minDate) {
                Swal.fire('Error', 'Due date cannot be earlier than today\'s date', 'error');
                this.value = minDate;
            }
        });
        // Also prevent manual input of past dates
        dueDateInput.addEventListener('input', function() {
            if (this.value < minDate) {
                this.value = minDate;
            }
        });
    }
    
    if (validUntilInput) {
        // Ensure min is set to today
        validUntilInput.setAttribute('min', minDate);
        
        // If current value is less than today, set it to today
        if (validUntilInput.value && validUntilInput.value < minDate) {
            validUntilInput.value = minDate;
        }
        
        validUntilInput.addEventListener('change', function() {
            if (this.value < minDate) {
                Swal.fire('Error', 'Valid until date cannot be earlier than today\'s date', 'error');
                this.value = minDate;
            }
        });
        // Also prevent manual input of past dates
        validUntilInput.addEventListener('input', function() {
            if (this.value < minDate) {
                this.value = minDate;
            }
        });
    }
});

// Form submission
document.getElementById('invoiceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (invoiceItems.length === 0) {
        Swal.fire('Error', 'Please add at least one item to the invoice', 'error');
        return;
    }
    
    // Validate due date
    if (!validateDueDate()) {
        return;
    }
    
    // Double check button is enabled (shouldn't be needed but safety check)
    const saveBtn = document.getElementById('saveInvoiceBtn');
    if (saveBtn && saveBtn.disabled) {
        return;
    }
    
    const formData = new FormData(this);
    const data = {
        invoice_type: formData.get('invoice_type'),
        customer_id: formData.get('customer_id') || null,
        branch_id: formData.get('branch_id') || null,
        invoice_date: formData.get('invoice_date'),
        due_date: formData.get('due_date') || null,
        notes: formData.get('notes') || null,
        terms: formData.get('terms') || null,
        terms_id: formData.get('terms_id') ? parseInt(formData.get('terms_id')) : null,
        banking_details_included: formData.get('banking_details_included') === 'on' ? 1 : 0,
        items: invoiceItems.map(item => {
            // Calculate line total with global discount and tax
            const pricesIncludeTax = <?= (getSetting('prices_include_tax', '1') == '1') ? 'true' : 'false' ?>;
            const globalDiscountPercent = parseFloat(document.getElementById('applyDiscountAll').value) || 0;
            const lineSubtotal = item.quantity * item.unit_price; // WITH tax if pricesIncludeTax
            const lineDiscount = lineSubtotal * (globalDiscountPercent / 100);
            const lineNet = lineSubtotal - lineDiscount; // Discounted price (WITH tax if pricesIncludeTax)
            
            let lineTax = 0;
            let lineTotal = lineNet;
            
            if (pricesIncludeTax) {
                // Prices include tax - EXTRACT tax from discounted price
                const taxPercent = item.tax_percent || 0;
                if (taxPercent > 0) {
                    const taxDecimal = taxPercent / 100;
                    const priceWithoutTax = lineNet / (1 + taxDecimal);
                    lineTax = lineNet - priceWithoutTax;
                }
                // Line total is the discounted price (already includes tax)
                lineTotal = lineNet;
            } else {
                // Prices do NOT include tax - ADD tax on top
                lineTax = lineNet * ((item.tax_percent || 0) / 100);
                lineTotal = lineNet + lineTax;
            }
            
            return {
                product_id: item.product_id,
                description: item.description,
                quantity: item.quantity,
                unit_price: item.unit_price,
                discount_percentage: 0, // Per-item discount removed, using global discount
                tax_percent: item.tax_percent || 0,
                tax_id: item.tax_id,
                line_total: lineTotal
            };
        })
    };
    
    // Calculate totals matching POS behavior (same as calculateTotals function)
    const pricesIncludeTax = <?= (getSetting('prices_include_tax', '1') == '1') ? 'true' : 'false' ?>;
    const globalDiscountPercent = parseFloat(document.getElementById('applyDiscountAll').value) || 0;
    
    // Group taxes by rate (like POS receipt)
    const taxGroups = {};
    let subtotalExclVAT = 0; // Sum of item "Total (Excl VAT)" values
    
    data.items.forEach(item => {
        const lineSubtotal = item.quantity * item.unit_price; // WITH tax if pricesIncludeTax (original price)
        
        // Calculate tax from original price (for item display - matching POS behavior)
        let lineTax = 0;
        let priceWithoutTax = 0;
        
        if (pricesIncludeTax) {
            // Prices include tax - EXTRACT tax from original price
            const taxPercent = item.tax_percent || 0;
            if (taxPercent > 0) {
                const taxDecimal = taxPercent / 100;
                priceWithoutTax = lineSubtotal / (1 + taxDecimal);
                lineTax = lineSubtotal - priceWithoutTax;
            } else {
                priceWithoutTax = lineSubtotal;
                lineTax = 0;
            }
        } else {
            // Prices do NOT include tax - ADD tax on top
            priceWithoutTax = lineSubtotal;
            lineTax = lineSubtotal * ((item.tax_percent || 0) / 100);
        }
        
        // Round tax to 2 decimal places (matching POS behavior)
        lineTax = Math.round(lineTax * 100) / 100;
        priceWithoutTax = Math.round(priceWithoutTax * 100) / 100;
        
        // Add to subtotal (Excl VAT) - sum of item "Total (Excl VAT)" values
        subtotalExclVAT += priceWithoutTax;
        
        // Group taxes by rate
        const taxPercent = item.tax_percent || 0;
        const taxKey = taxPercent.toFixed(1);
        if (!taxGroups[taxKey]) {
            taxGroups[taxKey] = { rate: taxPercent, amount: 0 };
        }
        taxGroups[taxKey].amount += lineTax;
    });
    
    // Round tax group amounts
    Object.keys(taxGroups).forEach(key => {
        taxGroups[key].amount = Math.round(taxGroups[key].amount * 100) / 100;
    });
    
    // Sum all taxes
    const totalTax = Object.values(taxGroups).reduce((sum, group) => sum + group.amount, 0);
    
    // Calculate subtotal WITH tax (sum of original prices including tax)
    let subtotalInclTax = 0;
    data.items.forEach(item => {
        const lineSubtotal = item.quantity * item.unit_price; // WITH tax if pricesIncludeTax
        subtotalInclTax += lineSubtotal;
    });
    
    // Calculate total discount from subtotal INCLUDING tax (matching POS behavior)
    const totalDiscount = subtotalInclTax * (globalDiscountPercent / 100);
    
    // Total (Incl VAT) = Subtotal - Discount + Sum of Taxes (matching POS behavior)
    const total = subtotalExclVAT - totalDiscount + totalTax;
    
    data.subtotal = subtotalExclVAT;
    data.discount_amount = totalDiscount;
    data.tax_amount = totalTax;
    data.total_amount = total;
    
    Swal.fire({
        title: 'Saving Invoice...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('<?= BASE_URL ?>ajax/create_invoice.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data),
        credentials: 'same-origin'
    })
    .then(async r => {
        const contentType = r.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await r.text();
            console.error('Non-JSON response:', text);
            throw new Error('Server returned non-JSON response. Check console for details.');
        }
        return r.json();
    })
    .then(result => {
        if (result.success) {
            Swal.fire('Success', 'Invoice created successfully', 'success').then(() => {
                window.location.href = 'print.php?id=' + result.invoice_id;
            });
        } else {
            Swal.fire('Error', result.message || 'Failed to create invoice', 'error');
        }
    })
    .catch(error => {
        console.error('Invoice creation error:', error);
        Swal.fire('Error', 'Failed to create invoice: ' + error.message, 'error');
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

