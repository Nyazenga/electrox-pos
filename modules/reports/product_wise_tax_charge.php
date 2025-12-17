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

$pageTitle = 'Product Wise Tax/Charge Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedCategory = $_GET['category_id'] ?? 'all';
$selectedProduct = $_GET['product_id'] ?? 'all';

// Get filter options
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

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

if ($selectedCategory !== 'all' && $selectedCategory) {
    $whereConditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $selectedCategory;
}

if ($selectedProduct !== 'all' && $selectedProduct) {
    $whereConditions[] = "si.product_id = :product_id";
    $params[':product_id'] = $selectedProduct;
}

$whereClause = implode(' AND ', $whereConditions);

// Get product wise tax and charges
$productTaxCharge = $db->getRows("SELECT 
    si.product_id,
    si.product_name,
    p.product_code,
    pc.name as category_name,
    SUM(si.quantity) as total_qty,
    COALESCE(SUM(s.tax_amount / NULLIF((SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id), 0)), 0) as total_tax,
    COALESCE(SUM(CASE WHEN si.unit_price > 0 AND si.total_price > (si.unit_price * si.quantity) THEN (si.total_price - (si.unit_price * si.quantity)) ELSE 0 END), 0) as total_charge
FROM sale_items si
INNER JOIN sales s ON si.sale_id = s.id
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE $whereClause
GROUP BY si.product_id, si.product_name, p.product_code, pc.name
HAVING total_tax > 0 OR total_charge > 0
ORDER BY (total_tax + total_charge) DESC
LIMIT 1000", $params);

if ($productTaxCharge === false) {
    $productTaxCharge = [];
}

// Get summary
$summary = $db->getRow("SELECT 
    COALESCE(SUM(s.tax_amount), 0) as total_tax,
    COALESCE(SUM(CASE WHEN si.unit_price > 0 AND si.total_price > (si.unit_price * si.quantity) THEN (si.total_price - (si.unit_price * si.quantity)) ELSE 0 END), 0) as total_charge
FROM sales s
LEFT JOIN sale_items si ON s.id = si.sale_id
LEFT JOIN products p ON si.product_id = p.id
WHERE $whereClause", $params);

if ($summary === false) {
    $summary = [
        'total_tax' => 0,
        'total_charge' => 0
    ];
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Product Wise Tax/Charge Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Period: ' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . '</p>';
    
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Amount</th></tr>';
    $html .= '<tr><td>Total Tax</td><td style="text-align: right;">' . formatCurrency($summary['total_tax']) . '</td></tr>';
    $html .= '<tr><td>Total Charge</td><td style="text-align: right;">' . formatCurrency($summary['total_charge']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Product Tax/Charge Breakdown</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
    $html .= '<tr style="background-color: #f0f0f0;"><th>Product</th><th>Code</th><th>Category</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Tax</th><th style="text-align: right;">Charge</th><th style="text-align: right;">Total</th></tr>';
    foreach ($productTaxCharge as $ptc) {
        $html .= '<tr>';
        $html .= '<td>' . escapeHtml($ptc['product_name']) . '</td>';
        $html .= '<td>' . escapeHtml($ptc['product_code'] ?? 'N/A') . '</td>';
        $html .= '<td>' . escapeHtml($ptc['category_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="text-align: right;">' . $ptc['total_qty'] . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($ptc['total_tax']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($ptc['total_charge']) . '</td>';
        $html .= '<td style="text-align: right;">' . formatCurrency($ptc['total_tax'] + $ptc['total_charge']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    
    ReportHelper::generatePDF('Product Wise Tax/Charge Report', $html, 'Product_Wise_Tax_Charge_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-calculator"></i> Product Wise Tax/Charge Report</h2>
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
                <select name="branch_id" class="form-select" id="branchFilter">
                    <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-tags"></i> Category</label>
                <select name="category_id" class="form-select" id="categoryFilter">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $selectedCategory == $category['id'] ? 'selected' : '' ?>><?= escapeHtml($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-box"></i> Product</label>
                <select name="product_id" class="form-select" id="productFilter">
                    <option value="all" <?= $selectedProduct === 'all' ? 'selected' : '' ?>>All Products</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['id'] ?>" data-category="<?= $product['category_id'] ?>" <?= $selectedProduct == $product['id'] ? 'selected' : '' ?>><?= escapeHtml($product['product_name'] . ' (' . $product['product_code'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="product_wise_tax_charge.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Tax</h6>
                <h3 class="mb-0 text-primary"><?= formatCurrency($summary['total_tax']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-info">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Charge</h6>
                <h3 class="mb-0 text-info"><?= formatCurrency($summary['total_charge']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Product Tax/Charge Breakdown</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover data-table" id="productWiseTaxChargeTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Code</th>
                        <th>Category</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Tax</th>
                        <th class="text-end">Charge</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productTaxCharge)): ?>
                        <tr>
                            <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productTaxCharge as $ptc): ?>
                            <tr>
                                <td><?= escapeHtml($ptc['product_name']) ?></td>
                                <td><?= escapeHtml($ptc['product_code'] ?? 'N/A') ?></td>
                                <td><?= escapeHtml($ptc['category_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= $ptc['total_qty'] ?></td>
                                <td class="text-end"><?= formatCurrency($ptc['total_tax']) ?></td>
                                <td class="text-end"><?= formatCurrency($ptc['total_charge']) ?></td>
                                <td class="text-end"><strong><?= formatCurrency($ptc['total_tax'] + $ptc['total_charge']) ?></strong></td>
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
(function() {
    function initReport() {
        if (typeof jQuery === 'undefined') {
            setTimeout(initReport, 100);
            return;
        }
        
        var $ = jQuery;
        
        $(document).ready(function() {
            // Initialize Select2 for searchable dropdowns
            function initSelect2() {
                if (typeof $.fn.select2 === 'undefined') {
                    setTimeout(initSelect2, 100);
                    return;
                }
                
                $('#categoryFilter').select2({
                    theme: 'bootstrap-5',
                    placeholder: 'All Categories',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('#categoryFilter').parent()
                });
                
                $('#productFilter').select2({
                    theme: 'bootstrap-5',
                    placeholder: 'All Products',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('#productFilter').parent()
                });
                
                // Filter products based on selected category
                $('#categoryFilter').on('change', function() {
                    var selectedCategory = $(this).val();
                    var productSelect = $('#productFilter');
                    var currentProduct = productSelect.val();
                    
                    productSelect.find('option').each(function() {
                        var $option = $(this);
                        if ($option.val() === 'all') {
                            $option.show();
                        } else {
                            var productCategory = $option.data('category');
                            if (selectedCategory === 'all' || productCategory == selectedCategory) {
                                $option.show();
                            } else {
                                $option.hide();
                                if ($option.val() === currentProduct) {
                                    productSelect.val('all').trigger('change');
                                }
                            }
                        }
                    });
                });
            }
            
            initSelect2();
            
            // Initialize DataTable
            if ($.fn.DataTable) {
                var table = $('#productWiseTaxChargeTable');
                if ($.fn.DataTable.isDataTable(table)) {
                    table.DataTable().destroy();
                }
                
                // Check if table has data
                var hasData = table.find('tbody tr').length > 0 && 
                             table.find('tbody tr').first().find('td').first().text().trim() !== '';
                
                if (hasData) {
                    table.DataTable({
                        order: [[6, 'desc']],
                        pageLength: 25,
                        destroy: true,
                        autoWidth: false,
                        language: {
                            emptyTable: 'No data available'
                        }
                    });
                } else {
                    // Clear empty row so DataTables can show its empty message
                    table.find('tbody').empty();
                    table.DataTable({
                        order: [[6, 'desc']],
                        pageLength: 25,
                        destroy: true,
                        autoWidth: false,
                        language: {
                            emptyTable: 'No data available'
                        }
                    });
                }
            }
        });
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReport);
    } else {
        initReport();
    }
})();
</script>

