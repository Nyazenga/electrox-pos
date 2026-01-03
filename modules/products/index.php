<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.view');

$pageTitle = 'Products';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;

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
    return is_array($taxes) ? $taxes : [];
}

// Get all applicable taxes (for bulk assignment - show all available taxes)
function getAllApplicableTaxes($primaryDb) {
    $configs = $primaryDb->getRows(
        "SELECT DISTINCT applicable_taxes FROM fiscal_config WHERE applicable_taxes IS NOT NULL AND applicable_taxes != ''"
    );
    
    $allTaxes = [];
    $seenTaxIds = [];
    
    foreach ($configs as $config) {
        $taxes = json_decode($config['applicable_taxes'], true);
        if (is_array($taxes)) {
            foreach ($taxes as $tax) {
                $taxId = $tax['taxID'] ?? null;
                if ($taxId && !in_array($taxId, $seenTaxIds)) {
                    $allTaxes[] = $tax;
                    $seenTaxIds[] = $taxId;
                }
            }
        }
    }
    
    return $allTaxes;
}

$allTaxes = getAllApplicableTaxes($primaryDb);

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$categoryId = $_GET['category_id'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$stockLevel = $_GET['stock_level'] ?? 'all';
$search = $_GET['search'] ?? '';

// Get branches for filter
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get categories for filter
$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Build query
$whereConditions = ["1=1"];
$params = [];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($categoryId !== 'all' && $categoryId) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $categoryId;
}

if ($status !== 'all') {
    $whereConditions[] = "p.status = :status";
    $params[':status'] = $status;
}

if ($stockLevel !== 'all') {
    if ($stockLevel === 'out') {
        $whereConditions[] = "p.quantity_in_stock = 0";
    } elseif ($stockLevel === 'low') {
        $whereConditions[] = "p.quantity_in_stock > 0 AND p.quantity_in_stock <= p.reorder_level";
    } elseif ($stockLevel === 'below_reorder') {
        $whereConditions[] = "p.quantity_in_stock <= p.reorder_level";
    } elseif ($stockLevel === 'in_stock') {
        $whereConditions[] = "p.quantity_in_stock > p.reorder_level";
    }
}

if ($search) {
    // Search across multiple fields, including concatenated brand+model and product_name
    $whereConditions[] = "(p.brand LIKE :search1 
                          OR p.model LIKE :search2 
                          OR p.product_name LIKE :search5 
                          OR p.product_code LIKE :search3 
                          OR p.description LIKE :search4
                          OR CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, '')) LIKE :search6
                          OR CONCAT(COALESCE(p.product_name, ''), ' ', COALESCE(p.brand, ''), ' ', COALESCE(p.model, '')) LIKE :search7)";
    $searchTerm = "%$search%";
    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    $params[':search3'] = $searchTerm;
    $params[':search4'] = $searchTerm;
    $params[':search5'] = $searchTerm;
    $params[':search6'] = $searchTerm;
    $params[':search7'] = $searchTerm;
}

$whereClause = implode(' AND ', $whereConditions);

$products = $db->getRows("SELECT p.*, pc.name as category_name, pc.tax_id as category_tax_id, b.branch_name 
                          FROM products p 
                          LEFT JOIN product_categories pc ON p.category_id = pc.id 
                          LEFT JOIN branches b ON p.branch_id = b.id 
                          WHERE $whereClause
                          ORDER BY p.created_at DESC", $params);
if ($products === false) $products = [];

// Get success message from session if exists
$successMessage = '';
if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

require_once APP_PATH . '/includes/header.php';
?>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i> <?= escapeHtml($successMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Products</h2>
    <?php if ($auth->hasPermission('products.create')): ?>
        <div>
            <button type="button" class="btn btn-success me-2" onclick="showBulkUploadModal()">
                <i class="bi bi-upload"></i> Bulk Upload Products
            </button>
            <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Product</a>
        </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body" style="padding: 12px;">
        <form method="GET" class="row g-2">
            <?php if (!$branchId): ?>
            <div class="col-md-2">
                <label class="form-label">Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>>
                            <?= escapeHtml($branch['branch_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label">Category</label>
                <select name="category_id" class="form-select">
                    <option value="all" <?= $categoryId === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $categoryId == $category['id'] ? 'selected' : '' ?>>
                            <?= escapeHtml($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Stock Level</label>
                <select name="stock_level" class="form-select">
                    <option value="all" <?= $stockLevel === 'all' ? 'selected' : '' ?>>All Levels</option>
                    <option value="out" <?= $stockLevel === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                    <option value="low" <?= $stockLevel === 'low' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="below_reorder" <?= $stockLevel === 'below_reorder' ? 'selected' : '' ?>>Below Reorder Level</option>
                    <option value="in_stock" <?= $stockLevel === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Brand, Model, Product Name, Code..." value="<?= escapeHtml($search) ?>">
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
            </div>
            <div class="col-md-12">
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset Filters</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Products</h5>
        <?php if ($auth->hasPermission('products.edit') && !empty($allTaxes)): ?>
            <div>
                <button type="button" class="btn btn-sm btn-primary" id="bulkTaxBtn" onclick="showBulkTaxModal()" disabled>
                    <i class="bi bi-tag"></i> Assign Tax to Selected
                </button>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <table class="table table-striped" id="productsTable">
            <thead>
                <tr>
                    <th width="30">
                        <input type="checkbox" id="selectAllProducts" onchange="toggleSelectAll(this)">
                    </th>
                    <th>Image</th>
                    <th>Product Code</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Tax</th>
                    <th>Brand</th>
                    <th>Model</th>
                    <th>Stock</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): 
                    $productImages = !empty($product['images']) ? json_decode($product['images'], true) : [];
                    $firstImage = !empty($productImages) ? $productImages[0] : null;
                    
                    // Get tax info for display
                    $productTaxId = $product['tax_id'] ?? null;
                    $categoryTaxId = $product['category_tax_id'] ?? null;
                    $displayTax = null;
                    $taxSource = '';
                    
                    if ($productTaxId) {
                        foreach ($allTaxes as $tax) {
                            if (($tax['taxID'] ?? null) == $productTaxId) {
                                $displayTax = $tax;
                                $taxSource = 'Product';
                                break;
                            }
                        }
                    } elseif ($categoryTaxId) {
                        foreach ($allTaxes as $tax) {
                            if (($tax['taxID'] ?? null) == $categoryTaxId) {
                                $displayTax = $tax;
                                $taxSource = 'Category';
                                break;
                            }
                        }
                    }
                ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="product-checkbox" value="<?= $product['id'] ?>" onchange="updateBulkTaxButton()">
                        </td>
                        <td>
                            <div class="product-image-container" style="width: 60px; height: 60px; position: relative; cursor: pointer;" onclick="uploadProductImage(<?= $product['id'] ?>)">
                                <?php if ($firstImage): ?>
                                    <img src="<?= escapeHtml($firstImage) ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;" class="border">
                                <?php elseif (!empty($product['color']) && $product['color'] !== '#ffffff' && $product['color'] !== 'white'): ?>
                                    <div class="d-flex align-items-center justify-content-center border rounded" style="width: 100%; height: 100%; background-color: <?= escapeHtml($product['color']) ?>;">
                                        <i class="bi bi-box" style="font-size: 18px; color: rgba(0,0,0,0.3);"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex align-items-center justify-content-center bg-light border rounded" style="width: 100%; height: 100%;">
                                        <i class="bi bi-box" style="font-size: 18px; color: #999;"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="image-upload-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; border-radius: 4px;">
                                    <i class="bi bi-camera text-white"></i>
                                </div>
                            </div>
                        </td>
                        <td><?= escapeHtml($product['product_code']) ?></td>
                        <td><?= escapeHtml(!empty($product['product_name']) ? $product['product_name'] : ($product['brand'] . ' ' . $product['model'])) ?></td>
                        <td><?= escapeHtml($product['category_name']) ?></td>
                        <td>
                            <?php if ($displayTax): ?>
                                <span class="badge bg-info" title="Tax from: <?= $taxSource ?>">
                                    <?= escapeHtml($displayTax['taxName'] ?? 'Tax') ?> (<?= $displayTax['taxPercent'] ?? 0 ?>%)
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">Auto</span>
                            <?php endif; ?>
                        </td>
                        <td><?= escapeHtml($product['brand'] ?? 'N/A') ?></td>
                        <td><?= escapeHtml($product['model'] ?? 'N/A') ?></td>
                        <td><?= $product['quantity_in_stock'] ?></td>
                        <td><?= formatCurrency($product['selling_price']) ?></td>
                        <td><span class="badge bg-<?= $product['status'] == 'Active' ? 'success' : 'secondary' ?>"><?= escapeHtml($product['status']) ?></span></td>
                        <td>
                            <a href="view.php?id=<?= $product['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                            <?php if ($auth->hasPermission('products.edit')): ?>
                                <a href="edit.php?id=<?= $product['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if ($auth->hasPermission('products.delete')): ?>
                                <a href="delete.php?id=<?= $product['id'] ?>" class="btn btn-sm btn-danger delete-btn"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.product-image-container:hover .image-upload-overlay {
    display: flex !important;
}
</style>

<!-- Bulk Tax Assignment Modal -->
<div class="modal fade" id="bulkTaxModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Tax to Selected Products</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>You have selected <strong id="selectedCount">0</strong> product(s).</p>
                <div class="mb-3">
                    <label class="form-label">Select Tax</label>
                    <select class="form-control" id="bulkTaxSelect">
                        <option value="">Remove Tax (Use Category/Default)</option>
                        <?php foreach ($allTaxes as $tax): 
                            $taxDisplay = sprintf(
                                "%s (%.2f%%) - Code: %s",
                                $tax['taxName'] ?? 'Tax',
                                $tax['taxPercent'] ?? 0,
                                $tax['taxCode'] ?? ''
                            );
                        ?>
                            <option value="<?= $tax['taxID'] ?? '' ?>"><?= escapeHtml($taxDisplay) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">This will override the product's tax assignment. Products will use this tax instead of category or default tax.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="applyBulkTax()">Apply Tax</button>
            </div>
        </div>
    </div>
</div>

<!-- Image Upload Modal -->
<div class="modal fade" id="imageUploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Upload Product Image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="imageUploadForm" enctype="multipart/form-data">
                    <input type="hidden" id="uploadProductId" name="product_id">
                    <div class="mb-3">
                        <label class="form-label">Select Image</label>
                        <input type="file" class="form-control" name="image" accept="image/*" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitImageUpload()">Upload</button>
            </div>
        </div>
    </div>
</div>

<script>
function uploadProductImage(productId) {
    document.getElementById('uploadProductId').value = productId;
    new bootstrap.Modal(document.getElementById('imageUploadModal')).show();
}

function submitImageUpload() {
    const form = document.getElementById('imageUploadForm');
    const formData = new FormData(form);
    
    fetch('<?= BASE_URL ?>ajax/upload_product_image.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', 'Image uploaded successfully', 'success').then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to upload image', 'error');
        }
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

<script>
(function() {
    function initProductsTable() {
        if (typeof jQuery === 'undefined') {
            setTimeout(initProductsTable, 100);
            return;
        }
        
        var $ = jQuery;
        
        $(document).ready(function() {
            var table = $('#productsTable');
            
            // Initialize DataTable with search functionality
            if ($.fn.DataTable) {
                if ($.fn.DataTable.isDataTable(table)) {
                    table.DataTable().destroy();
                }
                
                table.DataTable({
                    order: [[1, 'asc']], // Sort by product code
                    pageLength: 25,
                    destroy: true,
                    autoWidth: false,
                    language: {
                        emptyTable: 'No products found matching the selected filters.',
                        search: 'Search:',
                        searchPlaceholder: 'Search all columns...'
                    },
                    columns: [
                        { orderable: false, searchable: false }, // Checkbox
                        { orderable: false, searchable: false }, // Image
                        null, // Product Code
                        null, // Name
                        null, // Category
                        null, // Tax
                        null, // Brand
                        null, // Model
                        null, // Stock
                        null, // Price
                        null, // Status
                        { orderable: false, searchable: false } // Actions
                    ],
                    // Enable client-side search on all columns
                    search: {
                        smart: true,
                        regex: false
                    }
                });
            }
        });
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProductsTable);
    } else {
        initProductsTable();
    }
})();

// Bulk tax assignment functions
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.product-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateBulkTaxButton();
}

function updateBulkTaxButton() {
    const checked = document.querySelectorAll('.product-checkbox:checked');
    const btn = document.getElementById('bulkTaxBtn');
    if (btn) {
        btn.disabled = checked.length === 0;
    }
}

function showBulkTaxModal() {
    const checked = document.querySelectorAll('.product-checkbox:checked');
    document.getElementById('selectedCount').textContent = checked.length;
    document.getElementById('bulkTaxSelect').value = '';
    new bootstrap.Modal(document.getElementById('bulkTaxModal')).show();
}

function applyBulkTax() {
    const checked = document.querySelectorAll('.product-checkbox:checked');
    const productIds = Array.from(checked).map(cb => parseInt(cb.value));
    const taxId = document.getElementById('bulkTaxSelect').value;
    
    if (productIds.length === 0) {
        alert('Please select at least one product');
        return;
    }
    
    // Show loading
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Applying...';
    
    fetch('<?= BASE_URL ?>ajax/bulk_assign_tax.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            product_ids: productIds,
            tax_id: taxId ? parseInt(taxId) : null
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: `Tax assigned to ${data.updated} product(s)`,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to assign tax', 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An error occurred while assigning tax', 'error');
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

// Update button state on page load
document.addEventListener('DOMContentLoaded', function() {
    updateBulkTaxButton();
});

// Bulk Upload Functions
function showBulkUploadModal() {
    // Hide results area initially
    const resultsArea = document.getElementById('bulkUploadResults');
    if (resultsArea) {
        resultsArea.style.display = 'none';
    }
    
    // Fetch dynamic category information
    fetch('<?= BASE_URL ?>ajax/get_bulk_upload_info.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                populateBulkUploadModal(data);
                new bootstrap.Modal(document.getElementById('bulkUploadModal')).show();
            } else {
                showBulkUploadResult('Error: ' + (data.message || 'Failed to load upload information'), 'error');
                new bootstrap.Modal(document.getElementById('bulkUploadModal')).show();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showBulkUploadResult('Error: Failed to load upload information. Please try again.', 'error');
            new bootstrap.Modal(document.getElementById('bulkUploadModal')).show();
        });
}

function showBulkUploadResult(message, type) {
    const resultsArea = document.getElementById('bulkUploadResults');
    const resultsContent = document.getElementById('bulkUploadResultsContent');
    const resultsTitle = document.getElementById('bulkUploadResultsTitle');
    
    if (!resultsArea || !resultsContent || !resultsTitle) return;
    
    // Set title and styling based on type
    if (type === 'success') {
        resultsTitle.textContent = '✓ Upload Successful';
        resultsArea.style.borderColor = '#28a745';
        resultsArea.style.background = '#d4edda';
        resultsTitle.style.color = '#155724';
    } else if (type === 'error') {
        resultsTitle.textContent = '✗ Upload Failed';
        resultsArea.style.borderColor = '#dc3545';
        resultsArea.style.background = '#f8d7da';
        resultsTitle.style.color = '#721c24';
    } else if (type === 'info') {
        resultsTitle.textContent = 'ℹ Processing...';
        resultsArea.style.borderColor = '#17a2b8';
        resultsArea.style.background = '#d1ecf1';
        resultsTitle.style.color = '#0c5460';
    }
    
    // Set content
    resultsContent.innerHTML = message;
    
    // Show the results area
    resultsArea.style.display = 'block';
    
    // Scroll to top of modal to show results
    resultsArea.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearBulkUploadResults() {
    const resultsArea = document.getElementById('bulkUploadResults');
    if (resultsArea) {
        resultsArea.style.display = 'none';
        document.getElementById('bulkUploadResultsContent').innerHTML = '';
    }
}

function populateBulkUploadModal(data) {
    const categories = data.categories || [];
    const branches = data.branches || [];
    const taxes = data.taxes || [];
    
    // Build category list HTML
    let categoryListHtml = '<div class="row">';
    categories.forEach(cat => {
        const isGeneral = cat.is_general;
        const isUnique = cat.is_unique;
        let fields = [];
        
        if (isGeneral) {
            fields.push('Product Name (required)');
            fields.push('Batch Number (optional)');
            fields.push('Expiry Date (optional)');
            fields.push('Weight (optional)');
            fields.push('Unit of Measure (optional)');
            fields.push('Manufacturer (optional)');
        } else {
            fields.push('Brand (required)');
            fields.push('Model (required)');
            
            if (isUnique) {
                fields.push('Serial Number (required for unique products)');
                if (cat.name.toLowerCase().includes('smartphone') || cat.name.toLowerCase().includes('phone')) {
                    fields.push('IMEI (required for smartphones)');
                    fields.push('Storage (e.g., 128GB, 256GB)');
                    fields.push('Battery Health (0-100)');
                    fields.push('SIM Configuration (e.g., Dual SIM)');
                } else if (cat.name.toLowerCase().includes('laptop')) {
                    fields.push('Storage (e.g., 512GB SSD)');
                } else if (cat.name.toLowerCase().includes('tablet')) {
                    fields.push('Storage');
                    fields.push('Battery Health');
                }
            }
        }
        
        categoryListHtml += `
            <div class="col-md-6 mb-3">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <strong>${escapeHtml(cat.name)}</strong>
                        ${isUnique ? '<span class="badge bg-warning ms-2">Unique Product</span>' : ''}
                        ${isGeneral ? '<span class="badge bg-info ms-2">General Category</span>' : ''}
                    </div>
                    <div class="card-body">
                        <small class="text-muted">Required Fields:</small>
                        <ul class="mb-0 small">
                            ${fields.map(f => `<li>${escapeHtml(f)}</li>`).join('')}
                        </ul>
                        ${isUnique ? '<div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 3px; padding: 8px; margin-top: 10px; font-size: 0.875em; color: #856404;"><strong>Note:</strong> Quantity will be forced to 1 and Reorder Level to 0 for unique products.</div>' : ''}
                    </div>
                </div>
            </div>
        `;
    });
    categoryListHtml += '</div>';
    
    document.getElementById('categoryInfo').innerHTML = categoryListHtml;
    
    // Build branches list
    let branchesList = branches.map(b => escapeHtml(b.branch_name)).join(', ');
    document.getElementById('branchesList').textContent = branchesList || 'No branches available';
    
    // Build taxes list
    let taxesList = taxes.map(t => `${escapeHtml(t.taxName || 'Tax')} (${t.taxPercent || 0}%) - ID: ${t.taxID}`).join(', ');
    document.getElementById('taxesList').textContent = taxesList || 'No taxes configured';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function downloadTemplate() {
    window.location.href = '<?= BASE_URL ?>ajax/download_product_template.php';
}

function handleBulkUpload() {
    const fileInput = document.getElementById('bulkUploadFile');
    const file = fileInput.files[0];
    
    if (!file) {
        showBulkUploadResult('<div style="color: #721c24;"><strong>Error:</strong> Please select a CSV file to upload</div>', 'error');
        return;
    }
    
    // Validate file type
    const fileName = file.name.toLowerCase();
    if (!fileName.endsWith('.csv') && !fileName.endsWith('.txt')) {
        showBulkUploadResult('<div style="color: #721c24;"><strong>Error:</strong> Please upload a CSV file (.csv or .txt)</div>', 'error');
        return;
    }
    
    // Show processing message
    showBulkUploadResult('<div style="color: #0c5460;"><strong>Processing your bulk upload. Please wait...</strong></div>', 'info');
    
    // Disable upload button
    const uploadBtn = document.querySelector('button[onclick="handleBulkUpload()"]');
    const originalBtnText = uploadBtn ? uploadBtn.innerHTML : '';
    if (uploadBtn) {
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';
    }
    
    // Create form data
    const formData = new FormData();
    formData.append('csv_file', file);
    
    // Upload file
    fetch('<?= BASE_URL ?>ajax/process_bulk_upload.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Re-enable upload button
        if (uploadBtn) {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = originalBtnText;
        }
        
        if (data.success) {
            const summary = data.summary || {};
            let message = `<div style="color: #155724;">`;
            message += `<h6 style="color: #155724; margin-bottom: 15px;">✓ Bulk Upload Complete!</h6>`;
            message += `<p><strong>Successfully created:</strong> <span style="font-size: 1.1em; font-weight: bold;">${summary.successful || 0}</span> products</p>`;
            if (summary.errors > 0) {
                message += `<p><strong>Errors:</strong> <span style="font-size: 1.1em; font-weight: bold; color: #dc3545;">${summary.errors}</span> rows</p>`;
            }
            message += `<p><strong>Total rows processed:</strong> ${summary.total_rows || 0}</p>`;
            
            if (summary.errors > 0 && data.results && data.results.errors) {
                message += `<hr style="margin: 15px 0; border-color: #ccc;">`;
                message += `<details style="margin-top: 15px;">`;
                message += `<summary style="cursor: pointer; color: #0d6efd; font-weight: bold; margin-bottom: 10px; user-select: none;">View Error Details (Click to expand)</summary>`;
                message += `<ul style="text-align: left; max-height: 300px; overflow-y: auto; margin-top: 10px; padding-left: 20px;">`;
                data.results.errors.slice(0, 30).forEach(err => {
                    const productInfo = err.data ? ` <span style="color: #666;">(${err.data.product || err.data.category || 'N/A'})</span>` : '';
                    message += `<li style="margin-bottom: 8px; padding: 5px; background: #fff; border-left: 3px solid #dc3545;">`;
                    message += `<strong>Row ${err.row}:</strong> ${err.errors.join(', ')}${productInfo}`;
                    message += `</li>`;
                });
                if (data.results.errors.length > 30) {
                    message += `<li style="color: #666; font-style: italic; padding: 5px;">... and ${data.results.errors.length - 30} more errors</li>`;
                }
                message += `</ul></details>`;
            }
            message += `</div>`;
            
            showBulkUploadResult(message, 'success');
            
            // Reload page after 5 seconds if successful and no errors
            if (summary.errors === 0) {
                setTimeout(() => {
                    window.location.reload();
                }, 5000);
            }
        } else {
            showBulkUploadResult(`<div style="color: #721c24;"><strong>Error:</strong> ${data.message || 'Failed to process bulk upload'}</div>`, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showBulkUploadResult('<div style="color: #721c24;"><strong>Error:</strong> An error occurred while processing the upload. Please try again.</div>', 'error');
        
        // Re-enable upload button
        if (uploadBtn) {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = originalBtnText;
        }
    });
}
</script>

<!-- Bulk Upload Modal -->
<div class="modal fade" id="bulkUploadModal" tabindex="-1" aria-labelledby="bulkUploadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="bulkUploadModalLabel">
                    <i class="bi bi-upload"></i> Bulk Upload Products
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <!-- Permanent Results Display Area (No Auto-Dismiss) -->
                <div id="bulkUploadResults" style="display: none; margin-bottom: 20px; padding: 15px; border-radius: 5px; border: 2px solid #ddd; background: #f8f9fa;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h6 style="margin: 0; font-weight: bold;" id="bulkUploadResultsTitle">Upload Results</h6>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="clearBulkUploadResults()" style="padding: 2px 8px;">Clear</button>
                    </div>
                    <div id="bulkUploadResultsContent" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
                
                <div style="background: #d1ecf1; border: 2px solid #17a2b8; border-radius: 5px; padding: 15px; margin-bottom: 20px;">
                    <h6 style="color: #0c5460; margin-bottom: 10px; font-weight: bold;"><i class="bi bi-info-circle"></i> How to Use Bulk Upload</h6>
                    <ol style="margin-bottom: 0; padding-left: 20px; color: #0c5460;">
                        <li style="margin-bottom: 8px;"><strong>Download the template:</strong> Click the "Download CSV Template" button below to get a pre-formatted CSV file with example data.</li>
                        <li style="margin-bottom: 8px;"><strong>Fill in your data:</strong> Use the examples in the template as a guide. Make sure to match category names and branch names exactly as they appear in the system.</li>
                        <li style="margin-bottom: 8px;"><strong>Upload your file:</strong> Select your completed CSV file and click "Upload & Process".</li>
                        <li style="margin-bottom: 8px;"><strong>Review results:</strong> The system will show you how many products were created and any errors that occurred.</li>
                    </ol>
                </div>
                
                <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 5px; padding: 15px; margin-bottom: 20px;">
                    <h6 style="color: #856404; margin-bottom: 10px; font-weight: bold;"><i class="bi bi-exclamation-triangle"></i> Important Notes</h6>
                    <ul style="margin-bottom: 0; padding-left: 20px; color: #856404;">
                        <li style="margin-bottom: 8px;"><strong>Category Names:</strong> Must match exactly (case-sensitive). Available categories are listed below.</li>
                        <li style="margin-bottom: 8px;"><strong>Branch Names:</strong> Must match exactly (case-sensitive). Available branches: <span id="branchesList">Loading...</span></li>
                        <li style="margin-bottom: 8px;"><strong>Unique Products:</strong> Smartphones, Laptops, and Tablets will automatically have Quantity = 1 and Reorder Level = 0, regardless of what you enter.</li>
                        <li style="margin-bottom: 8px;"><strong>General Category:</strong> Requires "Product Name" field. Leave Brand and Model empty.</li>
                        <li style="margin-bottom: 8px;"><strong>Other Categories:</strong> Require "Brand" and "Model" fields. Leave Product Name empty.</li>
                        <li style="margin-bottom: 8px;"><strong>Tax ID:</strong> Optional. Available tax IDs: <span id="taxesList">Loading...</span></li>
                    </ul>
                </div>
                
                <div class="mb-4">
                    <h6><i class="bi bi-list-ul"></i> Category Information</h6>
                    <p class="text-muted">Each category has different required fields. See details below:</p>
                    <div id="categoryInfo">
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="bi bi-file-earmark-spreadsheet"></i> CSV Template Columns</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>Column Name</th>
                                        <th>Required</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Category Name</strong></td>
                                        <td><span class="badge bg-danger">Yes</span></td>
                                        <td>Must match exactly with category name in system</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Branch Name</strong></td>
                                        <td><span class="badge bg-danger">Yes</span></td>
                                        <td>Must match exactly with branch name in system</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Product Name</strong></td>
                                        <td><span class="badge bg-warning">Conditional</span></td>
                                        <td>Required for General category, leave empty for others</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Brand</strong></td>
                                        <td><span class="badge bg-warning">Conditional</span></td>
                                        <td>Required for non-General categories, leave empty for General</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Model</strong></td>
                                        <td><span class="badge bg-warning">Conditional</span></td>
                                        <td>Required for non-General categories, leave empty for General</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Color</strong></td>
                                        <td><span class="badge bg-secondary">Optional</span></td>
                                        <td>Color name or hex code (e.g., #FF0000)</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Storage</strong></td>
                                        <td><span class="badge bg-secondary">Optional</span></td>
                                        <td>For smartphones/laptops/tablets (e.g., "128GB", "512GB SSD")</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Serial Number</strong></td>
                                        <td><span class="badge bg-warning">Conditional</span></td>
                                        <td>Required for unique products (smartphones/laptops/tablets)</td>
                                    </tr>
                                    <tr>
                                        <td><strong>IMEI</strong></td>
                                        <td><span class="badge bg-warning">Conditional</span></td>
                                        <td>Required for smartphones</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Cost Price</strong></td>
                                        <td><span class="badge bg-danger">Yes</span></td>
                                        <td>Numeric value (e.g., 10.50)</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Selling Price</strong></td>
                                        <td><span class="badge bg-danger">Yes</span></td>
                                        <td>Numeric value (e.g., 15.00)</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Quantity in Stock</strong></td>
                                        <td><span class="badge bg-danger">Yes</span></td>
                                        <td>Numeric value (will be forced to 1 for unique products)</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Reorder Level</strong></td>
                                        <td><span class="badge bg-danger">Yes</span></td>
                                        <td>Numeric value (will be forced to 0 for unique products)</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Status</strong></td>
                                        <td><span class="badge bg-secondary">Optional</span></td>
                                        <td>Active or Inactive (default: Active)</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4">
                    <h6><i class="bi bi-cloud-download"></i> Step 1: Download Template</h6>
                    <button type="button" class="btn btn-primary btn-lg" onclick="downloadTemplate()">
                        <i class="bi bi-download"></i> Download CSV Template
                    </button>
                    <small class="text-muted d-block mt-2">The template includes example rows for different category types and instructions.</small>
                </div>
                
                <div class="mt-4">
                    <h6><i class="bi bi-cloud-upload"></i> Step 2: Upload Your File</h6>
                    <div class="input-group">
                        <input type="file" class="form-control" id="bulkUploadFile" accept=".csv,.txt">
                        <button type="button" class="btn btn-success" onclick="handleBulkUpload()">
                            <i class="bi bi-upload"></i> Upload & Process
                        </button>
                    </div>
                    <small class="text-muted d-block mt-2">Select your completed CSV file and click Upload to process.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
