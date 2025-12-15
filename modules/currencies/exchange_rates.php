<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.view');

$pageTitle = 'Exchange Rate Management';

$db = Database::getInstance();
$currencyId = intval($_GET['currency_id'] ?? 0);
$currency = $currencyId ? getCurrency($currencyId, $db) : null;

if (!$currency) {
    redirectTo('modules/currencies/index.php');
}

$baseCurrency = getBaseCurrency($db);

// Get exchange rate history
$rateHistory = $db->getRows("
    SELECT * FROM currency_exchange_rates 
    WHERE (from_currency_id = :base_id AND to_currency_id = :currency_id)
       OR (from_currency_id = :currency_id AND to_currency_id = :base_id)
    ORDER BY effective_date DESC, created_at DESC
    LIMIT 50
", [
    ':base_id' => $baseCurrency['id'],
    ':currency_id' => $currencyId
]);
if ($rateHistory === false) $rateHistory = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newRate = floatval($_POST['exchange_rate'] ?? 0);
    $effectiveDate = $_POST['effective_date'] ?? date('Y-m-d');
    
    if ($newRate <= 0) {
        $error = 'Exchange rate must be greater than 0';
    } else {
        // Update currency exchange rate
        $db->update('currencies', [
            'exchange_rate' => $newRate
        ], ['id' => $currencyId]);
        
        // Record in history
        $db->insert('currency_exchange_rates', [
            'from_currency_id' => $baseCurrency['id'],
            'to_currency_id' => $currencyId,
            'rate' => $newRate,
            'effective_date' => $effectiveDate,
            'created_by' => $_SESSION['user_id']
        ]);
        
        $_SESSION['success_message'] = 'Exchange rate updated successfully';
        redirectTo('modules/currencies/exchange_rates.php?currency_id=' . $currencyId);
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Exchange Rate: <?= escapeHtml($currency['code']) ?> - <?= escapeHtml($currency['name']) ?></h2>
    <a href="index.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Back
    </a>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= escapeHtml($error) ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Update Exchange Rate</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Current Rate</label>
                        <div class="alert alert-info">
                            <strong>1 <?= escapeHtml($baseCurrency['code']) ?> = <?= number_format($currency['exchange_rate'], 6) ?> <?= escapeHtml($currency['code']) ?></strong>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Exchange Rate *</label>
                        <input type="number" class="form-control" name="exchange_rate" 
                               step="0.000001" min="0.000001" 
                               value="<?= escapeHtml($currency['exchange_rate']) ?>" required>
                        <small class="text-muted">1 <?= escapeHtml($baseCurrency['code']) ?> = ? <?= escapeHtml($currency['code']) ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Effective Date *</label>
                        <input type="date" class="form-control" name="effective_date" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-save"></i> Update Rate
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Rate History</h5>
            </div>
            <div class="card-body">
                <?php if (empty($rateHistory)): ?>
                    <p class="text-muted">No rate history available.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Effective Date</th>
                                    <th>Rate</th>
                                    <th>Updated By</th>
                                    <th>Updated At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rateHistory as $rate): ?>
                                    <tr>
                                        <td><?= escapeHtml($rate['effective_date']) ?></td>
                                        <td><strong>1 <?= escapeHtml($baseCurrency['code']) ?> = <?= number_format($rate['rate'], 6) ?> <?= escapeHtml($currency['code']) ?></strong></td>
                                        <td>
                                            <?php
                                            $user = $db->getRow("SELECT first_name, last_name FROM users WHERE id = :id", [':id' => $rate['created_by']]);
                                            echo $user ? escapeHtml($user['first_name'] . ' ' . $user['last_name']) : 'System';
                                            ?>
                                        </td>
                                        <td><?= escapeHtml($rate['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

