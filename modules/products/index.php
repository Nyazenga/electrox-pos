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
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Product</a>
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
</script>
