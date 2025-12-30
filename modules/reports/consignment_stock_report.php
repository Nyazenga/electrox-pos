<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';
require_once APP_PATH . '/includes/report_helper.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requireAnyPermission('reports.general', 'reports.view', 'reports.consignment_stock_report');

$pageTitle = 'Consignment Stock Report';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;

// Filters
$selectedBranch = $_GET['branch_id'] ?? ($branchId ?: 'all');
$selectedSupplier = $_GET['supplier_id'] ?? 'all';
$search = $_GET['search'] ?? '';

// Get branches
$branches = $db->getRows("SELECT * FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branches === false) $branches = [];

// Get suppliers
$suppliers = $db->getRows("SELECT * FROM suppliers ORDER BY name");
if ($suppliers === false) $suppliers = [];

// Check if consignment stock tracking exists
$consignmentTableExists = false;
try {
    $check = $db->getRow("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'consignment_stock'");
    $consignmentTableExists = ($check && $check['count'] > 0);
} catch (Exception $e) {
    $consignmentTableExists = false;
}

$consignmentStock = [];
$summary = [
    'total_items' => 0,
    'total_quantity' => 0,
    'total_value' => 0
];

if ($consignmentTableExists) {
    // Build query conditions
    $whereConditions = ["1=1"];
    $params = [];

    if ($selectedBranch !== 'all' && $selectedBranch) {
        $whereConditions[] = "cs.branch_id = :branch_id";
        $params[':branch_id'] = $selectedBranch;
    } elseif ($branchId !== null) {
        $whereConditions[] = "cs.branch_id = :branch_id";
        $params[':branch_id'] = $branchId;
    }

    if ($selectedSupplier !== 'all' && $selectedSupplier) {
        $whereConditions[] = "cs.supplier_id = :supplier_id";
        $params[':supplier_id'] = $selectedSupplier;
    }

    if (!empty($search)) {
        $whereConditions[] = "(p.product_code LIKE :search OR p.product_name LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Get summary stats
    $summary = $db->getRow("SELECT 
        COUNT(DISTINCT cs.product_id) as total_items,
        COALESCE(SUM(cs.quantity), 0) as total_quantity,
        COALESCE(SUM(cs.quantity * COALESCE(p.cost_price, 0)), 0) as total_value
    FROM consignment_stock cs
    LEFT JOIN products p ON cs.product_id = p.id
    WHERE $whereClause", $params);

    if ($summary === false) {
        $summary = [
            'total_items' => 0,
            'total_quantity' => 0,
            'total_value' => 0
        ];
    }

    // Get consignment stock
    $consignmentStock = $db->getRows("SELECT 
        cs.id,
        cs.quantity,
        cs.received_date,
        cs.notes,
        p.product_code,
        COALESCE(p.product_name, CONCAT(COALESCE(p.brand, ''), ' ', COALESCE(p.model, ''))) as product_name,
        pc.name as category_name,
        b.branch_name,
        s.name as supplier_name,
        p.cost_price,
        p.selling_price,
        (cs.quantity * COALESCE(p.cost_price, 0)) as stock_value
    FROM consignment_stock cs
    LEFT JOIN products p ON cs.product_id = p.id
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    LEFT JOIN branches b ON cs.branch_id = b.id
    LEFT JOIN suppliers s ON cs.supplier_id = s.id
    WHERE $whereClause
    ORDER BY cs.received_date DESC, p.product_code
    LIMIT 1000", $params);

    if ($consignmentStock === false) {
        $consignmentStock = [];
    }
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $html = '<h2 style="text-align: center; margin-bottom: 20px;">Consignment Stock Report</h2>';
    $html .= '<p style="text-align: center; color: #666;">Generated: ' . date('M d, Y H:i') . '</p>';
    
    if (!$consignmentTableExists || empty($consignmentStock)) {
        $html .= '<p style="text-align: center; color: #666;">No consignment stock found or consignment stock feature not available.</p>';
    } else {
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th style="text-align: left;">Metric</th><th style="text-align: right;">Value</th></tr>';
        $html .= '<tr><td>Total Items</td><td style="text-align: right;">' . $summary['total_items'] . '</td></tr>';
        $html .= '<tr><td>Total Quantity</td><td style="text-align: right;">' . number_format($summary['total_quantity'], 2) . '</td></tr>';
        $html .= '<tr><td>Total Value</td><td style="text-align: right;">' . formatCurrency($summary['total_value']) . '</td></tr>';
        $html .= '</table>';
        
        $html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Consignment Stock Details</h3>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; font-size: 8px;">';
        $html .= '<tr style="background-color: #f0f0f0;"><th>Product Code</th><th>Product Name</th><th>Category</th><th>Supplier</th><th>Branch</th><th>Received Date</th><th style="text-align: right;">Qty</th><th style="text-align: right;">Cost Price</th><th style="text-align: right;">Value</th></tr>';
        foreach ($consignmentStock as $item) {
            $html .= '<tr>';
            $html .= '<td>' . escapeHtml($item['product_code'] ?? 'N/A') . '</td>';
            $html .= '<td>' . escapeHtml(substr($item['product_name'] ?? 'N/A', 0, 25)) . '</td>';
            $html .= '<td>' . escapeHtml($item['category_name'] ?? 'Uncategorized') . '</td>';
            $html .= '<td>' . escapeHtml($item['supplier_name'] ?? 'N/A') . '</td>';
            $html .= '<td>' . escapeHtml($item['branch_name'] ?? 'N/A') . '</td>';
            $html .= '<td>' . date('M d, Y', strtotime($item['received_date'])) . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($item['quantity'], 2) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($item['cost_price']) . '</td>';
            $html .= '<td style="text-align: right;">' . formatCurrency($item['stock_value']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    ReportHelper::generatePDF('Consignment Stock Report', $html, 'Consignment_Stock_' . date('Ymd') . '.pdf');
    exit;
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam"></i> Consignment Stock Report</h2>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn btn-primary"><i class="bi bi-file-pdf"></i> Export PDF</a>
    </div>
</div>

<?php if (!$consignmentTableExists): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> Consignment stock feature is not available. The consignment_stock table does not exist in the database.
    </div>
<?php else: ?>
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label"><i class="bi bi-shop"></i> Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>><?= escapeHtml($branch['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><i class="bi bi-truck"></i> Supplier</label>
                    <select name="supplier_id" class="form-select">
                        <option value="all" <?= $selectedSupplier === 'all' ? 'selected' : '' ?>>All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= $supplier['id'] ?>" <?= $selectedSupplier == $supplier['id'] ? 'selected' : '' ?>><?= escapeHtml($supplier['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="bi bi-search"></i> Search</label>
                    <input type="text" name="search" value="<?= escapeHtml($search) ?>" class="form-control" placeholder="Search products...">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-2">Total Items</h6>
                    <h3 class="mb-0"><?= $summary['total_items'] ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-2">Total Quantity</h6>
                    <h3 class="mb-0"><?= number_format($summary['total_quantity'], 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-2">Total Value</h6>
                    <h3 class="mb-0"><?= formatCurrency($summary['total_value']) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="consignmentTable">
                    <thead>
                        <tr>
                            <th>Product Code</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Supplier</th>
                            <th>Branch</th>
                            <th>Received Date</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Cost Price</th>
                            <th class="text-end">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($consignmentStock)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No consignment stock found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($consignmentStock as $item): ?>
                                <tr>
                                    <td><span class="fw-bold"><?= escapeHtml($item['product_code'] ?? 'N/A') ?></span></td>
                                    <td><?= escapeHtml($item['product_name'] ?? 'N/A') ?></td>
                                    <td><?= escapeHtml($item['category_name'] ?? 'Uncategorized') ?></td>
                                    <td><?= escapeHtml($item['supplier_name'] ?? 'N/A') ?></td>
                                    <td><?= escapeHtml($item['branch_name'] ?? 'N/A') ?></td>
                                    <td><?= date('M d, Y', strtotime($item['received_date'])) ?></td>
                                    <td class="text-end"><?= number_format($item['quantity'], 2) ?></td>
                                    <td class="text-end"><?= formatCurrency($item['cost_price']) ?></td>
                                    <td class="text-end fw-bold"><?= formatCurrency($item['stock_value']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        $('#consignmentTable').DataTable({
            order: [[5, 'desc']],
            pageLength: 25,
            responsive: true
        });
    });
    </script>
<?php endif; ?>

<?php require_once APP_PATH . '/includes/footer.php'; ?>


