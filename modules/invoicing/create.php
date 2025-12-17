<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('invoices.create');

$invoiceType = $_GET['type'] ?? 'proforma';
$validTypes = ['proforma', 'tax', 'quote', 'credit'];
if (!in_array(strtolower($invoiceType), $validTypes)) {
    $invoiceType = 'proforma';
}

$typeMap = [
    'proforma' => 'Proforma',
    'tax' => 'TaxInvoice',
    'quote' => 'Quote',
    'credit' => 'CreditNote'
];

$invoiceTypeEnum = $typeMap[$invoiceType];

$pageTitle = ucfirst($invoiceType) . ' Invoice';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Get data
$customers = $db->getRows("SELECT * FROM customers WHERE status = 'Active' ORDER BY first_name, last_name");
if ($customers === false) $customers = [];

// Get products - handle both General category (product_name) and others (brand/model)
$products = $db->getRows("SELECT p.*, 
                         COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as display_name,
                         pc.name as category_name
                         FROM products p
                         LEFT JOIN product_categories pc ON p.category_id = pc.id
                         WHERE p.status = 'Active' 
                         ORDER BY COALESCE(p.product_name, p.brand, ''), p.model");
if ($products === false) $products = [];

$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

$taxRate = floatval(getSetting('default_tax_rate', 15));

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
                    <label class="form-label">Branch</label>
                    <select name="branch_id" class="form-select">
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
                
                <?php if ($invoiceType !== 'quote'): ?>
                <div class="mb-3">
                    <label class="form-label">Due Date</label>
                    <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                </div>
                <?php endif; ?>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                </div>
                
                <?php if ($invoiceType === 'quote'): ?>
                <div class="mb-3">
                    <label class="form-label">Valid Until</label>
                    <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                </div>
                <?php endif; ?>
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
                               data-stock="<?= $product['quantity_in_stock'] ?>">
                                <?= escapeHtml($productDisplayName) ?> - 
                                <?= formatCurrency($product['selling_price']) ?> 
                                (Stock: <?= $product['quantity_in_stock'] ?>)
                                <?php if (!empty($product['category_name'])): ?>
                                    <small class="text-muted"> - <?= escapeHtml($product['category_name']) ?></small>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
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
                        <th width="10%">Discount %</th>
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
                    <textarea name="terms" class="form-control" rows="4" placeholder="Payment terms, delivery terms, etc..."><?= getSetting('invoice_default_terms', '') ?></textarea>
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
                        <span>Tax (<?= $taxRate ?>%):</span>
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
const taxRate = <?= $taxRate ?>;

// Initialize: Disable save button on page load
document.addEventListener('DOMContentLoaded', function() {
    const saveBtn = document.getElementById('saveInvoiceBtn');
    if (saveBtn) {
        saveBtn.disabled = true;
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

// Product search
const productSearch = document.getElementById('productSearch');
const productDropdown = document.getElementById('productDropdown');

if (productSearch && productDropdown) {
    productSearch.addEventListener('input', function() {
        filterDropdown(this.value, productDropdown, '.product-item');
    });
    
    productSearch.addEventListener('focus', function() {
        productDropdown.style.display = 'block';
    });
    
    productSearch.addEventListener('blur', function() {
        setTimeout(() => productDropdown.style.display = 'none', 300);
    });
    
    productDropdown.addEventListener('click', function(e) {
        e.preventDefault();
        const item = e.target.closest('.product-item');
        if (item) {
            addProductItem({
                product_id: item.dataset.id,
                description: item.dataset.name,
                unit_price: parseFloat(item.dataset.price),
                stock: parseInt(item.dataset.stock)
            });
            productSearch.value = '';
            productDropdown.style.display = 'none';
        }
    });
}

function filterDropdown(searchTerm, dropdown, itemSelector) {
    const items = dropdown.querySelectorAll(itemSelector);
    const term = searchTerm.toLowerCase().trim();
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(term) ? 'block' : 'none';
    });
}

function addProductItem(product) {
    const item = {
        id: itemCounter++,
        product_id: product.product_id || null,
        description: product.description || '',
        quantity: 1,
        unit_price: product.unit_price || 0,
        discount_percentage: 0,
        stock: product.stock || 0
    };
    invoiceItems.push(item);
    renderItems();
}

function addManualItem() {
    addProductItem({
        product_id: null,
        description: '',
        unit_price: 0,
        stock: 0
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
    
    invoiceItems.forEach(item => {
        item.discount_percentage = discountValue;
    });
    
    renderItems();
    
    Swal.fire('Success', `Applied ${discountValue}% discount to all items`, 'success');
}

function updateItem(id, field, value) {
    const item = invoiceItems.find(i => i.id === id);
    if (item) {
        if (field === 'quantity' || field === 'unit_price' || field === 'discount_percentage') {
            item[field] = parseFloat(value) || 0;
        } else {
            item[field] = value;
        }
        renderItems();
    }
}

function renderItems() {
    const tbody = document.getElementById('invoiceItemsBody');
    tbody.innerHTML = '';
    
    invoiceItems.forEach(item => {
        const lineTotal = (item.quantity * item.unit_price) * (1 - (item.discount_percentage / 100));
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
                    <input type="number" 
                           class="form-control form-control-sm" 
                           step="0.01"
                           value="${item.discount_percentage}"
                           min="0"
                           max="100"
                           onchange="updateItem(${item.id}, 'discount_percentage', this.value)">
                </td>
                <td><strong>$${lineTotal.toFixed(2)}</strong></td>
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
    let subtotal = 0;
    let totalDiscount = 0;
    
    invoiceItems.forEach(item => {
        const lineSubtotal = item.quantity * item.unit_price;
        const lineDiscount = lineSubtotal * (item.discount_percentage / 100);
        subtotal += lineSubtotal;
        totalDiscount += lineDiscount;
    });
    
    const netSubtotal = subtotal - totalDiscount;
    const tax = netSubtotal * (taxRate / 100);
    const total = netSubtotal + tax;
    
    document.getElementById('invoiceSubtotal').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('invoiceDiscount').textContent = '$' + totalDiscount.toFixed(2);
    document.getElementById('invoiceTax').textContent = '$' + tax.toFixed(2);
    document.getElementById('invoiceTotal').textContent = '$' + total.toFixed(2);
}

// Form submission
document.getElementById('invoiceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (invoiceItems.length === 0) {
        Swal.fire('Error', 'Please add at least one item to the invoice', 'error');
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
        items: invoiceItems.map(item => ({
            product_id: item.product_id,
            description: item.description,
            quantity: item.quantity,
            unit_price: item.unit_price,
            discount_percentage: item.discount_percentage,
            line_total: (item.quantity * item.unit_price) * (1 - (item.discount_percentage / 100))
        }))
    };
    
    // Calculate totals
    let subtotal = 0;
    let totalDiscount = 0;
    data.items.forEach(item => {
        const lineSubtotal = item.quantity * item.unit_price;
        const lineDiscount = lineSubtotal * (item.discount_percentage / 100);
        subtotal += lineSubtotal;
        totalDiscount += lineDiscount;
    });
    
    const netSubtotal = subtotal - totalDiscount;
    const tax = netSubtotal * (taxRate / 100);
    const total = netSubtotal + tax;
    
    data.subtotal = subtotal;
    data.discount_amount = totalDiscount;
    data.tax_amount = tax;
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
                window.location.href = 'view.php?id=' + result.invoice_id;
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

