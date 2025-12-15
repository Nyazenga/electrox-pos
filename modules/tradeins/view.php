<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('tradeins.view');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    redirectTo('modules/tradeins/index.php');
}

$pageTitle = 'View Trade-In';

$db = Database::getInstance();
$tradein = $db->getRow("SELECT t.*, c.first_name, c.last_name, c.email, c.phone, b.branch_name, 
                        p.brand as new_product_brand, p.model as new_product_model, p.product_code as new_product_code
                        FROM trade_ins t 
                        LEFT JOIN customers c ON t.customer_id = c.id 
                        LEFT JOIN branches b ON t.branch_id = b.id 
                        LEFT JOIN products p ON t.new_product_id = p.id
                        WHERE t.id = :id", [':id' => $id]);

if (!$tradein) {
    redirectTo('modules/tradeins/index.php');
}

// Extract and parse product details JSON from valuation_notes
$productDetails = [];
$valuationNotes = $tradein['valuation_notes'] ?? '';

if (!empty($valuationNotes) && strpos($valuationNotes, 'PRODUCT_DETAILS_JSON:') !== false) {
    // Split the notes and JSON
    $parts = explode('PRODUCT_DETAILS_JSON:', $valuationNotes);
    $valuationNotes = trim($parts[0]); // Get the actual notes (before JSON)
    
    if (isset($parts[1])) {
        $jsonPart = trim($parts[1]);
        $productDetails = json_decode($jsonPart, true) ?? [];
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Trade-In Details</h2>
    <div>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        <?php if ($auth->hasPermission('tradeins.edit')): ?>
            <a href="edit.php?id=<?= $tradein['id'] ?>" class="btn btn-warning"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Trade-In Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Trade-In #:</th>
                        <td><?= escapeHtml($tradein['trade_in_number']) ?></td>
                    </tr>
                    <tr>
                        <th>Date:</th>
                        <td><?= formatDate($tradein['created_at'] ?? date('Y-m-d')) ?></td>
                    </tr>
                    <tr>
                        <th>Device Name:</th>
                        <td><?= escapeHtml(($tradein['device_brand'] ?? '') . ' ' . ($tradein['device_model'] ?? '')) ?></td>
                    </tr>
                    <tr>
                        <th>Device Category:</th>
                        <td><?= escapeHtml($tradein['device_category'] ?? ($productDetails['device_category'] ?? 'N/A')) ?></td>
                    </tr>
                    <?php if (!empty($productDetails['device_color']) || !empty($tradein['device_color'])): ?>
                    <tr>
                        <th>Color:</th>
                        <td><?= escapeHtml($tradein['device_color'] ?? ($productDetails['device_color'] ?? 'N/A')) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($productDetails['device_storage']) || !empty($tradein['device_storage'])): ?>
                    <tr>
                        <th>Storage:</th>
                        <td><?= escapeHtml($tradein['device_storage'] ?? ($productDetails['device_storage'] ?? 'N/A')) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($productDetails['battery_health'])): ?>
                    <tr>
                        <th>Battery Health:</th>
                        <td><?= escapeHtml($productDetails['battery_health']) ?>%</td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($productDetails['serial_number'])): ?>
                    <tr>
                        <th>Serial Number:</th>
                        <td><?= escapeHtml($productDetails['serial_number']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($productDetails['imei'])): ?>
                    <tr>
                        <th>IMEI:</th>
                        <td><?= escapeHtml($productDetails['imei']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($productDetails['sim_configuration'])): ?>
                    <tr>
                        <th>SIM Configuration:</th>
                        <td><?= escapeHtml($productDetails['sim_configuration']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Device Condition:</th>
                        <td><span class="badge bg-<?= ($tradein['device_condition'] ?? 'B') == 'A+' ? 'success' : (($tradein['device_condition'] ?? 'B') == 'A' ? 'info' : 'warning') ?>"><?= escapeHtml($tradein['device_condition'] ?? 'N/A') ?></span></td>
                    </tr>
                    <?php if (!empty($productDetails['cost_price']) || !empty($productDetails['selling_price'])): ?>
                    <tr>
                        <th>Product Details:</th>
                        <td>
                            <?php if (!empty($productDetails['cost_price'])): ?>
                                <strong>Cost Price:</strong> <?= formatCurrency($productDetails['cost_price']) ?><br>
                            <?php endif; ?>
                            <?php if (!empty($productDetails['selling_price'])): ?>
                                <strong>Selling Price:</strong> <?= formatCurrency($productDetails['selling_price']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Getting (New Product):</th>
                        <td>
                            <?php if ($tradein['new_product_brand']): ?>
                                <strong><?= escapeHtml($tradein['new_product_brand'] . ' ' . $tradein['new_product_model']) ?></strong>
                                <?php if ($tradein['new_product_code']): ?>
                                    <br><small class="text-muted">Code: <?= escapeHtml($tradein['new_product_code']) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Trade-In Value:</th>
                        <td><strong><?= formatCurrency($tradein['final_valuation'] ?? 0) ?></strong></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><span class="badge bg-<?= $tradein['status'] == 'Completed' ? 'success' : ($tradein['status'] == 'Pending' ? 'warning' : 'secondary') ?>"><?= escapeHtml($tradein['status']) ?></span></td>
                    </tr>
                    <tr>
                        <th>Branch:</th>
                        <td><?= escapeHtml($tradein['branch_name'] ?? 'N/A') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Customer Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Name:</th>
                        <td><?= escapeHtml(($tradein['first_name'] ?? '') . ' ' . ($tradein['last_name'] ?? '')) ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?= escapeHtml($tradein['email'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?= escapeHtml($tradein['phone'] ?? 'N/A') ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <?php if (!empty($valuationNotes) || !empty($tradein['cosmetic_issues']) || !empty($tradein['functional_issues']) || !empty($tradein['accessories_included']) || !empty($productDetails['description']) || !empty($productDetails['specifications']) || !empty($productDetails['date_of_first_use'])): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Notes & Details</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($valuationNotes)): ?>
                        <div class="mb-3">
                            <strong>Valuation Notes:</strong>
                            <p><?= nl2br(escapeHtml($valuationNotes)) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($productDetails['description'])): ?>
                        <div class="mb-3">
                            <strong>Description:</strong>
                            <p><?= nl2br(escapeHtml($productDetails['description'])) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($productDetails['specifications'])): ?>
                        <div class="mb-3">
                            <strong>Specifications:</strong>
                            <p><?= nl2br(escapeHtml($productDetails['specifications'])) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($tradein['cosmetic_issues']) || !empty($productDetails['cosmetic_issues'])): ?>
                        <div class="mb-3">
                            <strong>Cosmetic Issues:</strong>
                            <p><?= nl2br(escapeHtml($tradein['cosmetic_issues'] ?? $productDetails['cosmetic_issues'] ?? '')) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($tradein['functional_issues']) || !empty($productDetails['functional_issues'])): ?>
                        <div class="mb-3">
                            <strong>Functional Issues:</strong>
                            <p><?= nl2br(escapeHtml($tradein['functional_issues'] ?? $productDetails['functional_issues'] ?? '')) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($tradein['accessories_included']) || !empty($productDetails['accessories_included'])): ?>
                        <div class="mb-3">
                            <strong>Accessories Included:</strong>
                            <p><?= nl2br(escapeHtml($tradein['accessories_included'] ?? $productDetails['accessories_included'] ?? '')) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($productDetails['date_of_first_use'])): ?>
                        <div class="mb-3">
                            <strong>Date of First Use:</strong>
                            <p><?= formatDate($productDetails['date_of_first_use']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

