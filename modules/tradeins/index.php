<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('tradeins.view');

$pageTitle = 'Trade-Ins';

$db = Database::getInstance();
$tradeins = $db->getRows("SELECT t.*, c.first_name, c.last_name, p.brand as new_product_brand, p.model as new_product_model 
                          FROM trade_ins t 
                          LEFT JOIN customers c ON t.customer_id = c.id 
                          LEFT JOIN products p ON t.new_product_id = p.id 
                          ORDER BY t.created_at DESC");

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Trade-Ins</h2>
    <?php if ($auth->hasPermission('tradeins.create')): ?>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New Trade-In</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped data-table">
            <thead>
                <tr>
                    <th>Trade-In #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Device</th>
                    <th>Getting</th>
                    <th>Condition</th>
                    <th>Valuation</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tradeins as $tradein): ?>
                    <tr>
                        <td><?= escapeHtml($tradein['trade_in_number']) ?></td>
                        <td><?= formatDate($tradein['created_at']) ?></td>
                        <td><?= escapeHtml(($tradein['first_name'] ?? '') . ' ' . ($tradein['last_name'] ?? 'N/A')) ?></td>
                        <td><?= escapeHtml($tradein['device_brand'] . ' ' . $tradein['device_model']) ?></td>
                        <td><?= $tradein['new_product_brand'] ? escapeHtml($tradein['new_product_brand'] . ' ' . $tradein['new_product_model']) : '<span class="text-muted">N/A</span>' ?></td>
                        <td><span class="badge bg-info"><?= escapeHtml($tradein['device_condition']) ?></span></td>
                        <td><?= formatCurrency($tradein['final_valuation'] ?? 0) ?></td>
                        <td><span class="badge bg-<?= $tradein['status'] == 'Processed' ? 'success' : ($tradein['status'] == 'Accepted' ? 'primary' : ($tradein['status'] == 'Assessed' ? 'warning' : 'secondary')) ?>"><?= escapeHtml($tradein['status']) ?></span></td>
                        <td>
                            <a href="view.php?id=<?= $tradein['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                            <?php if ($tradein['status'] == 'Accepted' && $auth->hasPermission('tradeins.process')): ?>
                                <button onclick="processTradeIn(<?= $tradein['id'] ?>)" class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> Process</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function processTradeIn(tradeInId) {
    Swal.fire({
        title: 'Process Trade-In?',
        text: 'This will create a sale for the trade-in value',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Process',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= BASE_URL ?>ajax/process_tradein.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({trade_in_id: tradeInId})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success', 'Trade-in processed successfully', 'success').then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

