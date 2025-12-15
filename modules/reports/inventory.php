<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Inventory Reports';

$db = Database::getInstance();
$lowStock = $db->getRows("SELECT p.*, pc.name as category_name FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE p.quantity_in_stock <= p.reorder_level AND p.status = 'Active' ORDER BY p.quantity_in_stock ASC");
$stockByCategory = $db->getRows("SELECT pc.name as category, COUNT(*) as count, SUM(p.quantity_in_stock) as total_stock FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE p.status = 'Active' GROUP BY pc.id, pc.name");

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Inventory Reports</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <h6>Low Stock Items</h6>
                <h3><?= count($lowStock) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6>Total Categories</h6>
                <h3><?= count($stockByCategory) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Low Stock Items</div>
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Reorder Level</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lowStock as $product): ?>
                    <tr>
                        <td><?= escapeHtml($product['brand'] . ' ' . $product['model']) ?></td>
                        <td><?= escapeHtml($product['category_name'] ?? 'N/A') ?></td>
                        <td><span class="badge bg-danger"><?= $product['quantity_in_stock'] ?></span></td>
                        <td><?= $product['reorder_level'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">Stock by Category</div>
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Products</th>
                    <th>Total Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stockByCategory as $cat): ?>
                    <tr>
                        <td><?= escapeHtml($cat['category'] ?? 'Uncategorized') ?></td>
                        <td><?= $cat['count'] ?></td>
                        <td><?= $cat['total_stock'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

