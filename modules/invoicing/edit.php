<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/settings_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('invoicing.edit');

$invoiceId = intval($_GET['id'] ?? 0);
if (!$invoiceId) {
    redirectTo('modules/invoicing/index.php');
}

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Get invoice
$invoice = $db->getRow("SELECT * FROM invoices WHERE id = :id AND invoice_type = 'Proforma'", [':id' => $invoiceId]);

if (!$invoice) {
    redirectTo('modules/invoicing/index.php');
}

// Only allow editing of PENDING and OVERDUE invoices
if ($invoice['status'] === 'Paid') {
    $_SESSION['error_message'] = 'Paid invoices cannot be edited.';
    redirectTo('modules/invoicing/index.php');
}

// Get invoice items
$invoiceItems = $db->getRows("SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY id", [':id' => $invoiceId]);
if ($invoiceItems === false) {
    $invoiceItems = [];
}

// Get data
$customers = $db->getRows("SELECT * FROM customers WHERE status = 'Active' ORDER BY first_name, last_name");
if ($customers === false) $customers = [];

// Get products
$products = $db->getRows("SELECT p.*, 
                         COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as display_name,
                         pc.name as category_name,
                         p.tax_id as product_tax_id,
                         pc.tax_id as category_tax_id
                         FROM products p
                         LEFT JOIN product_categories pc ON p.category_id = pc.id
                         WHERE p.status = 'Active' 
                         ORDER BY COALESCE(p.product_name, p.brand, ''), p.model");
if ($products === false) $products = [];

$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get applicable taxes from fiscal_config
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

$pageTitle = 'Edit Proforma Invoice';

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
    <h2>Edit Proforma Invoice #<?= escapeHtml($invoice['invoice_number']) ?></h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="invoice-form-container">
    <form id="invoiceForm" method="POST">
        <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
        <input type="hidden" name="invoice_type" value="Proforma">
        
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
                               value="<?= $invoice['customer_id'] ? escapeHtml(trim(($invoice['first_name'] ?? '') . ' ' . ($invoice['last_name'] ?? ''))) : 'Walk-in Customer' ?>"
                               required>
                        <input type="hidden" name="customer_id" id="customerId" value="<?= $invoice['customer_id'] ?? '' ?>">
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
                            <option value="<?= $branch['id'] ?>" <?= $invoice['branch_id'] == $branch['id'] ? 'selected' : '' ?>>
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
                    <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d', strtotime($invoice['invoice_date'])) ?>" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Due Date *</label>
                    <input type="date" 
                           name="due_date" 
                           id="dueDate" 
                           class="form-control" 
                           value="<?= $invoice['due_date'] ? date('Y-m-d', strtotime($invoice['due_date'])) : date('Y-m-d', strtotime('+30 days')) ?>"
                           min="<?= date('Y-m-d') ?>"
                           required>
                    <small class="text-muted">Due date cannot be earlier than today</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."><?= escapeHtml($invoice['notes'] ?? '') ?></textarea>
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
                        <?php foreach ($products as $product): 
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
                           value="<?= $invoice['subtotal'] > 0 ? number_format(($invoice['discount_amount'] / $invoice['subtotal']) * 100, 2) : '0' ?>">
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
                    <textarea name="terms" class="form-control" rows="4" placeholder="Payment terms, delivery terms, etc..."><?= escapeHtml($invoice['terms'] ?? getSetting('invoice_default_terms', '')) ?></textarea>
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
            <button type="submit" id="saveInvoiceBtn" class="btn btn-primary btn-lg">
                <i class="bi bi-save"></i> Update Invoice
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

// Load existing invoice items
<?php foreach ($invoiceItems as $item): 
    $product = null;
    if ($item['product_id']) {
        foreach ($products as $p) {
            if ($p['id'] == $item['product_id']) {
                $product = $p;
                break;
            }
        }
    }
    $productName = $product ? ($product['display_name'] ?? ($product['product_name'] ?? trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? '')))) : ($item['description'] ?? 'Manual Item');
    $taxPercent = $product ? ($product['tax_percent'] ?? 0) : 0;
    $taxId = $product ? ($product['tax_id'] ?? null) : null;
?>
invoiceItems.push({
    id: itemCounter++,
    product_id: <?= $item['product_id'] ?: 'null' ?>,
    description: <?= json_encode($productName) ?>,
    quantity: <?= floatval($item['quantity']) ?>,
    unit_price: <?= floatval($item['unit_price']) ?>,
    stock: <?= $product ? intval($product['quantity_in_stock']) : 0 ?>,
    tax_percent: <?= $taxPercent ?>,
    tax_id: <?= $taxId ? json_encode($taxId) : 'null' ?>
});
<?php endforeach; ?>

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    renderItems();
    
    const dueDateInput = document.getElementById('dueDate');
    const serverToday = '<?= date('Y-m-d') ?>';
    if (dueDateInput) {
        dueDateInput.setAttribute('min', serverToday);
        dueDateInput.addEventListener('change', function() {
            if (this.value < serverToday) {
                Swal.fire('Error', 'Due date cannot be earlier than today\'s date', 'error');
                this.value = serverToday;
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
                stock: parseInt(item.dataset.stock),
                tax_percent: parseFloat(item.dataset.taxPercent || 0),
                tax_id: item.dataset.taxId || null
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
        stock: product.stock || 0,
        tax_percent: product.tax_percent || 0,
        tax_id: product.tax_id || null
    };
    invoiceItems.push(item);
    renderItems();
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
    
    // Check if prices include tax (same as POS)
    const pricesIncludeTax = <?= (getSetting('prices_include_tax', '1') == '1') ? 'true' : 'false' ?>;
    
    // Get global discount percentage
    const globalDiscountPercent = parseFloat(document.getElementById('applyDiscountAll').value) || 0;
    
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

// Form submission
document.getElementById('invoiceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (invoiceItems.length === 0) {
        Swal.fire('Error', 'Please add at least one item to the invoice', 'error');
        return;
    }
    
    const dueDateInput = document.getElementById('dueDate');
    if (dueDateInput && dueDateInput.value < '<?= date('Y-m-d') ?>') {
        Swal.fire('Error', 'Due date cannot be earlier than today\'s date', 'error');
        return;
    }
    
    const formData = new FormData(this);
    const data = {
        invoice_id: parseInt(formData.get('invoice_id')),
        invoice_type: formData.get('invoice_type'),
        customer_id: formData.get('customer_id') || null,
        branch_id: formData.get('branch_id') || null,
        invoice_date: formData.get('invoice_date'),
        due_date: formData.get('due_date') || null,
        notes: formData.get('notes') || null,
        terms: formData.get('terms') || null,
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
        title: 'Updating Invoice...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('<?= BASE_URL ?>ajax/update_invoice.php', {
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
            Swal.fire('Success', 'Invoice updated successfully', 'success').then(() => {
                window.location.href = 'index.php';
            });
        } else {
            Swal.fire('Error', result.message || 'Failed to update invoice', 'error');
        }
    })
    .catch(error => {
        console.error('Invoice update error:', error);
        Swal.fire('Error', 'Failed to update invoice: ' + error.message, 'error');
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

