<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.create');

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

// Check if editing existing GRN
$grnId = isset($_GET['id']) ? intval($_GET['id']) : null;
$isEdit = $grnId !== null;
$grn = null;
$grnItems = [];

if ($isEdit) {
    // Load existing GRN
    $grn = $db->getRow("SELECT * FROM goods_received_notes WHERE id = :id", [':id' => $grnId]);
    if (!$grn) {
        header('Location: grn.php');
        exit;
    }
    
    // Check if GRN can be edited (only Draft status)
    if ($grn['status'] !== 'Draft') {
        header('Location: grn_view.php?id=' . $grnId);
        exit;
    }
    
    // Load GRN items
    $grnItems = $db->getRows("SELECT gi.*, 
                              COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name
                              FROM grn_items gi
                              LEFT JOIN products p ON gi.product_id = p.id
                              WHERE gi.grn_id = :id", [':id' => $grnId]);
    if ($grnItems === false) $grnItems = [];
    
    $pageTitle = 'Edit Goods Received Note (GRN)';
} else {
    $pageTitle = 'New Goods Received Note (GRN)';
}

// Get data
$suppliers = $db->getRows("SELECT * FROM suppliers WHERE status = 'Active' ORDER BY name");
if ($suppliers === false) $suppliers = [];

// Get products - handle both General category (product_name) and others (brand/model)
$products = $db->getRows("SELECT p.*, 
                         COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as display_name,
                         c.name as category_name 
                         FROM products p 
                         LEFT JOIN product_categories c ON p.category_id = c.id 
                         WHERE p.status = 'Active' 
                         ORDER BY COALESCE(p.product_name, p.brand, ''), p.model");
if ($products === false) $products = [];

$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

require_once APP_PATH . '/includes/header.php';
?>

<style>

.grn-items-table {
    margin-top: 20px;
}

.grn-items-table th {
    background-color: #f8f9fa;
    font-weight: 600;
}

.item-row {
    cursor: pointer;
}

.item-row:hover {
    background-color: #f8f9fa;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= $isEdit ? 'Edit' : 'New' ?> Goods Received Note (GRN)</h2>
    <a href="grn.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form id="grnForm">
            <?php if ($isEdit): ?>
                <input type="hidden" id="grn_id" name="grn_id" value="<?= $grnId ?>">
            <?php endif; ?>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">GRN Number *</label>
                    <input type="text" class="form-control" id="grn_number" name="grn_number" value="<?= $isEdit ? escapeHtml($grn['grn_number']) : '' ?>" <?= $isEdit ? '' : 'readonly' ?> required>
                    <small class="text-muted"><?= $isEdit ? 'GRN Number' : 'Auto-generated' ?></small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Received Date *</label>
                    <input type="date" class="form-control" name="received_date" value="<?= $isEdit ? $grn['received_date'] : date('Y-m-d') ?>" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Supplier *</label>
                    <div class="position-relative">
                        <?php 
                        $supplierName = '';
                        if ($isEdit && $grn['supplier_id']) {
                            $supplier = $db->getRow("SELECT name FROM suppliers WHERE id = :id", [':id' => $grn['supplier_id']]);
                            $supplierName = $supplier ? $supplier['name'] : '';
                        }
                        ?>
                        <input type="text" class="form-control" id="supplier_search" placeholder="Type to search suppliers..." value="<?= escapeHtml($supplierName) ?>" autocomplete="off">
                        <input type="hidden" id="supplier_id" name="supplier_id" value="<?= $isEdit ? ($grn['supplier_id'] ?? '') : '' ?>">
                        <div class="dropdown-menu position-absolute w-100" id="supplier_dropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                            <?php foreach ($suppliers as $supplier): ?>
                                <a class="dropdown-item supplier-item" href="#" data-id="<?= $supplier['id'] ?>" data-name="<?= escapeHtml($supplier['name']) ?>">
                                    <?= escapeHtml($supplier['name']) ?>
                                    <?php if ($supplier['contact_person']): ?>
                                        <small class="text-muted"> - <?= escapeHtml($supplier['contact_person']) ?></small>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Branch *</label>
                    <select class="form-select" name="branch_id" required>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= ($isEdit ? ($grn['branch_id'] == $branch['id']) : ($branchId == $branch['id'])) ? 'selected' : '' ?>>
                                <?= escapeHtml($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="3"><?= $isEdit ? escapeHtml($grn['notes'] ?? '') : '' ?></textarea>
            </div>
            
            <div class="mt-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5>Items</h5>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addItem()">
                        <i class="bi bi-plus-circle"></i> Add Item
                    </button>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered grn-items-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Cost Price</th>
                                <th>Selling Price</th>
                                <th>Total</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="items_tbody">
                            <!-- Items will be added here dynamically -->
                            <?php if ($isEdit && !empty($grnItems)): ?>
                                <!-- Pre-populate with existing items -->
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-end"><strong>Total Value:</strong></td>
                                <td><strong id="total_value">$0.00</strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="saveBtn">
                    <i class="bi bi-save"></i> <?= $isEdit ? 'Update' : 'Save' ?> GRN
                </button>
                <a href="grn.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Search Product *</label>
                    <div class="position-relative">
                        <input type="text" class="form-control" id="product_search" placeholder="Type to search products..." autocomplete="off">
                        <input type="hidden" id="selected_product_id">
                        <div class="dropdown-menu position-absolute w-100" id="product_dropdown" style="max-height: 300px; overflow-y: auto; z-index: 1050; display: none;">
                        <?php foreach ($products as $product): 
                            $productDisplayName = $product['display_name'] ?? ($product['product_name'] ?? trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? '')));
                            if (empty($productDisplayName)) {
                                $productDisplayName = 'Product #' . $product['id'];
                            }
                        ?>
                            <a class="dropdown-item product-item" href="#" 
                               data-id="<?= $product['id'] ?>"
                               data-name="<?= escapeHtml($productDisplayName) ?>"
                               data-cost="<?= $product['cost_price'] ?? 0 ?>"
                               data-selling="<?= $product['selling_price'] ?? 0 ?>">
                                <strong><?= escapeHtml($productDisplayName) ?></strong>
                                <br>
                                <small class="text-muted">
                                    <?= escapeHtml($product['category_name'] ?? 'N/A') ?> | 
                                    Code: <?= escapeHtml($product['product_code'] ?? 'N/A') ?> |
                                    Stock: <?= $product['quantity_in_stock'] ?? 0 ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Quantity *</label>
                        <input type="number" class="form-control" id="item_quantity" min="1" value="1" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Serial Numbers (Optional)</label>
                        <textarea class="form-control" id="item_serial_numbers" rows="2" placeholder="Enter serial numbers, one per line"></textarea>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Cost Price *</label>
                        <input type="number" class="form-control" id="item_cost_price" step="0.01" min="0" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Selling Price *</label>
                        <input type="number" class="form-control" id="item_selling_price" step="0.01" min="0" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmAddItem()">Add Item</button>
            </div>
        </div>
    </div>
</div>

<script>
let grnItems = [];
let itemCounter = 0;

// Generate GRN number on page load (only for new GRN)
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!$isEdit): ?>
    generateGRNNumber();
    <?php else: ?>
    // Load existing items
    const existingItems = <?= json_encode(array_map(function($item) {
        return [
            'product_id' => intval($item['product_id']),
            'product_name' => $item['product_name'] ?? '',
            'quantity' => intval($item['quantity']),
            'serial_numbers' => $item['serial_numbers'] ?? '',
            'cost_price' => floatval($item['cost_price']),
            'selling_price' => floatval($item['selling_price']),
            'total' => floatval($item['cost_price']) * intval($item['quantity'])
        ];
    }, $grnItems)) ?>;
    
    existingItems.forEach(function(item) {
        grnItems.push({
            id: itemCounter++,
            product_id: item.product_id,
            product_name: item.product_name,
            quantity: item.quantity,
            serial_numbers: item.serial_numbers,
            cost_price: item.cost_price,
            selling_price: item.selling_price,
            total: item.total
        });
    });
    
    renderItems();
    updateTotal();
    <?php endif; ?>
    
    // Supplier search
    const supplierSearch = document.getElementById('supplier_search');
    const supplierDropdown = document.getElementById('supplier_dropdown');
    const supplierIdInput = document.getElementById('supplier_id');
    
    supplierSearch.addEventListener('input', function() {
        filterDropdown(this.value, supplierDropdown, '.supplier-item');
    });
    
    supplierSearch.addEventListener('focus', function() {
        supplierDropdown.style.display = 'block';
    });
    
    supplierSearch.addEventListener('blur', function() {
        setTimeout(() => supplierDropdown.style.display = 'none', 300);
    });
    
    supplierDropdown.addEventListener('click', function(e) {
        e.preventDefault();
        const item = e.target.closest('.supplier-item');
        if (item) {
            supplierIdInput.value = item.getAttribute('data-id');
            supplierSearch.value = item.getAttribute('data-name');
            supplierDropdown.style.display = 'none';
        }
    });
    
    // Product search
    const productSearch = document.getElementById('product_search');
    const productDropdown = document.getElementById('product_dropdown');
    
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
            const id = item.getAttribute('data-id');
            const name = item.getAttribute('data-name');
            const cost = parseFloat(item.getAttribute('data-cost'));
            const selling = parseFloat(item.getAttribute('data-selling'));
            
            document.getElementById('selected_product_id').value = id;
            productSearch.value = name;
            document.getElementById('item_cost_price').value = cost.toFixed(2);
            document.getElementById('item_selling_price').value = selling.toFixed(2);
            productDropdown.style.display = 'none';
        }
    });
});

function filterDropdown(searchTerm, dropdown, itemSelector) {
    const items = dropdown.querySelectorAll(itemSelector);
    const term = searchTerm.toLowerCase().trim();
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(term) ? 'block' : 'none';
    });
}

function generateGRNNumber() {
    const date = new Date();
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const dateStr = year + month + day;
    
    // Generate with retry logic
    let attempt = 0;
    const maxAttempts = 10;
    
    function tryGenerate() {
        const sequence = String(Math.floor(Math.random() * 1000)).padStart(3, '0');
        const grnNumber = 'GRN-' + dateStr + '-' + sequence;
        
        // Check if exists
        fetch('<?= BASE_URL ?>ajax/check_grn_number.php?number=' + encodeURIComponent(grnNumber))
            .then(response => response.json())
            .then(data => {
                if (!data.exists) {
                    document.getElementById('grn_number').value = grnNumber;
                } else {
                    attempt++;
                    if (attempt < maxAttempts) {
                        tryGenerate();
                    } else {
                        // Fallback with timestamp
                        const timestamp = Date.now().toString().slice(-6);
                        document.getElementById('grn_number').value = 'GRN-' + dateStr + '-' + timestamp;
                    }
                }
            })
            .catch(() => {
                // Fallback on error
                const timestamp = Date.now().toString().slice(-6);
                document.getElementById('grn_number').value = 'GRN-' + dateStr + '-' + timestamp;
            });
    }
    
    tryGenerate();
}

function addItem() {
    // Reset modal fields
    document.getElementById('product_search').value = '';
    document.getElementById('selected_product_id').value = '';
    document.getElementById('item_quantity').value = '1';
    document.getElementById('item_serial_numbers').value = '';
    document.getElementById('item_cost_price').value = '';
    document.getElementById('item_selling_price').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('addItemModal'));
    modal.show();
}

function confirmAddItem() {
    const productId = document.getElementById('selected_product_id').value;
    const productName = document.getElementById('product_search').value;
    const quantity = parseInt(document.getElementById('item_quantity').value);
    const serialNumbers = document.getElementById('item_serial_numbers').value;
    const costPrice = parseFloat(document.getElementById('item_cost_price').value);
    const sellingPrice = parseFloat(document.getElementById('item_selling_price').value);
    
    if (!productId || !productName || !quantity || !costPrice || !sellingPrice) {
        Swal.fire('Error', 'Please fill in all required fields', 'error');
        return;
    }
    
    const item = {
        id: itemCounter++,
        product_id: productId,
        product_name: productName,
        quantity: quantity,
        serial_numbers: serialNumbers,
        cost_price: costPrice,
        selling_price: sellingPrice,
        total: costPrice * quantity
    };
    
    grnItems.push(item);
    renderItems();
    updateTotal();
    
    const modal = bootstrap.Modal.getInstance(document.getElementById('addItemModal'));
    modal.hide();
}

function removeItem(index) {
    grnItems = grnItems.filter((item, i) => i !== index);
    renderItems();
    updateTotal();
}

function renderItems() {
    const tbody = document.getElementById('items_tbody');
    tbody.innerHTML = '';
    
    if (grnItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No items added yet</td></tr>';
        return;
    }
    
    grnItems.forEach((item, index) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(item.product_name)}</td>
            <td>${item.quantity}</td>
            <td>$${item.cost_price.toFixed(2)}</td>
            <td>$${item.selling_price.toFixed(2)}</td>
            <td>$${item.total.toFixed(2)}</td>
            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${index})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function updateTotal() {
    const total = grnItems.reduce((sum, item) => sum + item.total, 0);
    document.getElementById('total_value').textContent = '$' + total.toFixed(2);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.getElementById('grnForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (!document.getElementById('supplier_id').value) {
        Swal.fire('Error', 'Please select a supplier', 'error');
        return;
    }
    
    if (grnItems.length === 0) {
        Swal.fire('Error', 'Please add at least one item', 'error');
        return;
    }
    
    const formData = new FormData(this);
    const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
    const grnId = <?= $isEdit ? $grnId : 'null' ?>;
    
    const data = {
        grn_number: formData.get('grn_number'),
        supplier_id: formData.get('supplier_id'),
        branch_id: formData.get('branch_id'),
        received_date: formData.get('received_date'),
        notes: formData.get('notes'),
        items: grnItems
    };
    
    if (isEdit && grnId) {
        data.grn_id = grnId;
    }
    
    document.getElementById('saveBtn').disabled = true;
    document.getElementById('saveBtn').innerHTML = '<span class="spinner-border spinner-border-sm"></span> <?= $isEdit ? 'Updating' : 'Saving' ?>...';
    
    fetch('<?= BASE_URL ?>ajax/' + (isEdit ? 'update_grn.php' : 'create_grn.php'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: isEdit ? 'GRN updated successfully' : 'GRN created successfully',
                confirmButtonColor: '#1e3a8a'
            }).then(() => {
                window.location.href = 'grn.php';
            });
        } else {
            Swal.fire('Error', data.message || (isEdit ? 'Failed to update GRN' : 'Failed to create GRN'), 'error');
            document.getElementById('saveBtn').disabled = false;
            document.getElementById('saveBtn').innerHTML = '<i class="bi bi-save"></i> <?= $isEdit ? 'Update' : 'Save' ?> GRN';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An error occurred while creating GRN', 'error');
        document.getElementById('saveBtn').disabled = false;
        document.getElementById('saveBtn').innerHTML = '<i class="bi bi-save"></i> Save GRN';
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
