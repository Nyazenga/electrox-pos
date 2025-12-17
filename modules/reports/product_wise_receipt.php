<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('reports.view');

$pageTitle = 'Product Wise Receipt Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCashier = $_GET['user_id'] ?? 'all';
$selectedCategory = $_GET['category_id'] ?? 'all';
$selectedProduct = $_GET['product_id'] ?? 'all';

// Get filter options
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

$cashiers = $db->getRows("SELECT DISTINCT u.* FROM users u 
                         INNER JOIN sales s ON u.id = s.user_id 
                         WHERE s.sale_date BETWEEN :start AND :end 
                         ORDER BY u.first_name, u.last_name", 
                         [':start' => $startDate, ':end' => $endDate]);
if ($cashiers === false) $cashiers = [];

$categories = $db->getRows("SELECT * FROM product_categories ORDER BY name");
if ($categories === false) $categories = [];

// Get products (filtered by category if selected)
$productWhere = "p.status = 'Active'";
$productParams = [];
if ($selectedCategory !== 'all' && $selectedCategory) {
    $productWhere .= " AND p.category_id = :category_id";
    $productParams[':category_id'] = $selectedCategory;
}
$products = $db->getRows("SELECT p.id, p.product_code, 
                         COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
                         p.category_id
                         FROM products p 
                         WHERE $productWhere 
                         ORDER BY product_name", $productParams);
if ($products === false) $products = [];

// Build query conditions
$whereConditions = ["DATE(s.sale_date) BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate, ':end_date' => $endDate];

if ($selectedBranch !== 'all' && $selectedBranch) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $selectedBranch;
} elseif ($branchId !== null) {
    $whereConditions[] = "s.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

if ($selectedCashier !== 'all' && $selectedCashier) {
    $whereConditions[] = "s.user_id = :user_id";
    $params[':user_id'] = $selectedCashier;
}

if ($selectedCategory !== 'all' && $selectedCategory) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $selectedCategory;
}

if ($selectedProduct !== 'all' && $selectedProduct) {
    $whereConditions[] = "si.product_id = :product_id";
    $params[':product_id'] = $selectedProduct;
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary
$summary = $db->getRow("SELECT 
    SUM(si.quantity) as sold_qty,
    COALESCE(SUM(si.total_price), 0) as total_subtotal,
    COALESCE(SUM(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0))), 0) as total_line_discount,
    COALESCE(SUM(si.quantity * COALESCE(s.tax_amount / NULLIF((SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id), 0), 0)), 0) as total_product_unit_tax
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'sold_qty' => 0,
        'total_subtotal' => 0,
        'total_line_discount' => 0,
        'total_product_unit_tax' => 0
    ];
}

// Get product wise receipts
$productReceipts = $db->getRows("SELECT 
    s.receipt_number,
    DATE(s.sale_date) as sale_date,
    si.product_name,
    p.product_code,
    pc.name as category_name,
    si.quantity,
    si.unit_price,
    si.total_price,
    COALESCE(s.discount_amount * (si.total_price / NULLIF(s.subtotal, 0)), 0) as line_discount,
    COALESCE(s.tax_amount / NULLIF((SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id), 0), 0) as product_tax,
    u.first_name as cashier_first, u.last_name as cashier_last,
    b.branch_name
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
LEFT JOIN users u ON s.user_id = u.id
LEFT JOIN branches b ON s.branch_id = b.id
WHERE $whereClause
ORDER BY s.sale_date DESC, s.receipt_number DESC
LIMIT 1000", $params);

if ($productReceipts === false) {
    $productReceipts = [];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Product Wise Receipt Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
    $html .= '<tr><td>Sold Quantity</td><td style="text-align: right;">' . $summary['sold_qty'] . '</td></tr>';
    $html .= '<tr><td>Total Subtotal</td><td style="text-align: right;">' . formatCurrency($summary['total_subtotal']) . '</td></tr>';
    $html .= '<tr><td>Total Line Discount</td><td style="text-align: right;">' . formatCurrency($summary['total_line_discount']) . '</td></tr>';
    $html .= '<tr><td>Total Product Unit Tax</td><td style="text-align: right;">' . formatCurrency($summary['total_product_unit_tax']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Product Receipts</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Receipt #</th><th>Date</th><th>Product</th><th>Code</th><th>Category</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Unit Price</th><th style="text-align: right;">Total</th><th style="text-align: right;">Discount</th><th style="text-align: right;">Tax</th><th>Cashier</th><th>Branch</th></tr>';
    foreach ($productReceipts as $pr) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($pr['receipt_number']) . '</td>';
        $html .= '<td>' . date('M d, Y', strtotime($pr['sale_date'])) . '</td>';
        $html .= '<td>' . escapeHtml($pr['product_name']) . '</td>';
        $html .= '<td>' . escapeHtml($pr['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($pr['category_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . $pr['quantity'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($pr['unit_price']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($pr['total_price']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($pr['line_discount']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($pr['product_tax']) . '</td>';
        $html .= '<td>' . escapeHtml(($pr['cashier_first'] ?? '') . ' ' . ($pr['cashier_last'] ?? '')) . '</td>';
        $html .= '<td>' . escapeHtml($pr['branch_name'] ?? 'N/A') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Product Wise Receipt Report', $html, 'Product_Wise_Receipt_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-file-earmark-text"></i> Product Wise Receipt Report</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> Start Date</label>
                <input type="date" name="start_date" value="<?= $startDate ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-calendar"></i> End Date</label>
                <input type="date" name="end_date" value="<?= $endDate ?>" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-person-badge"></i> Cashier</label>
                <select name="user_id" class="form-select">
                    <option value="all" <?= $selectedCashier === 'all' ? 'selected' : '' ?>>All Cashiers</option>
                    <?php foreach ($cashiers as $cashier): ?>
                        <option value="<?= $cashier['id'] ?>" <?= $selectedCashier == $cashier['id'] ? 'selected' : '' ?>><?= escapeHtml($cashier['first_name'] . ' ' . $cashier['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                <select name="category_id" class="form-select" id="categoryFilter" onchange="updateProductList()">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $selectedCategory == $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="bi bi-box-seam"></i> Product</label>
                <select name="product_id" class="form-select" id="productFilter">
                    <option value="all" <?= $selectedProduct === 'all' ? 'selected' : '' ?>>All Products</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['id'] ?>" data-category="<?= $product['category_id'] ?>" <?= $selectedProduct == $product['id'] ? 'selected' : '' ?>><?= escapeHtml($product['product_code'] . ' - ' . $product['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="product_wise_receipt.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Sold Quantity</h6>
                <h3 class="mb-0"><?= $summary['sold_qty'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Subtotal</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_subtotal']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Line Discount</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_line_discount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Product Unit Tax</h6>
                <h3 class="mb-0"><?= formatCurrency($summary['total_product_unit_tax']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Product Receipts</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="productWiseReceiptTable">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Code</th>
                        <th>Category</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Discount</th>
                        <th class="text-end">Tax</th>
                        <th>Cashier</th>
                        <th>Branch</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productReceipts)): ?>
                        <!-- Empty state - DataTables will handle the message -->
                    <?php else: ?>
                        <?php foreach ($productReceipts as $pr): ?>
                            <tr>
                                <td><?= escapeHtml($pr['receipt_number']) ?></td>
                                <td><?= date('M d, Y', strtotime($pr['sale_date'])) ?></td>
                                <td><?= escapeHtml($pr['product_name']) ?></td>
                                <td><?= escapeHtml($pr['product_code'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($pr['category_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $pr['quantity'] ?></td>
                                <td class="text-end"><?= formatCurrency($pr['unit_price']) ?></td>
                                <td class="text-end"><?= formatCurrency($pr['total_price']) ?></td>
                                <td class="text-end"><?= formatCurrency($pr['line_discount']) ?></td>
                                <td class="text-end"><?= formatCurrency($pr['product_tax']) ?></td>
                                <td><?= escapeHtml(($pr['cashier_first'] ?? '') . ' ' . ($pr['cashier_last'] ?? '')) ?></td>
                                <td><?= escapeHtml($pr['branch_name'] ?? 'N/A') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

<script>
$(document).ready(function() {
    if (typeof jQuery === 'undefined') {
        return;
    }
    
    var $ = jQuery;
    
    // Initialize searchable dropdowns with Select2
    function initSelect2() {
        if (typeof $.fn.select2 !== 'undefined') {
            var productSelect = $('#productFilter');
            var categorySelect = $('#categoryFilter');
            
            if (productSelect.length && !productSelect.hasClass('select2-hidden-accessible')) {
                productSelect.select2({
                    placeholder: 'All Products',
                    allowClear: true,
                    width: '100%',
                    theme: 'bootstrap-5'
                });
            }
            
            if (categorySelect.length && !categorySelect.hasClass('select2-hidden-accessible')) {
                categorySelect.select2({
                    placeholder: 'All Categories',
                    allowClear: true,
                    width: '100%',
                    theme: 'bootstrap-5'
                });
            }
        } else {
            // Retry after a short delay if Select2 isn't loaded yet
            setTimeout(initSelect2, 100);
        }
    }
    
    // Initialize Select2
    initSelect2();
    
    // Update product list when category changes (for Select2)
    $('#categoryFilter').on('change', function() {
        var categoryId = $(this).val();
        var productSelect = $('#productFilter');
        var options = productSelect.find('option');
        
        // Show/hide products based on selected category
        options.each(function() {
            var $option = $(this);
            if ($option.val() === 'all') {
                return; // Skip "All Products" option
            }
            var productCategory = $option.data('category');
            if (categoryId === 'all' || productCategory == categoryId) {
                $option.prop('disabled', false);
            } else {
                $option.prop('disabled', true);
                if ($option.prop('selected')) {
                    productSelect.val('all').trigger('change');
                }
            }
        });
        productSelect.trigger('change');
    });
    
    // Initialize DataTables
    if ($.fn.DataTable) {
        var table = $('#productWiseReceiptTable');
        if (table.length === 0) {
            return;
        }
        
        // Destroy existing instance if it exists
        if ($.fn.DataTable.isDataTable(table)) {
            try {
                table.DataTable().destroy();
            } catch(e) {
                // Ignore
            }
        }
        
        // Always initialize DataTables - it will handle empty state properly
        // Check if tbody is empty or has only empty rows
        var tbody = table.find('tbody');
        var rows = tbody.find('tr');
        var hasData = false;
        
        if (rows.length > 0) {
            rows.each(function() {
                var $row = $(this);
                var hasContent = false;
                $row.find('td').each(function() {
                    if ($(this).text().trim() !== '') {
                        hasContent = true;
                        return false; // break
                    }
                });
                if (hasContent) {
                    hasData = true;
                    return false; // break outer loop
                }
            });
        }
        
        // If no data, clear tbody so DataTables shows empty message
        if (!hasData && rows.length > 0) {
            tbody.empty();
        }
        
        try {
            table.DataTable({
                order: [[1, 'desc']],
                pageLength: 25,
                destroy: true,
                autoWidth: false,
                language: {
                    emptyTable: "No receipts found for the selected product",
                    zeroRecords: "No receipts found for the selected product"
                }
            });
        } catch(e) {
            console.error('DataTables initialization error:', e);
        }
    }
});
</script>

