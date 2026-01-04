<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.create');

$pageTitle = 'New Stock Transfer';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

// Get data
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get products - handle both General category (product_name) and others (brand/model)
$products = $db->getRows("SELECT p.*, 
                         COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as display_name,
                         c.name as category_name 
                         FROM products p 
                         LEFT JOIN product_categories c ON p.category_id = c.id 
                         WHERE p.status = 'Active' 
                         ORDER BY COALESCE(p.product_name, p.brand, ''), p.model");
if ($products === false) $products = [];

require_once APP_PATH . '/includes/header.php';
?>

<style>
.searchable-dropdown {
    position: relative;
}

.searchable-dropdown input {
    width: 100%;
}

.searchable-dropdown .dropdown-menu {
    max-height: 300px;
    overflow-y: auto;
    width: 100%;
    display: none;
}

.searchable-dropdown.show .dropdown-menu {
    display: block;
}

.transfer-items-table {
    margin-top: 20px;
}

.transfer-items-table th {
    background-color: #f8f9fa;
    font-weight: 600;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>New Stock Transfer</h2>
    <a href="transfers.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form id="transferForm">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Transfer Number *</label>
                    <input type="text" class="form-control" id="transfer_number" name="transfer_number" readonly required>
                    <small class="text-muted">Auto-generated</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Transfer Date *</label>
                    <input type="date" class="form-control" name="transfer_date" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">From Branch *</label>
                    <select class="form-select" name="from_branch_id" id="from_branch_id" required>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $branchId == $branch['id'] ? 'selected' : '' ?>>
                                <?= escapeHtml($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">To Branch *</label>
                    <select class="form-select" name="to_branch_id" id="to_branch_id" required>
                        <option value="">Select Branch</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $branchId == $branch['id'] ? 'disabled' : '' ?>>
                                <?= escapeHtml($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="3"></textarea>
            </div>
            
            <div class="mt-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5>Items</h5>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addItem()">
                        <i class="bi bi-plus-circle"></i> Add Item
                    </button>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered transfer-items-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Available Stock</th>
                                <th>Quantity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="items_tbody">
                            <tr><td colspan="4" class="text-center text-muted">No items added yet</td></tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="text-end"><strong>Total Items:</strong></td>
                                <td><strong id="total_items">0</strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="saveBtn">
                    <i class="bi bi-save"></i> Save Transfer
                </button>
                <a href="transfers.php" class="btn btn-secondary">Cancel</a>
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
                            <!-- Products will be loaded dynamically based on selected branch -->
                        </div>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Quantity *</label>
                        <input type="number" class="form-control" id="item_quantity" min="1" value="1" required>
                        <small class="text-muted" id="available_stock_text"></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Serial Numbers (Optional)</label>
                        <textarea class="form-control" id="item_serial_numbers" rows="2" placeholder="Enter serial numbers, one per line"></textarea>
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
let transferItems = [];
let itemCounter = 0;
let fromBranchId = document.getElementById('from_branch_id').value;

// Generate Transfer number on page load
document.addEventListener('DOMContentLoaded', function() {
    generateTransferNumber();
    
    // Load products for initial branch
    loadProductsForBranch(fromBranchId);
    
    // Update from branch ID when changed
    document.getElementById('from_branch_id').addEventListener('change', function() {
        const newFromBranchId = this.value;
        fromBranchId = newFromBranchId;
        
        // Update To Branch dropdown - disable the selected From Branch and enable others
        const toBranchSelect = document.getElementById('to_branch_id');
        const toBranchOptions = toBranchSelect.querySelectorAll('option');
        
        toBranchOptions.forEach(option => {
            if (option.value === '') {
                // Skip the "Select Branch" option
                return;
            }
            if (option.value === newFromBranchId) {
                // Disable the selected From Branch
                option.disabled = true;
                // If this was selected, clear the selection
                if (toBranchSelect.value === newFromBranchId) {
                    toBranchSelect.value = '';
                }
            } else {
                // Enable all other branches
                option.disabled = false;
            }
        });
        
        // Clear items when branch changes
        transferItems = [];
        renderItems();
        updateTotal();
        // Reload products for new branch
        loadProductsForBranch(fromBranchId);
    });
    
    // Product search
    const productSearch = document.getElementById('product_search');
    const productDropdown = document.getElementById('product_dropdown');
    
    productSearch.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        const items = productDropdown.querySelectorAll('.product-item');
        
        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            if (text.includes(searchTerm) || searchTerm === '') {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
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
            const stock = parseInt(item.getAttribute('data-stock'));
            
            document.getElementById('selected_product_id').value = id;
            productSearch.value = name;
            document.getElementById('item_quantity').max = stock;
            document.getElementById('item_quantity').value = Math.min(1, stock);
            document.getElementById('available_stock_text').textContent = `Available: ${stock}`;
            productDropdown.style.display = 'none';
        }
    });
});

function generateTransferNumber() {
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
        const transferNumber = 'TRF-' + dateStr + '-' + sequence;
        
        // Check if exists
        fetch('<?= BASE_URL ?>ajax/check_transfer_number.php?number=' + encodeURIComponent(transferNumber))
            .then(response => response.json())
            .then(data => {
                if (!data.exists) {
                    document.getElementById('transfer_number').value = transferNumber;
                } else {
                    attempt++;
                    if (attempt < maxAttempts) {
                        tryGenerate();
                    } else {
                        // Fallback with timestamp
                        const timestamp = Date.now().toString().slice(-6);
                        document.getElementById('transfer_number').value = 'TRF-' + dateStr + '-' + timestamp;
                    }
                }
            })
            .catch(() => {
                // Fallback on error
                const timestamp = Date.now().toString().slice(-6);
                document.getElementById('transfer_number').value = 'TRF-' + dateStr + '-' + timestamp;
            });
    }
    
    tryGenerate();
}

function loadProductsForBranch(branchId) {
    if (!branchId) {
        document.getElementById('product_dropdown').innerHTML = '<div class="dropdown-item text-muted">Please select a branch first</div>';
        return;
    }
    
    fetch('<?= BASE_URL ?>ajax/get_products_for_branch.php?branch_id=' + encodeURIComponent(branchId))
        .then(response => response.json())
        .then(data => {
            const dropdown = document.getElementById('product_dropdown');
            if (data.success && data.products && data.products.length > 0) {
                dropdown.innerHTML = '';
                data.products.forEach(product => {
                    const item = document.createElement('a');
                    item.className = 'dropdown-item product-item';
                    item.href = '#';
                    item.setAttribute('data-id', product.id);
                    item.setAttribute('data-name', product.display_name);
                    item.setAttribute('data-stock', product.quantity_in_stock);
                    item.innerHTML = `
                        <strong>${escapeHtml(product.display_name)}</strong>
                        <br>
                        <small class="text-muted">
                            ${escapeHtml(product.category_name)} | 
                            Code: ${escapeHtml(product.product_code)} |
                            Stock: ${product.quantity_in_stock}
                        </small>
                    `;
                    dropdown.appendChild(item);
                });
            } else {
                dropdown.innerHTML = '<div class="dropdown-item text-muted">No products available in this branch</div>';
            }
        })
        .catch(error => {
            console.error('Error loading products:', error);
            document.getElementById('product_dropdown').innerHTML = '<div class="dropdown-item text-danger">Error loading products</div>';
        });
}

function addItem() {
    // Reset modal fields
    document.getElementById('product_search').value = '';
    document.getElementById('selected_product_id').value = '';
    document.getElementById('item_quantity').value = '1';
    document.getElementById('available_stock_text').textContent = '';
    
    // Ensure products are loaded for current branch
    const currentBranchId = document.getElementById('from_branch_id').value;
    if (currentBranchId) {
        loadProductsForBranch(currentBranchId);
    }
    
    const modal = new bootstrap.Modal(document.getElementById('addItemModal'));
    modal.show();
}

function confirmAddItem() {
    const productId = document.getElementById('selected_product_id').value;
    const productName = document.getElementById('product_search').value;
    const quantity = parseInt(document.getElementById('item_quantity').value);
    const availableStock = parseInt(document.getElementById('item_quantity').max);
    
    if (!productId || !productName || !quantity || quantity <= 0) {
        Swal.fire('Error', 'Please fill in all required fields', 'error');
        return;
    }
    
    if (quantity > availableStock) {
        Swal.fire('Error', 'Quantity cannot exceed available stock', 'error');
        return;
    }
    
    // Check if product already added
    if (transferItems.some(item => item.product_id == productId)) {
        Swal.fire('Error', 'Product already added to transfer', 'error');
        return;
    }
    
    const item = {
        id: itemCounter++,
        product_id: productId,
        product_name: productName,
        quantity: quantity,
        available_stock: availableStock
    };
    
    transferItems.push(item);
    renderItems();
    updateTotal();
    
    const modal = bootstrap.Modal.getInstance(document.getElementById('addItemModal'));
    modal.hide();
}

function removeItem(index) {
    transferItems = transferItems.filter((item, i) => i !== index);
    renderItems();
    updateTotal();
}

function renderItems() {
    const tbody = document.getElementById('items_tbody');
    tbody.innerHTML = '';
    
    if (transferItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No items added yet</td></tr>';
        return;
    }
    
    transferItems.forEach((item, index) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(item.product_name)}</td>
            <td>${item.available_stock}</td>
            <td>${item.quantity}</td>
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
    const total = transferItems.reduce((sum, item) => sum + item.quantity, 0);
    document.getElementById('total_items').textContent = total;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.getElementById('transferForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const fromBranch = document.querySelector('select[name="from_branch_id"]').value;
    const toBranch = document.querySelector('select[name="to_branch_id"]').value;
    
    if (fromBranch === toBranch) {
        Swal.fire('Error', 'From and To branches cannot be the same', 'error');
        return;
    }
    
    if (transferItems.length === 0) {
        Swal.fire('Error', 'Please add at least one item', 'error');
        return;
    }
    
    const formData = new FormData(this);
    const data = {
        transfer_number: formData.get('transfer_number'),
        from_branch_id: formData.get('from_branch_id'),
        to_branch_id: formData.get('to_branch_id'),
        transfer_date: formData.get('transfer_date'),
        notes: formData.get('notes'),
        items: transferItems
    };
    
    document.getElementById('saveBtn').disabled = true;
    document.getElementById('saveBtn').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
    
    fetch('<?= BASE_URL ?>ajax/create_transfer.php', {
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
                text: 'Transfer created successfully',
                confirmButtonColor: '#1e3a8a'
            }).then(() => {
                window.location.href = 'transfers.php';
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to create transfer', 'error');
            document.getElementById('saveBtn').disabled = false;
            document.getElementById('saveBtn').innerHTML = '<i class="bi bi-save"></i> Save Transfer';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An error occurred while creating transfer', 'error');
        document.getElementById('saveBtn').disabled = false;
        document.getElementById('saveBtn').innerHTML = '<i class="bi bi-save"></i> Save Transfer';
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
