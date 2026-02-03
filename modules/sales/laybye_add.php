<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('sales.laybyes.create');

$pageTitle = 'New Laybye';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    redirectTo('laybyes.php');
}

// Get branches
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// If no branch selected, use session branch or first branch
if (!$branchId && !empty($branches)) {
    $branchId = $branches[0]['id'];
}

// Get customers
$customers = $primaryDb->getRows("SELECT * FROM customers WHERE status = 'Active' ORDER BY first_name, last_name, company_name");
if ($customers === false) $customers = [];

// Get products for the current branch
$products = $db->getRows("SELECT p.*, pc.name as category_name 
                          FROM products p 
                          LEFT JOIN product_categories pc ON p.category_id = pc.id 
                          WHERE p.branch_id = :branch_id AND p.status = 'Active' 
                          ORDER BY p.product_name, p.brand, p.model", 
                          [':branch_id' => $branchId]);
if ($products === false) $products = [];

// Get base currency
$baseCurrency = getBaseCurrency($db);

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-bag-plus"></i> New Laybye</h2>
    <a href="laybyes.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Back to Laybyes
    </a>
</div>

<div class="card">
    <div class="card-body">
        <form id="laybyeForm">
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Branch *</label>
                    <select class="form-select" id="branch_id" name="branch_id" required>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= ($branchId == $branch['id']) ? 'selected' : '' ?>>
                                <?= escapeHtml($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Customer *</label>
                    <div class="position-relative">
                        <input type="text" class="form-control" id="customer_search" placeholder="Type to search customers..." autocomplete="off">
                        <input type="hidden" id="customer_id" name="customer_id">
                        <div class="dropdown-menu position-absolute w-100" id="customer_dropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                            <?php foreach ($customers as $customer): ?>
                                <?php
                                $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
                                $displayName = $customerName ?: ($customer['company_name'] ?? 'Customer #' . $customer['id']);
                                ?>
                                <a class="dropdown-item customer-item" href="#" 
                                   data-id="<?= $customer['id'] ?>" 
                                   data-name="<?= escapeHtml($displayName) ?>">
                                    <?= escapeHtml($displayName) ?>
                                    <?php if ($customer['phone']): ?>
                                        <small class="text-muted"> - <?= escapeHtml($customer['phone']) ?></small>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Payment Schedule Type *</label>
                    <select class="form-select" id="payment_schedule_type" name="payment_schedule_type" required>
                        <option value="monthly">Monthly</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
            </div>

            <div class="row mb-3" id="monthlyScheduleGroup">
                <div class="col-md-6">
                    <label class="form-label">Number of Months *</label>
                    <input type="number" class="form-control" id="payment_months" name="payment_months" value="3" min="1" max="24" required>
                </div>
            </div>

            <div class="row mb-3" id="customScheduleGroup" style="display: none;">
                <div class="col-md-12">
                    <label class="form-label">Custom Payment Schedule</label>
                    <div id="customScheduleEntries">
                        <div class="custom-schedule-entry mb-2">
                            <div class="row">
                                <div class="col-md-5">
                                    <input type="date" class="form-control custom-schedule-date" name="custom_schedule[][date]">
                                </div>
                                <div class="col-md-5">
                                    <input type="number" step="0.01" class="form-control custom-schedule-amount" name="custom_schedule[][amount]" placeholder="Amount">
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-sm btn-danger" onclick="removeScheduleEntry(this)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addScheduleEntry()">
                        <i class="bi bi-plus"></i> Add Payment Date
                    </button>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Notes</label>
                <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Optional notes about this laybye"></textarea>
            </div>

            <hr>

            <h5 class="mb-3">Items</h5>
            
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Add Product</label>
                    <a href="<?= BASE_URL ?>modules/products/add.php?return_to=laybye" target="_blank" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plus-circle"></i> Create New Product
                    </a>
                </div>
                <div class="position-relative">
                    <input type="text" class="form-control" id="product_search" placeholder="Type to search products..." autocomplete="off">
                    <input type="hidden" id="selected_product_id">
                    <div class="dropdown-menu position-absolute w-100" id="product_dropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                        <?php foreach ($products as $product): ?>
                            <?php
                            $productDisplayName = $product['product_name'] ?? trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? ''));
                            if (empty($productDisplayName)) {
                                $productDisplayName = 'Product #' . $product['id'];
                            }
                            ?>
                            <a class="dropdown-item product-item" href="#" 
                               data-id="<?= $product['id'] ?>"
                               data-name="<?= escapeHtml($productDisplayName) ?>"
                               data-price="<?= $product['selling_price'] ?? 0 ?>"
                               data-requires-specific="<?= (productRequiresSpecificList($product, $db) ? '1' : '0') ?>">
                                <strong><?= escapeHtml($productDisplayName) ?></strong>
                                <br><small class="text-muted">Price: <?= formatCurrency($product['selling_price'] ?? 0) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered" id="itemsTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="items_tbody">
                        <tr>
                            <td colspan="5" class="text-center text-muted">No items added yet</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Total:</strong></td>
                            <td><strong id="totalAmount"><?= formatCurrency(0) ?></strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Create Laybye
                </button>
                <a href="laybyes.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
let laybyeItems = [];
let itemCounter = 0;

// Customer search
const customerSearch = document.getElementById('customer_search');
const customerDropdown = document.getElementById('customer_dropdown');
const customerIdInput = document.getElementById('customer_id');

customerSearch.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const items = customerDropdown.querySelectorAll('.customer-item');
    let hasVisible = false;
    
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            item.style.display = '';
            hasVisible = true;
        } else {
            item.style.display = 'none';
        }
    });
    
    customerDropdown.style.display = hasVisible ? 'block' : 'none';
});

customerDropdown.addEventListener('click', function(e) {
    e.preventDefault();
    const item = e.target.closest('.customer-item');
    if (item) {
        const id = item.getAttribute('data-id');
        const name = item.getAttribute('data-name');
        customerIdInput.value = id;
        customerSearch.value = name;
        customerDropdown.style.display = 'none';
    }
});

// Product search
const productSearch = document.getElementById('product_search');
const productDropdown = document.getElementById('product_dropdown');
const selectedProductId = document.getElementById('selected_product_id');

productSearch.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const selectedBranchId = document.getElementById('branch_id').value;
    const items = productDropdown.querySelectorAll('.product-item');
    let hasVisible = false;
    
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        const productBranchId = item.getAttribute('data-branch-id');
        const matchesSearch = text.includes(searchTerm);
        const matchesBranch = !productBranchId || productBranchId === selectedBranchId;
        
        if (matchesSearch && matchesBranch) {
            item.style.display = '';
            hasVisible = true;
        } else {
            item.style.display = 'none';
        }
    });
    
    productDropdown.style.display = hasVisible ? 'block' : 'none';
});

// Update products when branch changes
document.getElementById('branch_id').addEventListener('change', function() {
    productSearch.value = '';
    productDropdown.style.display = 'none';
});

productDropdown.addEventListener('click', function(e) {
    e.preventDefault();
    const item = e.target.closest('.product-item');
    if (item) {
        const id = item.getAttribute('data-id');
        const name = item.getAttribute('data-name');
        const price = parseFloat(item.getAttribute('data-price'));
        const requiresSpecific = item.getAttribute('data-requires-specific') === '1';
        
        selectedProductId.value = id;
        productSearch.value = name;
        productDropdown.style.display = 'none';
        
        // Add to items
        addItemToLaybye(id, name, price, requiresSpecific);
        productSearch.value = '';
        selectedProductId.value = '';
    }
});

function addItemToLaybye(productId, productName, price, requiresSpecific) {
    const item = {
        id: itemCounter++,
        product_id: productId,
        product_name: productName,
        quantity: 1,
        unit_price: price,
        total_price: price,
        requires_specific_list: requiresSpecific
    };
    
    laybyeItems.push(item);
    renderItems();
    updateTotal();
}

function renderItems() {
    const tbody = document.getElementById('items_tbody');
    tbody.innerHTML = '';
    
    if (laybyeItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No items added yet</td></tr>';
        return;
    }
    
    laybyeItems.forEach((item, index) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(item.product_name)}</td>
            <td>
                <input type="number" class="form-control form-control-sm" value="${item.quantity}" min="1" 
                       onchange="updateItemQuantity(${index}, this.value)" style="width: 80px;">
            </td>
            <td>
                <input type="number" step="0.01" class="form-control form-control-sm" value="${item.unit_price.toFixed(2)}" 
                       onchange="updateItemPrice(${index}, this.value)" style="width: 100px;">
            </td>
            <td>${formatCurrency(item.total_price)}</td>
            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${index})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function updateItemQuantity(index, quantity) {
    if (index >= 0 && index < laybyeItems.length) {
        laybyeItems[index].quantity = parseInt(quantity) || 1;
        laybyeItems[index].total_price = laybyeItems[index].unit_price * laybyeItems[index].quantity;
        renderItems();
        updateTotal();
    }
}

function updateItemPrice(index, price) {
    if (index >= 0 && index < laybyeItems.length) {
        laybyeItems[index].unit_price = parseFloat(price) || 0;
        laybyeItems[index].total_price = laybyeItems[index].unit_price * laybyeItems[index].quantity;
        renderItems();
        updateTotal();
    }
}

function removeItem(index) {
    laybyeItems = laybyeItems.filter((item, i) => i !== index);
    renderItems();
    updateTotal();
}

function updateTotal() {
    const total = laybyeItems.reduce((sum, item) => sum + item.total_price, 0);
    document.getElementById('totalAmount').textContent = formatCurrency(total);
}

// Payment schedule type change
document.getElementById('payment_schedule_type').addEventListener('change', function() {
    const isMonthly = this.value === 'monthly';
    document.getElementById('monthlyScheduleGroup').style.display = isMonthly ? 'block' : 'none';
    document.getElementById('customScheduleGroup').style.display = isMonthly ? 'none' : 'block';
    
    if (isMonthly) {
        document.getElementById('payment_months').required = true;
        // Remove required from custom schedule fields when hidden
        document.querySelectorAll('.custom-schedule-date, .custom-schedule-amount').forEach(field => {
            field.removeAttribute('required');
        });
    } else {
        document.getElementById('payment_months').required = false;
        // Add required to custom schedule fields when visible
        document.querySelectorAll('.custom-schedule-date, .custom-schedule-amount').forEach(field => {
            field.setAttribute('required', 'required');
        });
    }
});

function addScheduleEntry() {
    const container = document.getElementById('customScheduleEntries');
    const entry = document.createElement('div');
    entry.className = 'custom-schedule-entry mb-2';
    const isCustom = document.getElementById('payment_schedule_type').value === 'custom';
    const requiredAttr = isCustom ? 'required' : '';
    entry.innerHTML = `
        <div class="row">
            <div class="col-md-5">
                <input type="date" class="form-control custom-schedule-date" name="custom_schedule[][date]" ${requiredAttr}>
            </div>
            <div class="col-md-5">
                <input type="number" step="0.01" class="form-control custom-schedule-amount" name="custom_schedule[][amount]" placeholder="Amount" ${requiredAttr}>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-sm btn-danger" onclick="removeScheduleEntry(this)">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
    container.appendChild(entry);
}

function removeScheduleEntry(button) {
    button.closest('.custom-schedule-entry').remove();
}

// Form submission
document.getElementById('laybyeForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Remove required attribute from hidden custom schedule fields to prevent validation errors
    const paymentScheduleType = document.getElementById('payment_schedule_type').value;
    if (paymentScheduleType === 'monthly') {
        document.querySelectorAll('.custom-schedule-date, .custom-schedule-amount').forEach(field => {
            field.removeAttribute('required');
        });
    }
    
    const customerId = customerIdInput.value;
    if (!customerId) {
        Swal.fire('Error', 'Please select a customer', 'error');
        return;
    }
    
    if (laybyeItems.length === 0) {
        Swal.fire('Error', 'Please add at least one item', 'error');
        return;
    }
    let paymentScheduleData = null;
    let paymentMonths = null;
    let customSchedule = [];
    
    if (paymentScheduleType === 'monthly') {
        paymentMonths = parseInt(document.getElementById('payment_months').value);
        if (!paymentMonths || paymentMonths < 1) {
            Swal.fire('Error', 'Please enter a valid number of months', 'error');
            return;
        }
    } else {
        const entries = document.querySelectorAll('#customScheduleEntries .custom-schedule-entry');
        entries.forEach(entry => {
            const dateInput = entry.querySelector('.custom-schedule-date');
            const amountInput = entry.querySelector('.custom-schedule-amount');
            if (dateInput && amountInput) {
                const date = dateInput.value;
                const amount = parseFloat(amountInput.value);
                if (date && amount && amount > 0) {
                    customSchedule.push({ date, amount });
                }
            }
        });
        
        if (customSchedule.length === 0) {
            Swal.fire('Error', 'Please add at least one payment date with a valid amount', 'error');
            return;
        }
    }
    
    Swal.fire({
        title: 'Creating Laybye...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    const formData = {
        branch_id: parseInt(document.getElementById('branch_id').value),
        customer_id: customerId,
        items: laybyeItems.map(item => ({
            product_id: item.product_id,
            quantity: item.quantity,
            unit_price: item.unit_price
        })),
        payment_schedule_type: paymentScheduleType,
        payment_months: paymentMonths,
        custom_schedule: customSchedule.length > 0 ? customSchedule : null,
        notes: document.getElementById('notes').value
    };
    
    fetch('<?= BASE_URL ?>ajax/create_laybye.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(formData)
    })
    .then(async response => {
        const text = await response.text();
        console.log('Raw response:', text);
        
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Response text:', text);
            throw new Error('Server returned invalid JSON. Response: ' + text.substring(0, 200));
        }
        
        if (!response.ok) {
            throw new Error(data.message || 'Server error: ' + response.status);
        }
        
        return data;
    })
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: 'Success!',
                text: 'Laybye created successfully',
                icon: 'success',
                confirmButtonText: 'OK'
            }).then(() => {
                window.location.href = 'laybyes.php';
            });
        } else {
            console.error('Server returned error:', data);
            Swal.fire({
                title: 'Error',
                html: '<div style="text-align: left;"><strong>Error creating laybye:</strong><br><br>' + 
                      escapeHtml(data.message || 'Unknown error') + 
                      (data.error_type ? '<br><br><small>Error Type: ' + escapeHtml(data.error_type) + '</small>' : '') +
                      (data.file ? '<br><small>File: ' + escapeHtml(data.file) + (data.line ? ':' + data.line : '') + '</small>' : '') +
                      '</div>',
                icon: 'error',
                confirmButtonText: 'OK',
                width: '600px'
            });
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        console.error('Error stack:', error.stack);
        Swal.fire({
            title: 'Error',
            html: '<div style="text-align: left;"><strong>An error occurred while creating laybye:</strong><br><br>' + 
                  escapeHtml(error.message) + 
                  '<br><br><small>Check browser console (F12) for more details.</small></div>',
            icon: 'error',
            confirmButtonText: 'OK',
            width: '600px'
        });
    });
});

function formatCurrency(amount) {
    return '<?= $baseCurrency['symbol'] ?? '$' ?>' + parseFloat(amount).toFixed(2);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
