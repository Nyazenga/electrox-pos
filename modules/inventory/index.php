<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('inventory.view');

$pageTitle = 'Stock Levels';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

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
    // This handles cases where "Apple 20W USB-C Power Adapter" might be split across brand and model
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

$products = $db->getRows("SELECT p.*, pc.name as category_name, pc.id as category_id, b.branch_name 
                          FROM products p 
                          LEFT JOIN product_categories pc ON p.category_id = pc.id 
                          LEFT JOIN branches b ON p.branch_id = b.id 
                          WHERE $whereClause
                          ORDER BY p.quantity_in_stock ASC", $params);
if ($products === false) $products = [];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Stock Levels</h2>
    <div>
        <a href="grn.php" class="btn btn-success"><i class="bi bi-box-arrow-in-down"></i> Goods Received</a>
        <a href="transfers.php" class="btn btn-info"><i class="bi bi-arrow-left-right"></i> Transfers</a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
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
    <div class="card-body">
        <table class="table table-striped" id="inventoryTable">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Branch</th>
                    <th>Stock</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <?php 
                                // Check if it's General category - use product_name instead of brand/model
                                $isGeneralCategory = !empty($product['category_name']) && strtolower($product['category_name']) === 'general';
                                if ($isGeneralCategory && !empty($product['product_name'])) {
                                    echo escapeHtml($product['product_name']);
                                } else {
                                    echo escapeHtml(trim(($product['brand'] ?? '') . ' ' . ($product['model'] ?? '')));
                                }
                                ?>
                            </td>
                            <td><?= escapeHtml($product['category_name'] ?? 'N/A') ?></td>
                            <td><?= escapeHtml($product['branch_name'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge bg-<?= $product['quantity_in_stock'] <= $product['reorder_level'] ? 'danger' : ($product['quantity_in_stock'] <= $product['reorder_level'] * 2 ? 'warning' : 'success') ?>">
                                    <?= $product['quantity_in_stock'] ?>
                                </span>
                            </td>
                            <td><?= $product['reorder_level'] ?></td>
                            <td><span class="badge bg-<?= $product['status'] == 'Active' ? 'success' : 'secondary' ?>"><?= escapeHtml($product['status'] ?? 'Unknown') ?></span></td>
                            <td>
                                <a href="../products/view.php?id=<?= $product['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

<script>
(function() {
    function initInventoryTable() {
        if (typeof jQuery === 'undefined') {
            setTimeout(initInventoryTable, 100);
            return;
        }
        
        var $ = jQuery;
        
        $(document).ready(function() {
            var table = $('#inventoryTable');
            
            // Check if table has actual data rows (not just empty placeholder)
            var hasData = table.find('tbody tr').length > 0 && 
                         table.find('tbody tr').first().find('td').first().text().trim() !== '';
            
            if (!hasData) {
                // Clear empty row so DataTables can show its empty message
                table.find('tbody').empty();
            }
            
            // Initialize DataTable
            if ($.fn.DataTable) {
                if ($.fn.DataTable.isDataTable(table)) {
                    table.DataTable().destroy();
                }
                
                table.DataTable({
                    order: [[3, 'asc']], // Sort by stock (column 3)
                    pageLength: 25,
                    destroy: true,
                    autoWidth: false,
                    language: {
                        emptyTable: 'No products found matching the selected filters.'
                    },
                    columns: [
                        null, // Product
                        null, // Category
                        null, // Branch
                        null, // Stock
                        null, // Reorder Level
                        null, // Status
                        { orderable: false, searchable: false } // Actions
                    ]
                });
            }
        });
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initInventoryTable);
    } else {
        initInventoryTable();
    }
})();
</script>
