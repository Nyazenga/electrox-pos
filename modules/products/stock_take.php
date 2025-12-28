<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/settings_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.stock_take');

$pageTitle = 'Stock Take';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

// Check if stock take is enabled
if (getSetting('allow_stock_take', '1') != '1') {
    redirectTo('index.php');
}

// Get view type: full or single
$viewType = $_GET['view'] ?? 'full'; // 'full' or 'single'
$stockTakeId = $_GET['id'] ?? null;
$productId = $_GET['product_id'] ?? null;

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get categories
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Get products for single product view - load all products like invoicing page does
$products = [];
if ($viewType === 'single') {
    $whereConditions = ["p.status = 'Active'"];
    $params = [];
    
    if ($branchId) {
        $whereConditions[] = "p.branch_id = :branch_id";
        $params[':branch_id'] = $branchId;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    $products = $db->getRows("SELECT p.*, 
                             COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as display_name,
                             pc.name as category_name
                             FROM products p
                             LEFT JOIN product_categories pc ON p.category_id = pc.id
                             WHERE $whereClause
                             ORDER BY COALESCE(p.product_name, p.brand, ''), p.model", $params);
    if ($products === false) $products = [];
}

// Get selected product if productId is provided
$selectedProduct = null;
if ($viewType === 'single' && $productId) {
    $selectedProduct = $db->getRow("SELECT p.*, 
                                    COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as display_name,
                                    pc.name as category_name
                                    FROM products p
                                    LEFT JOIN product_categories pc ON p.category_id = pc.id
                                    WHERE p.id = :id", [':id' => $productId]);
}

// Get existing draft stock take
$draftStockTake = null;
$stockTakeItems = [];

if ($stockTakeId) {
    // Load specific draft by ID
    $draftStockTake = $primaryDb->getRow("SELECT * FROM stock_takes WHERE id = :id", [':id' => $stockTakeId]);
    if ($draftStockTake && strtolower($draftStockTake['status']) === 'draft') {
        $stockTakeItems = $primaryDb->getRows("SELECT sti.*, 
                                              COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as product_name,
                                              p.product_code
                                              FROM stock_take_items sti
                                              LEFT JOIN products p ON sti.product_id = p.id
                                              WHERE sti.stock_take_id = :id", [':id' => $stockTakeId]);
    }
} elseif ($viewType === 'full' && $branchId) {
    // If no ID in URL, try to load the most recent draft for this branch and user
    $draftStockTake = $primaryDb->getRow("SELECT * FROM stock_takes 
                                         WHERE branch_id = :branch_id 
                                         AND taken_by = :user_id 
                                         AND status = 'draft' 
                                         AND take_type = 'full'
                                         ORDER BY taken_at DESC 
                                         LIMIT 1", [
        ':branch_id' => $branchId,
        ':user_id' => $userId
    ]);
    
    if ($draftStockTake) {
        $stockTakeItems = $primaryDb->getRows("SELECT sti.*, 
                                              COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as product_name,
                                              p.product_code
                                              FROM stock_take_items sti
                                              LEFT JOIN products p ON sti.product_id = p.id
                                              WHERE sti.stock_take_id = :id", [':id' => $draftStockTake['id']]);
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clipboard-check"></i> Stock Take</h2>
    <div>
        <a href="stock_take.php?view=full" class="btn btn-primary <?= $viewType === 'full' ? 'active' : '' ?>">
            <i class="bi bi-list-ul"></i> Full Stock Take
        </a>
        <a href="stock_take.php?view=single" class="btn btn-secondary <?= $viewType === 'single' ? 'active' : '' ?>">
            <i class="bi bi-box"></i> Single Product
        </a>
        <?php if ($draftStockTake): ?>
        <button class="btn btn-success" onclick="finalizeStockTake()">
            <i class="bi bi-check-circle"></i> Finalize Stock Take
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($viewType === 'single'): ?>
    <!-- Single Product Stock Take -->
    <div class="card mb-4">
        <div class="card-header">
            <h5>Single Product Stock Take</h5>
        </div>
        <div class="card-body">
            <form id="singleProductForm">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Select Product *</label>
                        <div class="position-relative" style="z-index: 1000;">
                            <input type="text" class="form-control" id="productSearch" placeholder="Type to search products..." autocomplete="off">
                            <input type="hidden" id="selectedProductId" name="product_id">
                            <div class="dropdown-menu position-absolute w-100 product-search-dropdown" id="productDropdown" style="display: none; max-height: 300px; overflow-y: auto; z-index: 1051;">
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
                                       data-stock="<?= $product['quantity_in_stock'] ?? 0 ?>">
                                        <?= escapeHtml($productDisplayName) ?> 
                                        (Code: <?= escapeHtml($product['product_code'] ?? 'N/A') ?>) 
                                        - Stock: <?= $product['quantity_in_stock'] ?? 0 ?>
                                        <?php if (!empty($product['category_name'])): ?>
                                            <small class="text-muted"> - <?= escapeHtml($product['category_name']) ?></small>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Branch *</label>
                        <select class="form-select" name="branch_id" id="branchId" required>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= $branch['id'] ?>" <?= $branch['id'] == $branchId ? 'selected' : '' ?>>
                                    <?= escapeHtml($branch['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Counted Stock *</label>
                        <input type="number" class="form-control" name="counted_stock" id="countedStock" required min="0" step="1">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div id="productInfo" style="display: none;" class="alert alert-info">
                    <strong>Current Stock:</strong> <span id="currentStock">0</span>
                    <br>
                    <strong>Difference:</strong> <span id="stockDifference">0</span>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Stock Take
                </button>
            </form>
        </div>
    </div>
<?php else: ?>
    <!-- Full Stock Take -->
    <div class="card mb-4">
        <div class="card-header">
            <h5>Full Stock Take</h5>
            <small class="text-white-50">Auto-saves as draft. Changes will overwrite existing stock levels when finalized.</small>
        </div>
        <div class="card-body">
            <form id="stockTakeForm">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Branch *</label>
                        <select class="form-select" name="branch_id" id="stockTakeBranchId" required>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= $branch['id'] ?>" <?= $branch['id'] == $branchId ? 'selected' : '' ?>>
                                    <?= escapeHtml($branch['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Category Filter</label>
                        <select class="form-select" id="categoryFilter">
                            <option value="all">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>"><?= escapeHtml($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search Product</label>
                        <input type="text" class="form-control" id="productSearchFilter" placeholder="Search products...">
                    </div>
                </div>
                
                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                    <table class="table table-striped table-hover">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th width="50">#</th>
                                <th>Product Code</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th class="text-end">Current Stock</th>
                                <th class="text-end">Counted Stock</th>
                                <th class="text-end">Difference</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody id="stockTakeTableBody">
                            <!-- Products will be loaded here -->
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-3">
                    <button type="button" class="btn btn-primary" onclick="saveDraft()">
                        <i class="bi bi-save"></i> Save Draft
                    </button>
                    <button type="button" class="btn btn-success" onclick="finalizeStockTake()">
                        <i class="bi bi-check-circle"></i> Finalize Stock Take
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;
let stockTakeData = <?= json_encode($draftStockTake ? ['id' => $draftStockTake['id'], 'items' => ($stockTakeItems ?? [])] : null) ?>;
let products = [];
let stockTakeItems = {};
let isFinalizing = false; // Flag to prevent auto-save during finalization
let pendingSaveTimeout = null; // Debounce auto-save
let pendingSavePromise = null; // Track pending save operation

<?php if ($viewType === 'full'): ?>
// Load products for full stock take
function loadProducts() {
    const branchId = document.getElementById('stockTakeBranchId').value;
    const categoryId = document.getElementById('categoryFilter').value;
    const search = document.getElementById('productSearchFilter').value.trim();
    
    fetch(BASE_URL + 'ajax/get_products_for_stock_take.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            branch_id: branchId,
            category_id: categoryId === 'all' ? null : categoryId,
            search: search || ''
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            products = data.products;
            renderStockTakeTable();
        } else {
            console.error('Error loading products:', data.message);
            products = [];
            renderStockTakeTable();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        products = [];
        renderStockTakeTable();
    });
}

function renderStockTakeTable() {
    const tbody = document.getElementById('stockTakeTableBody');
    tbody.innerHTML = '';
    
    products.forEach((product, index) => {
        // First check if we have saved draft data from the server
        let item = null;
        if (stockTakeData && stockTakeData.items && Array.isArray(stockTakeData.items)) {
            const savedItem = stockTakeData.items.find(i => i.product_id == product.id);
            if (savedItem) {
                item = {
                    product_id: product.id,
                    current_stock: savedItem.current_stock || product.quantity_in_stock || 0,
                    counted_stock: savedItem.counted_stock || product.quantity_in_stock || 0,
                    difference: savedItem.difference || 0,
                    notes: savedItem.notes || ''
                };
            }
        }
        
        // If no saved item, check in-memory stockTakeItems
        if (!item) {
            item = stockTakeItems[product.id] || {
                product_id: product.id,
                current_stock: product.quantity_in_stock || 0,
                counted_stock: product.quantity_in_stock || 0,
                difference: 0,
                notes: ''
            };
        }
        
        const difference = item.counted_stock - item.current_stock;
        
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${index + 1}</td>
            <td>${escapeHtml(product.product_code || 'N/A')}</td>
            <td>${escapeHtml(product.display_name || 'N/A')}</td>
            <td>${escapeHtml(product.category_name || 'N/A')}</td>
            <td class="text-end">${item.current_stock}</td>
            <td class="text-end">
                <input type="number" class="form-control form-control-sm text-end" 
                       value="${item.counted_stock}" 
                       min="0" 
                       step="1"
                       onchange="updateStockTakeItem(${product.id}, this.value)"
                       data-product-id="${product.id}">
            </td>
            <td class="text-end ${difference >= 0 ? 'text-success' : 'text-danger'}">
                ${difference >= 0 ? '+' : ''}${difference}
            </td>
            <td>
                <input type="text" class="form-control form-control-sm" 
                       value="${escapeHtml(item.notes || '')}" 
                       placeholder="Notes..."
                       onchange="updateStockTakeNotes(${product.id}, this.value)"
                       data-product-id="${product.id}">
            </td>
        `;
        tbody.appendChild(row);
        
        stockTakeItems[product.id] = item;
    });
}

function updateStockTakeItem(productId, countedStock) {
    if (!stockTakeItems[productId]) {
        const product = products.find(p => p.id == productId);
        stockTakeItems[productId] = {
            product_id: productId,
            current_stock: product.quantity_in_stock || 0,
            counted_stock: parseFloat(countedStock) || 0,
            difference: 0,
            notes: ''
        };
    }
    
    stockTakeItems[productId].counted_stock = parseFloat(countedStock) || 0;
    stockTakeItems[productId].difference = stockTakeItems[productId].counted_stock - stockTakeItems[productId].current_stock;
    
    // Update difference display
    const row = document.querySelector(`tr input[data-product-id="${productId}"]`).closest('tr');
    const diffCell = row.querySelector('td:nth-child(7)');
    const diff = stockTakeItems[productId].difference;
    diffCell.className = `text-end ${diff >= 0 ? 'text-success' : 'text-danger'}`;
    diffCell.textContent = (diff >= 0 ? '+' : '') + diff;
    
    // Auto-save draft (debounced, only if not finalizing)
    if (!isFinalizing) {
        debouncedSaveDraft();
    }
}

function updateStockTakeNotes(productId, notes) {
    if (!stockTakeItems[productId]) {
        const product = products.find(p => p.id == productId);
        stockTakeItems[productId] = {
            product_id: productId,
            current_stock: product.quantity_in_stock || 0,
            counted_stock: product.quantity_in_stock || 0,
            difference: 0,
            notes: notes
        };
    } else {
        stockTakeItems[productId].notes = notes;
    }
    
    // Auto-save draft (debounced, only if not finalizing)
    if (!isFinalizing) {
        debouncedSaveDraft();
    }
}

// Debounced auto-save function
function debouncedSaveDraft() {
    // Clear any pending save
    if (pendingSaveTimeout) {
        clearTimeout(pendingSaveTimeout);
    }
    
    // Set new timeout (500ms debounce)
    pendingSaveTimeout = setTimeout(() => {
        if (!isFinalizing) {
            saveDraft(true);
        }
        pendingSaveTimeout = null;
    }, 500);
}

function saveDraft(silent = false) {
    // Don't save if finalizing
    if (isFinalizing) {
        return Promise.resolve();
    }
    
    const branchId = document.getElementById('stockTakeBranchId').value;
    const items = Object.values(stockTakeItems);
    
    if (items.length === 0) {
        if (!silent) {
            Swal.fire('Warning', 'No items to save', 'warning');
        }
        return Promise.resolve();
    }
    
    if (!silent) {
        Swal.fire({
            title: 'Saving...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
    }
    
    // Cancel any pending save timeout
    if (pendingSaveTimeout) {
        clearTimeout(pendingSaveTimeout);
        pendingSaveTimeout = null;
    }
    
    // Wait for any pending save to complete
    const savePromise = pendingSavePromise 
        ? pendingSavePromise.then(() => performSave(silent))
        : performSave(silent);
    
    pendingSavePromise = savePromise;
    return savePromise;
}

function performSave(silent) {
    const branchId = document.getElementById('stockTakeBranchId').value;
    const items = Object.values(stockTakeItems);
    
    return fetch(BASE_URL + 'ajax/save_stock_take_draft.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            stock_take_id: stockTakeData?.id || null,
            branch_id: branchId,
            items: items
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (stockTakeData) {
                stockTakeData.id = data.stock_take_id;
            } else {
                stockTakeData = { id: data.stock_take_id, items: [] };
            }
            
            if (!silent) {
                Swal.fire({
                    icon: 'success',
                    title: 'Saved!',
                    text: 'Stock take draft saved successfully',
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        } else {
            if (!silent) {
                Swal.fire('Error', data.message || 'Failed to save draft', 'error');
            }
        }
        pendingSavePromise = null;
        return data;
    })
    .catch(error => {
        console.error('Error:', error);
        if (!silent) {
            Swal.fire('Error', 'An error occurred while saving', 'error');
        }
        pendingSavePromise = null;
        throw error;
    });
}

function finalizeStockTake() {
    // Prevent multiple clicks
    if (isFinalizing) {
        return;
    }
    
    // Cancel any pending auto-save
    if (pendingSaveTimeout) {
        clearTimeout(pendingSaveTimeout);
        pendingSaveTimeout = null;
    }
    
    // Set finalizing flag to prevent auto-save
    isFinalizing = true;
    
    const branchId = document.getElementById('stockTakeBranchId').value;
    
    // Collect all items from the table (including those that haven't been modified)
    const items = [];
    const tableRows = document.querySelectorAll('#stockTakeTableBody tr');
    
    tableRows.forEach(row => {
        const countedInput = row.querySelector('input[type="number"][data-product-id]');
        const notesInput = row.querySelector('input[type="text"][data-product-id]');
        
        if (countedInput) {
            const productId = parseInt(countedInput.getAttribute('data-product-id'));
            const currentStock = parseFloat(row.querySelector('td:nth-child(5)').textContent) || 0;
            const countedStock = parseFloat(countedInput.value) || 0;
            const notes = notesInput ? notesInput.value : '';
            
            items.push({
                product_id: productId,
                current_stock: currentStock,
                counted_stock: countedStock,
                difference: countedStock - currentStock,
                notes: notes
            });
        }
    });
    
    // Check if there are any items to finalize
    if (items.length === 0) {
        isFinalizing = false;
        Swal.fire('Error', 'No items to finalize. Please load products first.', 'error');
        return;
    }
    
    // Update stockTakeItems with current table data to ensure consistency
    items.forEach(item => {
        stockTakeItems[item.product_id] = item;
    });
    
    // Always save/update draft with current table data BEFORE finalizing
    const finalizeAfterSave = () => {
        Swal.fire({
            title: 'Finalize Stock Take?',
            text: 'This will overwrite current stock levels with counted values. This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Finalize',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Finalizing...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });
                
                // Send current items data to ensure finalize uses latest data, not stale database data
                fetch(BASE_URL + 'ajax/finalize_stock_take.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        stock_take_id: stockTakeData.id,
                        items: items // Send current table data to ensure consistency
                    })
                })
                .then(async response => {
                    const text = await response.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Response text:', text);
                        throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                    }
                })
                .then(data => {
                    isFinalizing = false; // Reset flag
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Finalized!',
                            text: 'Stock take has been finalized and stock levels updated',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            location.href = 'stock_take.php?view=full';
                        });
                    } else {
                        console.error('Finalize error:', data);
                        Swal.fire('Error', data.message || 'Failed to finalize stock take', 'error');
                    }
                })
                .catch(error => {
                    isFinalizing = false; // Reset flag on error
                    console.error('Error:', error);
                    Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
                });
            }
        });
    };
    
    // Always wait for any pending save to complete, then save/update draft with current data
    const waitForPendingSave = pendingSavePromise 
        ? pendingSavePromise.catch(() => {}) // Ignore errors from pending save
        : Promise.resolve();
    
    waitForPendingSave.then(() => {
        // Save/update draft with current table data (ensures database has latest values)
        fetch(BASE_URL + 'ajax/save_stock_take_draft.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                stock_take_id: stockTakeData?.id || null,
                branch_id: branchId,
                items: items // Use current table data, not stale stockTakeItems
            })
        })
        .then(async response => {
            const text = await response.text();
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Response text:', text);
                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
            }
        })
        .then(data => {
            if (data.success && data.stock_take_id) {
                stockTakeData = { id: data.stock_take_id, items: [] };
                console.log('Draft saved/updated with ID:', data.stock_take_id);
                // Small delay to ensure database commit is complete
                setTimeout(() => {
                    finalizeAfterSave();
                }, 100);
            } else {
                isFinalizing = false;
                console.error('Save draft error:', data);
                Swal.fire('Error', data.message || 'Failed to save draft', 'error');
            }
        })
        .catch(error => {
            isFinalizing = false;
            console.error('Error saving draft:', error);
            Swal.fire('Error', 'An error occurred while saving draft: ' + error.message, 'error');
        });
    });
}

// Load products on page load
document.addEventListener('DOMContentLoaded', function() {
    // Load existing draft items if any (before loading products)
    <?php if ($draftStockTake && isset($stockTakeItems) && !empty($stockTakeItems)): ?>
    stockTakeItems = {};
    <?php foreach ($stockTakeItems as $item): ?>
    stockTakeItems[<?= $item['product_id'] ?>] = {
        product_id: <?= $item['product_id'] ?>,
        current_stock: <?= $item['current_stock'] ?>,
        counted_stock: <?= $item['counted_stock'] ?>,
        difference: <?= $item['difference'] ?>,
        notes: <?= json_encode($item['notes'] ?? '') ?>
    };
    <?php endforeach; ?>
    console.log('Loaded draft items:', stockTakeItems);
    <?php endif; ?>
    
    // Now load products (which will render the table with draft data)
    loadProducts();
    
    document.getElementById('stockTakeBranchId').addEventListener('change', loadProducts);
    document.getElementById('categoryFilter').addEventListener('change', loadProducts);
    document.getElementById('productSearchFilter').addEventListener('input', debounce(loadProducts, 500));
});

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
<?php else: ?>
// Single product stock take - using client-side filtering like invoicing page
document.addEventListener('DOMContentLoaded', function() {
    const productSearch = document.getElementById('productSearch');
    const productDropdown = document.getElementById('productDropdown');
    
    if (!productSearch || !productDropdown) {
        console.error('Product search elements not found');
        return;
    }
    
    // Filter dropdown function (same as invoicing page)
    function filterDropdown(searchTerm, dropdown, itemSelector) {
        const items = dropdown.querySelectorAll(itemSelector);
        const term = searchTerm.toLowerCase().trim();
        let visibleCount = 0;
        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            if (text.includes(term)) {
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });
        return visibleCount > 0;
    }
    
    productSearch.addEventListener('input', function() {
        const hasResults = filterDropdown(this.value, productDropdown, '.product-item');
        if (this.value.trim().length > 0) {
            productDropdown.style.display = hasResults ? 'block' : 'none';
        } else {
            productDropdown.style.display = 'none';
        }
    });
    
    productSearch.addEventListener('focus', function() {
        if (this.value.trim().length > 0) {
            productDropdown.style.display = 'block';
        }
    });
    
    productSearch.addEventListener('blur', function() {
        setTimeout(() => productDropdown.style.display = 'none', 300);
    });
    
    productDropdown.addEventListener('click', function(e) {
        e.preventDefault();
        const item = e.target.closest('.product-item');
        if (item) {
            productSearch.value = item.dataset.name;
            document.getElementById('selectedProductId').value = item.dataset.id;
            document.getElementById('currentStock').textContent = item.dataset.stock || 0;
            document.getElementById('countedStock').value = item.dataset.stock || 0;
            document.getElementById('productInfo').style.display = 'block';
            updateDifference();
            productDropdown.style.display = 'none';
        }
    });
    
    function updateDifference() {
        const current = parseFloat(document.getElementById('currentStock').textContent) || 0;
        const counted = parseFloat(document.getElementById('countedStock').value) || 0;
        const diff = counted - current;
        document.getElementById('stockDifference').textContent = (diff >= 0 ? '+' : '') + diff;
        document.getElementById('stockDifference').className = diff >= 0 ? 'text-success' : 'text-danger';
    }
    
    const countedStockInput = document.getElementById('countedStock');
    if (countedStockInput) {
        countedStockInput.addEventListener('input', updateDifference);
    }
    
    const singleProductForm = document.getElementById('singleProductForm');
    if (singleProductForm) {
        singleProductForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const productId = document.getElementById('selectedProductId').value;
            if (!productId) {
                Swal.fire('Error', 'Please select a product', 'error');
                return;
            }
            
            const formData = new FormData(this);
            formData.append('product_id', productId);
            
            Swal.fire({
                title: 'Saving...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch(BASE_URL + 'ajax/save_single_stock_take.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Saved!',
                        text: 'Stock take saved and stock level updated',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.href = 'stock_take.php?view=full';
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to save stock take', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred', 'error');
            });
        });
    }
});
<?php endif; ?>
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

