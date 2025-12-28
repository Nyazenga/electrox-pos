<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('products.barcodes');

$pageTitle = 'Product Barcodes';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Get products
$branchId = $_SESSION['branch_id'] ?? null;
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? 'all'; // all, with_barcode, without_barcode

$whereConditions = ["p.status = 'Active'"];
$params = [];

if ($branchId) {
    $whereConditions[] = "p.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if (!empty($search)) {
    $whereConditions[] = "(p.product_name LIKE :search OR p.product_code LIKE :search OR p.barcode LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($filter === 'with_barcode') {
    $whereConditions[] = "p.barcode IS NOT NULL AND p.barcode != ''";
} elseif ($filter === 'without_barcode') {
    $whereConditions[] = "(p.barcode IS NULL OR p.barcode = '')";
}

$whereClause = implode(' AND ', $whereConditions);

$products = $db->getRows("SELECT p.*, 
                          COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as display_name,
                          pc.name as category_name,
                          b.branch_name
                          FROM products p
                          LEFT JOIN product_categories pc ON p.category_id = pc.id
                          LEFT JOIN branches b ON p.branch_id = b.id
                          WHERE $whereClause
                          ORDER BY p.product_code
                          LIMIT 500", $params);

if ($products === false) $products = [];

// Count stats
$stats = $db->getRow("SELECT 
    COUNT(*) as total_products,
    COUNT(CASE WHEN p.barcode IS NOT NULL AND p.barcode != '' THEN 1 END) as with_barcode,
    COUNT(CASE WHEN p.barcode IS NULL OR p.barcode = '' THEN 1 END) as without_barcode
    FROM products p
    WHERE " . ($branchId ? "p.branch_id = :branch_id AND " : "") . "p.status = 'Active'",
    $branchId ? [':branch_id' => $branchId] : []);

if ($stats === false) {
    $stats = ['total_products' => 0, 'with_barcode' => 0, 'without_barcode' => 0];
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-upc-scan"></i> Product Barcodes</h2>
    <div>
        <button class="btn btn-info me-2" id="printSelectedBtn" onclick="printSelectedBarcodes()" style="display: none;">
            <i class="bi bi-printer"></i> Print Selected
        </button>
        <button class="btn btn-success me-2" id="generateSelectedBtn" onclick="generateSelectedBarcodes()" style="display: none;">
            <i class="bi bi-upc"></i> Generate Selected Barcodes
        </button>
        <button class="btn btn-primary" onclick="generateAllBarcodes()">
            <i class="bi bi-upc"></i> Generate All Barcodes
        </button>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Total Products</h5>
                <h3 class="text-primary"><?= number_format($stats['total_products']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">With Barcode</h5>
                <h3 class="text-success"><?= number_format($stats['with_barcode']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Without Barcode</h5>
                <h3 class="text-warning"><?= number_format($stats['without_barcode']) ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" value="<?= escapeHtml($search) ?>" placeholder="Product name, code, barcode...">
            </div>
            <div class="col-md-4">
                <label class="form-label">Filter</label>
                <select name="filter" class="form-select">
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Products</option>
                    <option value="with_barcode" <?= $filter === 'with_barcode' ? 'selected' : '' ?>>With Barcode</option>
                    <option value="without_barcode" <?= $filter === 'without_barcode' ? 'selected' : '' ?>>Without Barcode</option>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search"></i> Filter</button>
                <a href="barcodes.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table">
                <thead>
                    <tr>
                        <th width="50">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                        </th>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Branch</th>
                        <th>Barcode</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="product-checkbox" value="<?= $product['id'] ?>">
                            </td>
                            <td><?= escapeHtml($product['product_code'] ?? 'N/A') ?></td>
                            <td><?= escapeHtml($product['display_name'] ?? 'N/A') ?></td>
                            <td><?= escapeHtml($product['category_name'] ?? 'N/A') ?></td>
                            <td><?= escapeHtml($product['branch_name'] ?? 'N/A') ?></td>
                            <td>
                                <?php if (!empty($product['barcode'])): ?>
                                    <span class="badge bg-success"><?= escapeHtml($product['barcode']) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning">No Barcode</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if (!empty($product['barcode'])): ?>
                                        <button class="btn btn-info" onclick="printBarcode(<?= $product['id'] ?>)" title="Print Barcode">
                                            <i class="bi bi-printer"></i>
                                        </button>
                                        <button class="btn btn-warning" onclick="regenerateBarcode(<?= $product['id'] ?>)" title="Regenerate Barcode">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-primary" onclick="generateBarcode(<?= $product['id'] ?>)" title="Generate Barcode">
                                            <i class="bi bi-upc"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Generate All Modal -->
<div class="modal fade" id="generateAllModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="generateModalTitle">Generate All Barcodes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="generateModalMessage">Are you sure you want to generate barcodes for all products without barcodes?</p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="replaceExisting">
                    <label class="form-check-label" for="replaceExisting">
                        Replace existing barcodes
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="generateModalConfirmBtn" onclick="confirmGenerateAll()">Generate All</button>
            </div>
        </div>
    </div>
</div>

<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.product-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateGenerateSelectedButton();
}

function updateGenerateSelectedButton() {
    const selected = Array.from(document.querySelectorAll('.product-checkbox:checked')).map(cb => cb.value);
    const generateSelectedBtn = document.getElementById('generateSelectedBtn');
    const printSelectedBtn = document.getElementById('printSelectedBtn');
    
    if (selected.length > 0) {
        generateSelectedBtn.style.display = 'inline-block';
        generateSelectedBtn.innerHTML = '<i class="bi bi-upc"></i> Generate Selected (' + selected.length + ')';
        printSelectedBtn.style.display = 'inline-block';
        printSelectedBtn.innerHTML = '<i class="bi bi-printer"></i> Print Selected (' + selected.length + ')';
    } else {
        generateSelectedBtn.style.display = 'none';
        printSelectedBtn.style.display = 'none';
    }
    
    // Update select all checkbox
    const selectAllCheckbox = document.getElementById('selectAll');
    const allCheckboxes = document.querySelectorAll('.product-checkbox');
    if (allCheckboxes.length > 0) {
        selectAllCheckbox.checked = selected.length === allCheckboxes.length;
    }
}

// Update button visibility when individual checkboxes change
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.product-checkbox').forEach(cb => {
        cb.addEventListener('change', updateGenerateSelectedButton);
    });
    updateGenerateSelectedButton();
});

function generateSelectedBarcodes() {
    const selected = Array.from(document.querySelectorAll('.product-checkbox:checked')).map(cb => cb.value);
    if (selected.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Selection',
            text: 'Please select at least one product'
        });
        return;
    }
    
    // Show modal with selected products message
    document.getElementById('generateModalTitle').textContent = 'Generate Selected Barcodes';
    document.getElementById('generateModalMessage').textContent = 'Are you sure you want to generate barcodes for ' + selected.length + ' selected product(s)?';
    document.getElementById('generateModalConfirmBtn').textContent = 'Generate Selected';
    document.getElementById('generateModalConfirmBtn').setAttribute('onclick', 'confirmGenerateSelected()');
    
    const modal = new bootstrap.Modal(document.getElementById('generateAllModal'));
    modal.show();
}

function generateAllBarcodes() {
    const selected = Array.from(document.querySelectorAll('.product-checkbox:checked')).map(cb => cb.value);
    
    // If products are selected, ask if they want to generate for selected or all
    if (selected.length > 0) {
        Swal.fire({
            title: 'Generate Barcodes',
            text: 'You have ' + selected.length + ' product(s) selected. What would you like to do?',
            icon: 'question',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: 'Generate for Selected',
            denyButtonText: 'Generate for All',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                generateSelectedBarcodes();
            } else if (result.isDenied) {
                // Show all products modal
                document.getElementById('generateModalTitle').textContent = 'Generate All Barcodes';
                document.getElementById('generateModalMessage').textContent = 'Are you sure you want to generate barcodes for all products without barcodes?';
                document.getElementById('generateModalConfirmBtn').textContent = 'Generate All';
                document.getElementById('generateModalConfirmBtn').setAttribute('onclick', 'confirmGenerateAll()');
                
                const modal = new bootstrap.Modal(document.getElementById('generateAllModal'));
                modal.show();
            }
        });
        return;
    }
    
    // No selection, show normal all products modal
    document.getElementById('generateModalTitle').textContent = 'Generate All Barcodes';
    document.getElementById('generateModalMessage').textContent = 'Are you sure you want to generate barcodes for all products without barcodes?';
    document.getElementById('generateModalConfirmBtn').textContent = 'Generate All';
    document.getElementById('generateModalConfirmBtn').setAttribute('onclick', 'confirmGenerateAll()');
    
    const modal = new bootstrap.Modal(document.getElementById('generateAllModal'));
    modal.show();
}

function confirmGenerateAll() {
    const replaceExisting = document.getElementById('replaceExisting').checked;
    
    Swal.fire({
        title: 'Generating Barcodes...',
        text: 'Please wait while barcodes are generated and PDF is created.',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch(BASE_URL + 'ajax/generate_all_barcodes.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            replace_existing: replaceExisting
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                confirmButtonText: 'Download PDF'
            }).then(() => {
                if (data.pdf_url) {
                    window.open(data.pdf_url, '_blank');
                }
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: data.message || 'Failed to generate barcodes'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'An error occurred while generating barcodes'
        });
    });
    
    bootstrap.Modal.getInstance(document.getElementById('generateAllModal')).hide();
}

function confirmGenerateSelected() {
    const selected = Array.from(document.querySelectorAll('.product-checkbox:checked')).map(cb => parseInt(cb.value));
    if (selected.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Selection',
            text: 'Please select at least one product'
        });
        bootstrap.Modal.getInstance(document.getElementById('generateAllModal')).hide();
        return;
    }
    
    const replaceExisting = document.getElementById('replaceExisting').checked;
    
    Swal.fire({
        title: 'Generating Barcodes...',
        text: 'Please wait while barcodes are generated and PDF is created.',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch(BASE_URL + 'ajax/generate_all_barcodes.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_ids: selected,
            replace_existing: replaceExisting
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                confirmButtonText: 'Download PDF'
            }).then(() => {
                if (data.pdf_url) {
                    window.open(data.pdf_url, '_blank');
                }
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: data.message || 'Failed to generate barcodes'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'An error occurred while generating barcodes'
        });
    });
    
    bootstrap.Modal.getInstance(document.getElementById('generateAllModal')).hide();
}

function generateBarcode(productId) {
    Swal.fire({
        title: 'Generating Barcode...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch(BASE_URL + 'ajax/generate_product_barcode.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: productId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'Barcode generated successfully',
                confirmButtonText: 'OK'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: data.message || 'Failed to generate barcode'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'An error occurred'
        });
    });
}

function regenerateBarcode(productId) {
    Swal.fire({
        title: 'Regenerate Barcode?',
        text: 'This will replace the existing barcode with a new one.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, regenerate',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            generateBarcode(productId);
        }
    });
}

function printBarcode(productId) {
    window.open(BASE_URL + 'modules/products/print_barcode.php?id=' + productId, '_blank');
}

// Print selected barcodes
function printSelectedBarcodes() {
    const selected = Array.from(document.querySelectorAll('.product-checkbox:checked')).map(cb => cb.value);
    if (selected.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Selection',
            text: 'Please select at least one product'
        });
        return;
    }
    
    window.open(BASE_URL + 'modules/products/print_barcodes.php?ids=' + selected.join(','), '_blank');
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

