<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.view');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    redirectTo('modules/products/index.php');
}

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get product with category tax_id
$product = $db->getRow(
    "SELECT p.*, pc.name as category_name, pc.tax_id as category_tax_id, b.branch_name 
     FROM products p 
     LEFT JOIN product_categories pc ON p.category_id = pc.id 
     LEFT JOIN branches b ON p.branch_id = b.id 
     WHERE p.id = :id", 
    [':id' => $id]
);

if (!$product) {
    redirectTo('modules/products/index.php');
}

// Check if product requires specific list
$requiresSpecificList = !empty($product['requires_specific_list']);

// Also check by category is_specific flag
if (!$requiresSpecificList && !empty($product['category_id'])) {
    $catCheck = $db->getRow("SELECT is_specific FROM product_categories WHERE id = :id", [':id' => $product['category_id']]);
    if ($catCheck && !empty($catCheck['is_specific'])) {
        $requiresSpecificList = true;
    }
}

// Get category characteristics for dynamic field display
$categoryCharacteristics = [];
if ($requiresSpecificList && !empty($product['category_id'])) {
    $categoryCharacteristics = getCategoryCharacteristics($product['category_id'], $db);
}

// Category name for legacy compatibility
$categoryName = strtolower($product['category_name'] ?? '');

// Get product_specific_list entries if product requires it
$specificListEntries = [];
if ($requiresSpecificList) {
    $specificListEntries = $db->getRows(
        "SELECT * FROM product_specific_list WHERE product_id = :product_id ORDER BY created_at DESC",
        [':product_id' => $id]
    );
    if ($specificListEntries === false) {
        $specificListEntries = [];
    }
}

// Get all applicable taxes for tax display
function getAllApplicableTaxes($primaryDb) {
    $configs = $primaryDb->getRows(
        "SELECT DISTINCT applicable_taxes FROM fiscal_config WHERE applicable_taxes IS NOT NULL AND applicable_taxes != ''"
    );
    
    $allTaxes = [];
    $seenTaxIds = [];
    
    require_once APP_PATH . '/includes/fiscal_helper.php';
    
    foreach ($configs as $config) {
        $taxes = json_decode($config['applicable_taxes'], true);
        if (is_array($taxes)) {
            // Filter out 5% Non-VAT Withholding Tax - it should NOT be fiscalized
            $taxes = filterOut5PercentTax($taxes);
            
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

$productDisplayName = !empty($product['product_name']) ? $product['product_name'] : ($product['brand'] . ' ' . $product['model']);
$pageTitle = 'View Product - ' . escapeHtml($productDisplayName);

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Product Details</h2>
    <div>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        <?php if ($requiresSpecificList && $auth->hasPermission('products.edit')): ?>
            <button type="button" class="btn btn-primary" onclick="openManageSpecificItemsModal()">
                <i class="bi bi-list-ul"></i> Manage Specific Items
            </button>
        <?php endif; ?>
        <?php if ($auth->hasPermission('products.edit')): ?>
            <a href="edit.php?id=<?= $product['id'] ?>" class="btn btn-warning"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <?php 
                $images = !empty($product['images']) ? json_decode($product['images'], true) : [];
                if (!empty($images)): 
                ?>
                    <div class="product-image-container mb-3" style="position: relative; display: inline-block; cursor: pointer;" onclick="uploadProductImage(<?= $product['id'] ?>)">
                        <img src="<?= escapeHtml($images[0]) ?>" alt="<?= escapeHtml($product['brand'] . ' ' . $product['model']) ?>" class="img-fluid" style="max-height: 300px; border-radius: 8px;">
                        <div class="image-upload-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; border-radius: 8px;">
                            <i class="bi bi-camera text-white" style="font-size: 24px;"></i>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="product-image-container mb-3" style="position: relative; display: inline-block; cursor: pointer;" onclick="uploadProductImage(<?= $product['id'] ?>)">
                        <?php if (!$requiresSpecificList && !empty($product['color']) && $product['color'] !== '#ffffff' && $product['color'] !== 'white'): ?>
                            <div class="p-5" style="border-radius: 8px; background-color: <?= escapeHtml($product['color']) ?>; min-height: 200px; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-box-seam" style="font-size: 48px; color: rgba(0,0,0,0.3);"></i>
                            </div>
                        <?php else: ?>
                            <div class="bg-light p-5" style="border-radius: 8px;">
                                <i class="bi bi-box-seam" style="font-size: 48px; color: #9ca3af;"></i>
                            </div>
                        <?php endif; ?>
                        <div class="image-upload-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; border-radius: 8px;">
                            <i class="bi bi-camera text-white" style="font-size: 24px;"></i>
                        </div>
                    </div>
                <?php endif; ?>
                <h4><?= escapeHtml(!empty($product['product_name']) ? $product['product_name'] : ($product['brand'] . ' ' . $product['model'])) ?></h4>
                <p class="text-muted"><?= escapeHtml($product['product_code']) ?></p>
                <?php if (!$requiresSpecificList && !empty($product['color']) && $product['color'] !== '#ffffff' && $product['color'] !== 'white'): ?>
                    <div class="mt-2">
                        <span class="d-inline-flex align-items-center gap-2">
                            <span class="badge" style="background-color: <?= escapeHtml($product['color']) ?>; width: 40px; height: 40px; border: 2px solid #ddd; border-radius: 4px; display: inline-block;"></span>
                            <span>Color</span>
                        </span>
                    </div>
                <?php elseif ($requiresSpecificList && !empty($specificListEntries)): ?>
                    <div class="mt-2">
                        <span class="badge bg-info"><?= count($specificListEntries) ?> instances</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Product Information</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($product['product_name'])): ?>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <strong>Product Name:</strong> <?= escapeHtml($product['product_name']) ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Brand:</strong> <?= escapeHtml($product['brand'] ?? 'N/A') ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Model:</strong> <?= escapeHtml($product['model'] ?? 'N/A') ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Category:</strong> <?= escapeHtml($product['category_name'] ?? 'N/A') ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Branch:</strong> <?= escapeHtml($product['branch_name'] ?? 'N/A') ?>
                    </div>
                </div>
                <?php if ($requiresSpecificList): ?>
                    <!-- Product Specific List Section (Dynamic from Category Characteristics) -->
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <strong>Individual Instances:</strong> 
                            <span class="badge bg-info"><?= count($specificListEntries) ?> available</span>
                        </div>
                    </div>
                    <?php if (!empty($specificListEntries)): ?>
                        <?php
                        // Build display columns from category characteristics
                        $viewCharFields = [];
                        foreach ($categoryCharacteristics as $char) {
                            $viewCharFields[] = [
                                'label' => $char['label'],
                                'column' => resolveCharacteristicListColumn($char),
                                'field_type' => $char['field_type'],
                            ];
                        }
                        ?>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <?php foreach ($viewCharFields as $cf): ?>
                                            <th><?= escapeHtml($cf['label']) ?></th>
                                        <?php endforeach; ?>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($specificListEntries as $entry): ?>
                                        <tr>
                                            <?php foreach ($viewCharFields as $cf): 
                                                $col = $cf['column'];
                                                $val = $entry[$col] ?? '';
                                            ?>
                                                <td>
                                                    <?php if ($col === 'color' && !empty($val)): ?>
                                                        <?= escapeHtml($val) ?>
                                                    <?php elseif ($col === 'condition' && !empty($val)): ?>
                                                        <span class="badge bg-<?= $val == 'New' ? 'success' : ($val == 'Refurbished' ? 'info' : 'warning') ?>">
                                                            <?= escapeHtml($val) ?>
                                                        </span>
                                                    <?php elseif ($col === 'battery_health' && !empty($val)): ?>
                                                        <span class="badge bg-<?= $val >= 80 ? 'success' : ($val >= 60 ? 'warning' : 'danger') ?>">
                                                            <?= escapeHtml($val) ?>%
                                                        </span>
                                                    <?php elseif ($col === 'warranty_months' && !empty($val) && $val > 0): ?>
                                                        <?= escapeHtml($val) ?> months
                                                    <?php elseif ($col === 'trade_in_eligible'): ?>
                                                        <?= $val ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>' ?>
                                                    <?php elseif (!empty($val)): ?>
                                                        <?= escapeHtml($val) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td>
                                                <span class="badge bg-<?= $entry['status'] == 'available' ? 'success' : ($entry['status'] == 'sold' ? 'danger' : ($entry['status'] == 'transferred' ? 'info' : 'secondary')) ?>">
                                                    <?= escapeHtml(ucfirst($entry['status'] ?? 'available')) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> No specific instances have been added yet. 
                            <div class="mt-2">
                                <button type="button" class="btn btn-primary btn-sm" onclick="openManageSpecificItemsModal()">
                                    <i class="bi bi-list-ul"></i> Manage Specific Items
                                </button>
                                <span class="ms-2">or add them via GRN, Stock Take, or Bulk Upload.</span>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Legacy fields for products that don't require specific list (backward compatibility) -->
                <?php if (!empty($product['color'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Color:</strong> 
                        <span class="d-inline-flex align-items-center gap-2">
                            <span class="badge" style="background-color: <?= escapeHtml($product['color']) ?>; width: 40px; height: 40px; border: 2px solid #ddd; border-radius: 4px; display: inline-block;"></span>
                        </span>
                    </div>
                    <?php if (!empty($product['storage'])): ?>
                    <div class="col-md-6">
                        <strong>Storage:</strong> <?= escapeHtml($product['storage']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php elseif (!empty($product['storage'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Storage:</strong> <?= escapeHtml($product['storage']) ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($product['condition'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Condition:</strong> 
                        <span class="badge bg-<?= $product['condition'] == 'New' ? 'success' : ($product['condition'] == 'Refurbished' ? 'info' : 'warning') ?>">
                            <?= escapeHtml($product['condition']) ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($product['serial_number'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Serial Number:</strong> <?= escapeHtml($product['serial_number']) ?>
                    </div>
                    <?php if (!empty($product['imei'])): ?>
                    <div class="col-md-6">
                        <strong>IMEI:</strong> <?= escapeHtml($product['imei']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php elseif (!empty($product['imei'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>IMEI:</strong> <?= escapeHtml($product['imei']) ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($product['sim_configuration'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>SIM Configuration:</strong> <?= escapeHtml($product['sim_configuration']) ?>
                    </div>
                </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($product['sku'])): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>SKU:</strong> <?= escapeHtml($product['sku']) ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Cost Price:</strong> <?= formatCurrency($product['cost_price']) ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Selling Price:</strong> <?= formatCurrency($product['selling_price']) ?>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Stock Quantity:</strong> <?= $product['quantity_in_stock'] ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Reorder Level:</strong> <?= $product['reorder_level'] ?>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Status:</strong> 
                        <span class="badge bg-<?= $product['status'] == 'Active' ? 'success' : 'secondary' ?>">
                            <?= escapeHtml($product['status']) ?>
                        </span>
                    </div>
                    <div class="col-md-6">
                        <strong>Barcode:</strong> <?= escapeHtml($product['barcode'] ?? 'N/A') ?>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Tax / Charges:</strong> 
                        <?php if ($displayTax): ?>
                            <span class="badge bg-info" title="Tax from: <?= $taxSource ?>">
                                <?= escapeHtml($displayTax['taxName'] ?? 'Tax') ?> (<?= $displayTax['taxPercent'] !== null ? number_format($displayTax['taxPercent'], 2) : 'Exempt' ?>%)
                            </span>
                        <?php else: ?>
                            <span class="text-muted">Auto</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($product['description']): ?>
                    <div class="mb-3">
                        <strong>Description:</strong>
                        <p><?= escapeHtml($product['description']) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($product['specifications']): ?>
                    <div class="mb-3">
                        <strong>Specifications:</strong>
                        <pre class="bg-light p-3 rounded"><?= escapeHtml($product['specifications']) ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.product-image-container:hover .image-upload-overlay {
    display: flex !important;
}
.form-control.is-invalid, .form-select.is-invalid {
    border-color: #dc3545;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath d='m5.8 3.6 .4.4.4-.4m0 4.8-.4-.4-.4.4'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
    padding-right: calc(1.5em + 0.75rem);
}
</style>

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
                        <small class="text-muted">You can upload multiple images by selecting multiple files</small>
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
    
    Swal.fire({
        title: 'Uploading...',
        text: 'Please wait while we upload the image',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
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
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An unexpected error occurred', 'error');
    });
}
</script>

<!-- Manage Specific Items Modal -->
<?php if ($requiresSpecificList): ?>
<?php
// Build characteristic field definitions from category assignments
// These are the dynamic fields from category_characteristics
$charFields = [];
$hasSerialNumber = false;
$hasIMEI = false;
foreach ($categoryCharacteristics as $char) {
    $col = resolveCharacteristicListColumn($char);
    $charFields[] = [
        'name' => $char['name'],
        'label' => $char['label'],
        'column' => $col,
        'field_type' => $char['field_type'],
        'is_required' => !empty($char['is_required']),
        'options' => !empty($char['options']) ? json_decode($char['options'], true) : [],
    ];
    if ($col === 'serial_number') $hasSerialNumber = true;
    if ($col === 'imei') $hasIMEI = true;
}

// Always-present columns for specific items (pricing, status, actions)
$alwaysColumns = ['cost_price', 'selling_price', 'wholesale_price', 'status'];
?>
<div class="modal fade" id="manageSpecificItemsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manage Specific Items - <?= escapeHtml($productDisplayName) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-success" onclick="addSpecificItemRow()">
                        <i class="bi bi-plus-circle"></i> Add Item
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addMultipleItems()">
                        <i class="bi bi-plus-circle-fill"></i> Add Multiple Items
                    </button>
                </div>
                <div id="specificItemsContainer" class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <?php foreach ($charFields as $cf): ?>
                                    <th><?= escapeHtml($cf['label']) ?><?= $cf['is_required'] ? ' *' : '' ?></th>
                                <?php endforeach; ?>
                                <th>Cost Price</th>
                                <th>Selling Price *</th>
                                <th>Wholesale Price</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="specificItemsTableBody">
                            <!-- Items will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveSpecificItems()">
                    <i class="bi bi-save"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let specificItemsData = [];

// Dynamic characteristic fields from PHP (category assignments)
const charFields = <?= json_encode($charFields) ?>;
const hasSerialNumber = <?= $hasSerialNumber ? 'true' : 'false' ?>;
const hasIMEI = <?= $hasIMEI ? 'true' : 'false' ?>;
const totalColumns = charFields.length + 5; // char fields + cost + selling + wholesale + status + actions

function openManageSpecificItemsModal() {
    loadSpecificItems();
    new bootstrap.Modal(document.getElementById('manageSpecificItemsModal')).show();
}

function loadSpecificItems() {
    fetch('<?= BASE_URL ?>ajax/get_product_specific_list.php?product_id=<?= $product['id'] ?>&branch_id=<?= $product['branch_id'] ?? $_SESSION['branch_id'] ?? '' ?>&show_all=1')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                specificItemsData = data.entries || [];
                renderSpecificItemsTable();
            } else {
                Swal.fire('Error', data.message || 'Failed to load items', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load items', 'error');
        });
}

/**
 * Build an input cell HTML for a given characteristic field
 */
function buildCharFieldCell(index, field, item) {
    const col = field.column;
    const val = item[col] || '';
    const escapedVal = escapeHtml(String(val));
    
    // Select field type
    if (field.field_type === 'select' && field.options && field.options.length > 0) {
        let optionsHtml = '<option value="">Select</option>';
        field.options.forEach(opt => {
            const selected = (String(val) === String(opt)) ? 'selected' : '';
            optionsHtml += `<option value="${escapeHtml(opt)}" ${selected}>${escapeHtml(opt)}</option>`;
        });
        return `<td><select class="form-select form-select-sm" onchange="updateItemField(${index}, '${col}', this.value)">${optionsHtml}</select></td>`;
    }
    
    // Number field type
    if (field.field_type === 'number') {
        let min = 0, max = '', step = '1', placeholder = '0';
        if (col === 'battery_health') { max = '100'; placeholder = '0-100'; }
        if (col === 'warranty_months') { max = '999'; placeholder = 'Months'; }
        return `<td><input type="number" class="form-control form-control-sm" 
                    value="${escapedVal}" 
                    onchange="updateItemField(${index}, '${col}', this.value)"
                    min="${min}" ${max ? 'max="' + max + '"' : ''} step="${step}"
                    placeholder="${placeholder}"></td>`;
    }
    
    // Boolean field type
    if (field.field_type === 'boolean') {
        const checked = val == 1 || val === true || val === 'true' || val === '1' ? 'checked' : '';
        return `<td class="text-center"><input type="checkbox" class="form-check-input" 
                    ${checked}
                    onchange="updateItemField(${index}, '${col}', this.checked ? 1 : 0)"></td>`;
    }
    
    // Textarea field type
    if (field.field_type === 'textarea') {
        return `<td><textarea class="form-control form-control-sm" rows="1"
                    onchange="updateItemField(${index}, '${col}', this.value.trim())"
                    placeholder="${escapeHtml(field.label)}">${escapedVal}</textarea></td>`;
    }
    
    // Text (default) - with special handling for IMEI and serial_number
    let placeholder = escapeHtml(field.label);
    let extraAttrs = '';
    let extraEvents = '';
    
    if (col === 'serial_number') {
        placeholder = field.is_required ? 'Required' : field.label;
        extraAttrs = `maxlength="100" data-index="${index}" ${field.is_required ? 'required' : ''}`;
        extraEvents = `onblur="validateSerialNumber(${index}, this)"`;
    } else if (col === 'imei') {
        placeholder = '15 digits';
        extraAttrs = `maxlength="15" pattern="[0-9]{15}" data-index="${index}"`;
        extraEvents = `onblur="validateIMEI(${index}, this)"`;
    } else if (col === 'color') {
        placeholder = 'e.g., Red, Blue, Black';
        extraAttrs = `maxlength="50"`;
    } else if (col === 'storage') {
        placeholder = 'e.g., 128GB';
        extraAttrs = `maxlength="50"`;
    } else if (col === 'manufacturer') {
        placeholder = 'Manufacturer';
        extraAttrs = `maxlength="100"`;
    } else {
        extraAttrs = `maxlength="255"`;
    }
    
    return `<td><input type="text" class="form-control form-control-sm" 
                value="${escapedVal}" 
                onchange="updateItemField(${index}, '${col}', this.value.trim())"
                ${extraEvents}
                ${extraAttrs}
                placeholder="${placeholder}"></td>`;
}

function renderSpecificItemsTable() {
    const tbody = document.getElementById('specificItemsTableBody');
    tbody.innerHTML = '';
    
    if (specificItemsData.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${totalColumns}" class="text-center text-muted">No items added yet. Click "Add Item" to add one.</td></tr>`;
        return;
    }
    
    specificItemsData.forEach((item, index) => {
        const row = document.createElement('tr');
        let html = '';
        
        // Dynamic characteristic columns
        charFields.forEach(field => {
            html += buildCharFieldCell(index, field, item);
        });
        
        // Always-present pricing columns
        html += `
            <td>
                <input type="number" step="0.01" class="form-control form-control-sm" 
                       value="${item.cost_price || ''}" 
                       onchange="updateItemField(${index}, 'cost_price', this.value)"
                       min="0" placeholder="0.00">
            </td>
            <td>
                <input type="number" step="0.01" class="form-control form-control-sm" 
                       value="${item.selling_price || ''}" 
                       onchange="updateItemField(${index}, 'selling_price', this.value)"
                       min="0" placeholder="0.00" required>
            </td>
            <td>
                <input type="number" step="0.01" class="form-control form-control-sm" 
                       value="${item.wholesale_price || ''}" 
                       onchange="updateItemField(${index}, 'wholesale_price', this.value)"
                       min="0" placeholder="Optional">
            </td>
            <td>
                <span class="badge bg-${item.status === 'available' ? 'success' : (item.status === 'sold' ? 'danger' : 'secondary')}">
                    ${escapeHtml(item.status || 'available')}
                </span>
            </td>
            <td>
                ${item.id ? `
                    <button type="button" class="btn btn-sm btn-danger" onclick="deleteSpecificItem(${index}, ${item.id})">
                        <i class="bi bi-trash"></i>
                    </button>
                ` : `
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeSpecificItemRow(${index})">
                        <i class="bi bi-x"></i>
                    </button>
                `}
            </td>
        `;
        
        row.innerHTML = html;
        tbody.appendChild(row);
    });
}


function updateItemField(index, field, value) {
    if (index >= 0 && index < specificItemsData.length) {
        specificItemsData[index][field] = value;
    }
}

function validateSerialNumber(index, input) {
    const value = input.value.trim();
    if (!value) {
        input.classList.add('is-invalid');
    } else {
        input.classList.remove('is-invalid');
        updateItemField(index, 'serial_number', value);
    }
}

function validateIMEI(index, input) {
    const value = input.value.trim();
    if (value && !/^\d{15}$/.test(value)) {
        input.classList.add('is-invalid');
        Swal.fire({
            icon: 'warning',
            title: 'Invalid IMEI',
            text: 'IMEI must be exactly 15 digits',
            timer: 2000,
            showConfirmButton: false
        });
    } else {
        input.classList.remove('is-invalid');
        if (value) {
            updateItemField(index, 'imei', value);
        }
    }
}

function addSpecificItemRow() {
    // Build new item with only the assigned characteristic columns + always fields
    const newItem = {
        id: null,
        product_id: <?= $product['id'] ?>,
        branch_id: <?= $product['branch_id'] ?? $_SESSION['branch_id'] ?? 'null' ?>,
        cost_price: '',
        selling_price: '',
        wholesale_price: '',
        status: 'available'
    };
    
    // Add default values for each assigned characteristic
    charFields.forEach(field => {
        const col = field.column;
        if (field.field_type === 'boolean') {
            newItem[col] = 0;
        } else if (field.field_type === 'number') {
            newItem[col] = '';
        } else if (col === 'condition') {
            newItem[col] = 'New';
        } else {
            newItem[col] = '';
        }
    });
    
    specificItemsData.push(newItem);
    renderSpecificItemsTable();
}

function addMultipleItems() {
    Swal.fire({
        title: 'Add Multiple Items',
        html: `
            <div class="mb-3">
                <label>Number of items to add:</label>
                <input type="number" id="itemCount" class="form-control" value="1" min="1" max="50">
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Add',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
            const count = parseInt(document.getElementById('itemCount').value) || 1;
            if (count < 1 || count > 50) {
                Swal.showValidationMessage('Please enter a number between 1 and 50');
                return false;
            }
            return count;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const count = result.value;
            for (let i = 0; i < count; i++) {
                addSpecificItemRow();
            }
        }
    });
}

function removeSpecificItemRow(index) {
    specificItemsData.splice(index, 1);
    renderSpecificItemsTable();
}

function deleteSpecificItem(index, itemId) {
    Swal.fire({
        title: 'Delete Item?',
        text: 'This will permanently delete this item. Are you sure?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= BASE_URL ?>ajax/manage_product_specific_list.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete&id=${itemId}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    specificItemsData.splice(index, 1);
                    renderSpecificItemsTable();
                    Swal.fire('Success', 'Item deleted successfully', 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    Swal.fire('Error', data.message || 'Failed to delete item', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', 'Failed to delete item', 'error');
            });
        }
    });
}

function saveSpecificItems() {
    // Validate all items before saving
    const validationErrors = [];
    
    for (let i = 0; i < specificItemsData.length; i++) {
        const item = specificItemsData[i];
        const itemNum = i + 1;
        
        // Validate required characteristic fields
        charFields.forEach(field => {
            const col = field.column;
            if (field.is_required) {
                const val = item[col];
                if (!val || (typeof val === 'string' && val.trim() === '')) {
                    validationErrors.push(`Item ${itemNum}: ${field.label} is required`);
                }
            }
        });
        
        // Validate IMEI format if present
        if (hasIMEI && item.imei && item.imei.trim() !== '') {
            if (!/^\d{15}$/.test(item.imei.trim())) {
                validationErrors.push(`Item ${itemNum}: IMEI must be exactly 15 digits`);
            }
        }
        
        // If has both serial_number and imei fields, at least one is needed
        if (hasSerialNumber && hasIMEI) {
            if (!item.serial_number && !item.imei) {
                validationErrors.push(`Item ${itemNum}: Must have either Serial Number or IMEI`);
            }
        }
        
        // Validate selling price is required
        if (!item.selling_price || parseFloat(item.selling_price) <= 0) {
            validationErrors.push(`Item ${itemNum}: Selling Price is required and must be greater than 0`);
        }
        
        // Validate field lengths
        if (item.color && item.color.length > 50) {
            validationErrors.push(`Item ${itemNum}: Color cannot exceed 50 characters`);
        }
        if (item.storage && item.storage.length > 50) {
            validationErrors.push(`Item ${itemNum}: Storage cannot exceed 50 characters`);
        }
        if (item.manufacturer && item.manufacturer.length > 100) {
            validationErrors.push(`Item ${itemNum}: Manufacturer cannot exceed 100 characters`);
        }
        if (item.serial_number && item.serial_number.length > 100) {
            validationErrors.push(`Item ${itemNum}: Serial Number cannot exceed 100 characters`);
        }
    }
    
    if (validationErrors.length > 0) {
        Swal.fire({
            icon: 'error',
            title: 'Validation Errors',
            html: validationErrors.join('<br>'),
            width: '600px'
        });
        return;
    }
    
    // Separate new items from existing ones
    const newItems = specificItemsData.filter(item => !item.id);
    const existingItems = specificItemsData.filter(item => item.id);
    
    if (newItems.length === 0 && existingItems.length === 0) {
        Swal.fire('Info', 'No items to save', 'info');
        return;
    }
    
    Swal.fire({
        title: 'Saving...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    // Save new items and update existing items
    const savePromises = [];
    
    if (newItems.length > 0) {
        const formData = new FormData();
        formData.append('action', 'add');
        formData.append('product_id', <?= $product['id'] ?>);
        formData.append('branch_id', <?= $product['branch_id'] ?? $_SESSION['branch_id'] ?? 'null' ?>);
        formData.append('entries', JSON.stringify(newItems));
        
        savePromises.push(
            fetch('<?= BASE_URL ?>ajax/manage_product_specific_list.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json())
        );
    }
    
    // Update existing items
    if (existingItems.length > 0) {
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('entries', JSON.stringify(existingItems));
        
        savePromises.push(
            fetch('<?= BASE_URL ?>ajax/manage_product_specific_list.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json())
        );
    }
    
    Promise.all(savePromises)
        .then(results => {
            let added = 0;
            let updated = 0;
            let errors = [];
            
            results.forEach(result => {
                if (result.success) {
                    if (result.added) added += result.added;
                    if (result.updated) updated += result.updated;
                    if (result.errors) errors = errors.concat(result.errors);
                } else {
                    if (result.errors && result.errors.length > 0) {
                        errors = errors.concat(result.errors);
                    } else if (result.message) {
                        errors.push(result.message || 'Failed to save');
                    } else {
                        errors.push('Failed to save');
                    }
                }
            });
            
            if (added > 0 || updated > 0) {
                let message = '';
                let hasErrors = errors.length > 0;
                
                if (added > 0 && updated > 0) {
                    message = `Added ${added} item(s) and updated ${updated} item(s) successfully`;
                } else if (added > 0) {
                    message = `Added ${added} item(s) successfully`;
                } else {
                    message = `Updated ${updated} item(s) successfully`;
                }
                
                if (hasErrors) {
                    message += '<br><br><strong>Some errors occurred:</strong><br>' + errors.join('<br>');
                }
                
                Swal.fire({
                    icon: hasErrors ? 'warning' : 'success',
                    title: hasErrors ? 'Partial Success' : 'Success',
                    html: message,
                    width: '600px'
                }).then(() => {
                    window.location.reload();
                });
            } else {
                let errorMessage = errors.length > 0 ? errors.join('<br>') : 'Failed to save items';
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    html: errorMessage,
                    width: '600px'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to save items', 'error');
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
<?php endif; ?>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

