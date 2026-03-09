<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.view');

$pageTitle = 'Product Categories';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Function to get applicable taxes for all branches (for category tax assignment)
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

// Build categories query with branch filtering
$categoryQuery = "SELECT pc.*, COUNT(p.id) as product_count,
                  (SELECT COUNT(*) FROM category_characteristic_assignments cca WHERE cca.category_id = pc.id) as char_count
                  FROM product_categories pc 
                  LEFT JOIN products p ON pc.id = p.category_id";
$categoryParams = [];

if ($branchId !== null) {
    $categoryQuery .= " AND (p.branch_id = :branch_id OR p.branch_id IS NULL)";
    $categoryParams[':branch_id'] = $branchId;
}

$categoryQuery .= " GROUP BY pc.id ORDER BY pc.name";
$categories = $db->getRows($categoryQuery, $categoryParams);

// Get all characteristics for the picker
$allCharacteristics = $db->getRows(
    "SELECT * FROM category_characteristics WHERE is_active = 1 ORDER BY sort_order, label"
);

require_once APP_PATH . '/includes/header.php';
?>

<style>
.characteristic-item {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    margin-bottom: 6px;
    background: #fff;
    transition: all 0.2s;
}
.characteristic-item:hover {
    border-color: #3b82f6;
    background: #f8fafc;
}
.characteristic-item .form-check {
    flex: 1;
}
.characteristic-item .badge {
    font-size: 0.7rem;
}
.characteristic-item .required-toggle {
    margin-left: 8px;
}
.char-badge {
    display: inline-block;
    padding: 2px 8px;
    margin: 1px 2px;
    font-size: 0.72rem;
    border-radius: 12px;
    background: #e2e8f0;
    color: #475569;
}
.char-badge.required {
    background: #dbeafe;
    color: #1e40af;
    font-weight: 500;
}
.specific-badge {
    font-size: 0.72rem;
    padding: 3px 8px;
    border-radius: 12px;
}
#characteristicsSection {
    transition: all 0.3s ease;
}
.chars-manage-table .btn-group .btn {
    padding: 2px 8px;
    font-size: 0.78rem;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Product Categories</h2>
    <div>
        <?php if ($auth->hasPermission('products.create')): ?>
            <button class="btn btn-outline-primary me-2" onclick="manageCharacteristics()">
                <i class="bi bi-sliders"></i> Manage Characteristics
            </button>
            <button class="btn btn-primary" onclick="showAddCategory()">
                <i class="bi bi-plus-circle"></i> Add Category
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Type</th>
                    <th>Products</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $category): ?>
                <tr>
                    <td>
                        <?= escapeHtml($category['name']) ?>
                    </td>
                    <td><?= escapeHtml($category['description'] ?? 'N/A') ?></td>
                    <td>
                        <?php if ($category['is_specific']): ?>
                            <span class="badge bg-info specific-badge"><i class="bi bi-fingerprint"></i> Specific</span>
                            <?php if ($category['char_count'] > 0): ?>
                                <br><small class="text-muted"><?= $category['char_count'] ?> characteristic<?= $category['char_count'] != 1 ? 's' : '' ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary specific-badge">Standard</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-primary"><?= $category['product_count'] ?></span>
                        <?php if ($category['tax_id']): 
                            $categoryTax = null;
                            foreach ($allTaxes as $tax) {
                                if (($tax['taxID'] ?? null) == $category['tax_id']) {
                                    $categoryTax = $tax;
                                    break;
                                }
                            }
                            if ($categoryTax):
                        ?>
                            <br><small class="text-muted">Tax: <?= escapeHtml($categoryTax['taxName'] ?? 'Tax') ?> (<?= $categoryTax['taxPercent'] ?? 0 ?>%)</small>
                        <?php endif; endif; ?>
                    </td>
                    <td><?= formatDate($category['created_at']) ?></td>
                    <td>
                        <?php if ($auth->hasPermission('products.edit')): ?>
                            <button class="btn btn-sm btn-warning" onclick="editCategory(<?= $category['id'] ?>)" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                        <?php endif; ?>
                        <?php if ($category['is_specific']): ?>
                            <button class="btn btn-sm btn-info text-white" onclick="viewCharacteristics(<?= $category['id'] ?>, '<?= escapeHtml($category['name']) ?>')" title="View Characteristics">
                                <i class="bi bi-list-check"></i>
                            </button>
                        <?php endif; ?>
                        <?php if ($auth->hasPermission('products.delete') && $category['product_count'] == 0): ?>
                            <button class="btn btn-sm btn-danger" onclick="deleteCategory(<?= $category['id'] ?>)" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryModalTitle">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="categoryForm">
                <div class="modal-body">
                    <input type="hidden" id="categoryId" name="id">
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Category Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="categoryName" name="name" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Default Tax</label>
                            <select class="form-control" id="categoryTaxId" name="tax_id">
                                <option value="">No Default Tax</option>
                                <?php foreach ($allTaxes as $tax): 
                                    $taxDisplay = sprintf(
                                        "%s (%.2f%%)",
                                        $tax['taxName'] ?? 'Tax',
                                        $tax['taxPercent'] ?? 0
                                    );
                                ?>
                                    <option value="<?= $tax['taxID'] ?? '' ?>"><?= escapeHtml($taxDisplay) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="categoryDescription" name="description" rows="2"></textarea>
                    </div>
                    
                    <!-- Specific Products Toggle -->
                    <div class="card mb-3" style="border: 2px solid #e2e8f0;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><i class="bi bi-fingerprint text-info"></i> Specific / Unique Products</h6>
                                    <small class="text-muted">
                                        Enable this if products in this category are unique items requiring individual tracking 
                                        (e.g., phones with serial numbers, laptops with specific configurations).
                                    </small>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="isSpecific" name="is_specific" 
                                           style="width: 3em; height: 1.5em;" onchange="toggleCharacteristicsSection()">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Characteristics Selection Section -->
                    <div id="characteristicsSection" style="display: none;">
                        <div class="card border-info">
                            <div class="card-header bg-info bg-opacity-10">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0"><i class="bi bi-list-check"></i> Select Characteristics</h6>
                                    <small class="text-muted">Tick the characteristics that apply to products in this category</small>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllChars()">Select All</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllChars()">Deselect All</button>
                                </div>
                                <div id="characteristicsList">
                                    <?php foreach ($allCharacteristics as $char): ?>
                                    <div class="characteristic-item" data-char-id="<?= $char['id'] ?>">
                                        <div class="form-check flex-grow-1">
                                            <input class="form-check-input char-checkbox" type="checkbox" 
                                                   id="char_<?= $char['id'] ?>" 
                                                   value="<?= $char['id'] ?>"
                                                   data-name="<?= escapeHtml($char['name']) ?>">
                                            <label class="form-check-label" for="char_<?= $char['id'] ?>">
                                                <strong><?= escapeHtml($char['label']) ?></strong>
                                                <?php if ($char['is_system']): ?>
                                                    <span class="badge bg-secondary ms-1">System</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success ms-1">Custom</span>
                                                <?php endif; ?>
                                                <span class="badge bg-light text-dark ms-1"><?= escapeHtml($char['field_type']) ?></span>
                                            </label>
                                            <?php if ($char['description']): ?>
                                                <br><small class="text-muted ms-4"><?= escapeHtml($char['description']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="required-toggle">
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input char-required" type="checkbox" 
                                                       id="char_req_<?= $char['id'] ?>" disabled>
                                                <label class="form-check-label" for="char_req_<?= $char['id'] ?>">
                                                    <small>Required</small>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Characteristics Modal -->
<div class="modal fade" id="characteristicsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-sliders"></i> Manage Characteristics</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">
                    Characteristics define the attributes that can be tracked for specific/unique products 
                    (e.g., Color, Serial Number, Storage Size). System characteristics are built-in and cannot be deleted.
                </p>
                
                <!-- Add New Characteristic Form -->
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-plus-circle"></i> Add New Characteristic</h6>
                    </div>
                    <div class="card-body">
                        <form id="addCharForm" onsubmit="return addCharacteristic(event)">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label">Name (key) <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="newCharName" 
                                           placeholder="e.g. screen_size" required
                                           pattern="[a-z0-9_]+" title="Lowercase letters, numbers, and underscores only">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Display Label <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="newCharLabel" 
                                           placeholder="e.g. Screen Size" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Field Type</label>
                                    <select class="form-control form-control-sm" id="newCharFieldType" onchange="toggleOptionsField()">
                                        <option value="text">Text</option>
                                        <option value="number">Number</option>
                                        <option value="select">Dropdown</option>
                                        <option value="color">Color</option>
                                        <option value="boolean">Yes/No</option>
                                        <option value="textarea">Text Area</option>
                                        <option value="date">Date</option>
                                    </select>
                                </div>
                                <div class="col-md-3" id="optionsFieldWrapper" style="display:none;">
                                    <label class="form-label">Options</label>
                                    <input type="text" class="form-control form-control-sm" id="newCharOptions" 
                                           placeholder="Comma-separated: S,M,L,XL">
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-plus-lg"></i> Add
                                    </button>
                                </div>
                            </div>
                            <div class="row g-2 mt-1">
                                <div class="col-md-12">
                                    <input type="text" class="form-control form-control-sm" id="newCharDescription" 
                                           placeholder="Description (optional)">
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Existing Characteristics Table -->
                <div class="table-responsive chars-manage-table">
                    <table class="table table-sm table-hover" id="characteristicsTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Label</th>
                                <th>Name (Key)</th>
                                <th>Type</th>
                                <th>Options</th>
                                <th>System</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="characteristicsTableBody">
                            <!-- Loaded via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Characteristic Modal -->
<div class="modal fade" id="editCharModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Characteristic</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editCharForm" onsubmit="return updateCharacteristic(event)">
                <div class="modal-body">
                    <input type="hidden" id="editCharId">
                    <div class="mb-3">
                        <label class="form-label">Display Label <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="editCharLabel" required>
                    </div>
                    <div class="mb-3" id="editCharNameGroup">
                        <label class="form-label">Name (Key)</label>
                        <input type="text" class="form-control" id="editCharName" 
                               pattern="[a-z0-9_]+" title="Lowercase letters, numbers, and underscores only">
                        <small class="text-muted">Lowercase letters, numbers, and underscores only</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Field Type</label>
                        <select class="form-control" id="editCharFieldType" onchange="toggleEditOptionsField()">
                            <option value="text">Text</option>
                            <option value="number">Number</option>
                            <option value="select">Dropdown</option>
                            <option value="color">Color</option>
                            <option value="boolean">Yes/No</option>
                            <option value="textarea">Text Area</option>
                            <option value="date">Date</option>
                        </select>
                    </div>
                    <div class="mb-3" id="editOptionsFieldWrapper" style="display:none;">
                        <label class="form-label">Options (comma-separated)</label>
                        <input type="text" class="form-control" id="editCharOptions" placeholder="Option1, Option2, Option3">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" id="editCharDescription">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Category Characteristics Modal -->
<div class="modal fade" id="viewCharsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewCharsTitle">Category Characteristics</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewCharsBody">
                <!-- Loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';

// ═══════════════════════════════════════════════════
// CATEGORY CRUD
// ═══════════════════════════════════════════════════

function showAddCategory() {
    document.getElementById('categoryModalTitle').textContent = 'Add Category';
    document.getElementById('categoryForm').reset();
    document.getElementById('categoryId').value = '';
    document.getElementById('isSpecific').checked = false;
    toggleCharacteristicsSection();
    
    // Uncheck all characteristics
    document.querySelectorAll('.char-checkbox').forEach(cb => {
        cb.checked = false;
    });
    document.querySelectorAll('.char-required').forEach(cb => {
        cb.checked = false;
        cb.disabled = true;
    });
    
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

function editCategory(id) {
    fetch(BASE_URL + 'ajax/get_category.php?id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const cat = data.category;
                document.getElementById('categoryModalTitle').textContent = 'Edit Category';
                document.getElementById('categoryId').value = cat.id;
                document.getElementById('categoryName').value = cat.name;
                document.getElementById('categoryDescription').value = cat.description || '';
                document.getElementById('categoryTaxId').value = cat.tax_id || '';
                
                // Set specific toggle
                const isSpecific = cat.is_specific == 1;
                document.getElementById('isSpecific').checked = isSpecific;
                toggleCharacteristicsSection();
                
                // Reset all checkboxes first
                document.querySelectorAll('.char-checkbox').forEach(cb => {
                    cb.checked = false;
                });
                document.querySelectorAll('.char-required').forEach(cb => {
                    cb.checked = false;
                    cb.disabled = true;
                });
                
                // Check assigned characteristics
                if (cat.characteristics && cat.characteristics.length > 0) {
                    cat.characteristics.forEach(ch => {
                        const checkbox = document.getElementById('char_' + ch.id);
                        const reqCheckbox = document.getElementById('char_req_' + ch.id);
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                        if (reqCheckbox) {
                            reqCheckbox.disabled = false;
                            reqCheckbox.checked = ch.is_required == 1;
                        }
                    });
                }
                
                new bootstrap.Modal(document.getElementById('categoryModal')).show();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(err => {
            Swal.fire('Error', 'Failed to load category', 'error');
        });
}

function deleteCategory(id) {
    Swal.fire({
        title: 'Delete Category?',
        text: 'This action cannot be undone',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(BASE_URL + 'ajax/delete_category.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success', 'Category deleted', 'success').then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

// Category form submit
document.getElementById('categoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const isSpecific = document.getElementById('isSpecific').checked;
    
    // Gather data
    const data = {
        id: document.getElementById('categoryId').value,
        name: document.getElementById('categoryName').value,
        description: document.getElementById('categoryDescription').value,
        tax_id: document.getElementById('categoryTaxId').value,
        is_specific: isSpecific ? 1 : 0,
        characteristics: []
    };
    
    // Collect checked characteristics
    if (isSpecific) {
        document.querySelectorAll('.char-checkbox:checked').forEach(cb => {
            const charId = cb.value;
            const reqCb = document.getElementById('char_req_' + charId);
            data.characteristics.push({
                id: charId,
                is_required: reqCb && reqCb.checked ? 1 : 0
            });
        });
        
        if (data.characteristics.length === 0) {
            Swal.fire('Warning', 'Please select at least one characteristic for specific products.', 'warning');
            return;
        }
    }
    
    fetch(BASE_URL + 'ajax/save_category.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            Swal.fire('Success', result.message, 'success').then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire('Error', result.message, 'error');
        }
    })
    .catch(err => {
        Swal.fire('Error', 'Failed to save category', 'error');
    });
});

// Toggle characteristics section visibility
function toggleCharacteristicsSection() {
    const isSpecific = document.getElementById('isSpecific').checked;
    const section = document.getElementById('characteristicsSection');
    section.style.display = isSpecific ? 'block' : 'none';
}

// Enable/disable required checkbox when characteristic is checked/unchecked
document.querySelectorAll('.char-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
        const charId = this.value;
        const reqCb = document.getElementById('char_req_' + charId);
        if (reqCb) {
            reqCb.disabled = !this.checked;
            if (!this.checked) reqCb.checked = false;
        }
    });
});

function selectAllChars() {
    document.querySelectorAll('.char-checkbox').forEach(cb => {
        cb.checked = true;
        const reqCb = document.getElementById('char_req_' + cb.value);
        if (reqCb) reqCb.disabled = false;
    });
}

function deselectAllChars() {
    document.querySelectorAll('.char-checkbox').forEach(cb => {
        cb.checked = false;
        const reqCb = document.getElementById('char_req_' + cb.value);
        if (reqCb) {
            reqCb.disabled = true;
            reqCb.checked = false;
        }
    });
}

// ═══════════════════════════════════════════════════
// CHARACTERISTICS CRUD
// ═══════════════════════════════════════════════════

function manageCharacteristics() {
    loadCharacteristicsTable();
    new bootstrap.Modal(document.getElementById('characteristicsModal')).show();
}

function loadCharacteristicsTable() {
    fetch(BASE_URL + 'ajax/manage_characteristics.php?action=list')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const tbody = document.getElementById('characteristicsTableBody');
                tbody.innerHTML = '';
                
                data.characteristics.forEach((char, index) => {
                    const options = char.options ? JSON.parse(char.options) : [];
                    const optionsDisplay = options.length > 0 ? options.join(', ') : '<span class="text-muted">—</span>';
                    
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${index + 1}</td>
                        <td><strong>${escapeHtml(char.label)}</strong></td>
                        <td><code>${escapeHtml(char.name)}</code></td>
                        <td><span class="badge bg-light text-dark">${escapeHtml(char.field_type)}</span></td>
                        <td><small>${optionsDisplay}</small></td>
                        <td>${char.is_system == 1 ? '<span class="badge bg-secondary">System</span>' : '<span class="badge bg-success">Custom</span>'}</td>
                        <td>${char.is_active == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>'}</td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-warning" onclick="editCharacteristic(${char.id})" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                ${char.is_system == 0 ? `
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteCharacteristic(${char.id}, '${escapeHtml(char.label)}')" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                ` : ''}
                                <button class="btn btn-sm btn-outline-${char.is_active == 1 ? 'secondary' : 'success'}" 
                                        onclick="toggleCharActive(${char.id})" 
                                        title="${char.is_active == 1 ? 'Deactivate' : 'Activate'}">
                                    <i class="bi bi-${char.is_active == 1 ? 'eye-slash' : 'eye'}"></i>
                                </button>
                            </div>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
                
                if (data.characteristics.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No characteristics defined</td></tr>';
                }
            }
        });
}

function toggleOptionsField() {
    const type = document.getElementById('newCharFieldType').value;
    document.getElementById('optionsFieldWrapper').style.display = type === 'select' ? 'block' : 'none';
}

function toggleEditOptionsField() {
    const type = document.getElementById('editCharFieldType').value;
    document.getElementById('editOptionsFieldWrapper').style.display = type === 'select' ? 'block' : 'none';
}

function addCharacteristic(e) {
    e.preventDefault();
    
    const data = {
        action: 'create',
        name: document.getElementById('newCharName').value,
        label: document.getElementById('newCharLabel').value,
        field_type: document.getElementById('newCharFieldType').value,
        options: document.getElementById('newCharOptions').value,
        description: document.getElementById('newCharDescription').value
    };
    
    fetch(BASE_URL + 'ajax/manage_characteristics.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            Swal.fire('Success', result.message, 'success');
            document.getElementById('addCharForm').reset();
            document.getElementById('optionsFieldWrapper').style.display = 'none';
            loadCharacteristicsTable();
        } else {
            Swal.fire('Error', result.message, 'error');
        }
    });
    
    return false;
}

function editCharacteristic(id) {
    fetch(BASE_URL + 'ajax/manage_characteristics.php?action=get&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const char = data.characteristic;
                document.getElementById('editCharId').value = char.id;
                document.getElementById('editCharLabel').value = char.label;
                document.getElementById('editCharName').value = char.name;
                document.getElementById('editCharFieldType').value = char.field_type;
                document.getElementById('editCharDescription').value = char.description || '';
                
                // Handle options
                if (char.options) {
                    try {
                        const opts = JSON.parse(char.options);
                        document.getElementById('editCharOptions').value = opts.join(', ');
                    } catch(e) {
                        document.getElementById('editCharOptions').value = char.options;
                    }
                } else {
                    document.getElementById('editCharOptions').value = '';
                }
                
                toggleEditOptionsField();
                
                // Disable name for system chars
                const nameGroup = document.getElementById('editCharNameGroup');
                if (char.is_system == 1) {
                    nameGroup.style.display = 'none';
                } else {
                    nameGroup.style.display = 'block';
                }
                
                new bootstrap.Modal(document.getElementById('editCharModal')).show();
            }
        });
}

function updateCharacteristic(e) {
    e.preventDefault();
    
    const data = {
        action: 'update',
        id: document.getElementById('editCharId').value,
        label: document.getElementById('editCharLabel').value,
        name: document.getElementById('editCharName').value,
        field_type: document.getElementById('editCharFieldType').value,
        options: document.getElementById('editCharOptions').value,
        description: document.getElementById('editCharDescription').value
    };
    
    fetch(BASE_URL + 'ajax/manage_characteristics.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('editCharModal')).hide();
            Swal.fire('Success', result.message, 'success');
            loadCharacteristicsTable();
        } else {
            Swal.fire('Error', result.message, 'error');
        }
    });
    
    return false;
}

function deleteCharacteristic(id, label) {
    Swal.fire({
        title: 'Delete Characteristic?',
        html: `Are you sure you want to delete <strong>${label}</strong>?<br><small class="text-muted">If it's assigned to categories, it will be deactivated instead.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(BASE_URL + 'ajax/manage_characteristics.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'delete', id: id})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success', data.message, 'success');
                    loadCharacteristicsTable();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

function toggleCharActive(id) {
    fetch(BASE_URL + 'ajax/manage_characteristics.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'toggle_active', id: id})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadCharacteristicsTable();
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    });
}

// ═══════════════════════════════════════════════════
// VIEW CATEGORY CHARACTERISTICS
// ═══════════════════════════════════════════════════

function viewCharacteristics(categoryId, categoryName) {
    document.getElementById('viewCharsTitle').textContent = categoryName + ' - Characteristics';
    const body = document.getElementById('viewCharsBody');
    body.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>';
    
    new bootstrap.Modal(document.getElementById('viewCharsModal')).show();
    
    fetch(BASE_URL + 'ajax/manage_characteristics.php?action=get_for_category&category_id=' + categoryId)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.characteristics.length > 0) {
                let html = '<div class="list-group">';
                data.characteristics.forEach((char, index) => {
                    const options = char.options ? JSON.parse(char.options) : [];
                    html += `
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong>${index + 1}. ${escapeHtml(char.label)}</strong>
                                    ${char.is_required == 1 ? '<span class="badge bg-danger ms-1">Required</span>' : ''}
                                    ${char.is_system == 1 ? '<span class="badge bg-secondary ms-1">System</span>' : '<span class="badge bg-success ms-1">Custom</span>'}
                                </div>
                                <span class="badge bg-light text-dark">${escapeHtml(char.field_type)}</span>
                            </div>
                            ${char.description ? '<small class="text-muted">' + escapeHtml(char.description) + '</small>' : ''}
                            ${options.length > 0 ? '<br><small class="text-info">Options: ' + options.map(o => escapeHtml(o)).join(', ') + '</small>' : ''}
                        </div>
                    `;
                });
                html += '</div>';
                body.innerHTML = html;
            } else {
                body.innerHTML = '<p class="text-muted text-center">No characteristics assigned to this category.</p>';
            }
        })
        .catch(() => {
            body.innerHTML = '<p class="text-danger text-center">Failed to load characteristics.</p>';
        });
}

// ═══════════════════════════════════════════════════
// UTILITY
// ═══════════════════════════════════════════════════

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
