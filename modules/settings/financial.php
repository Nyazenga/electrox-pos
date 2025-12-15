<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/currency_functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.view');

$pageTitle = 'Financial Settings';

$db = Database::getInstance();
// Use main database for currencies (they're stored in the base database)
$mainDb = Database::getMainInstance();
$baseCurrency = getBaseCurrency($mainDb);
$currencies = getActiveCurrencies($mainDb);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $baseCurrencyId = intval($_POST['base_currency_id'] ?? 0);
    
    if ($baseCurrencyId) {
        // Use main database for currencies
        $mainDb = Database::getMainInstance();
        
        // Unset all base currencies
        $mainDb->update('currencies', ['is_base' => 0], []);
        
        // Set new base currency
        $mainDb->update('currencies', [
            'is_base' => 1,
            'exchange_rate' => 1.000000
        ], ['id' => $baseCurrencyId]);
        
        $_SESSION['success_message'] = 'Base currency updated successfully';
        redirectTo('modules/settings/index.php?page=financial');
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Financial Settings</h2>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Currency Settings</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Base Currency *</label>
                <select class="form-select" name="base_currency_id" required>
                    <?php if (empty($currencies)): ?>
                        <option value="">No currencies found. Please add currencies first.</option>
                    <?php else: ?>
                        <?php foreach ($currencies as $currency): ?>
                            <option value="<?= $currency['id'] ?>" <?= $currency['is_base'] ? 'selected' : '' ?>>
                                <?= escapeHtml($currency['code']) ?> - <?= escapeHtml($currency['name']) ?>
                                <?= $currency['is_base'] ? ' (Current Base)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <small class="text-muted">All amounts will be converted to this base currency for reporting and calculations.</small>
            </div>
            
            <div class="alert alert-info">
                <strong>Note:</strong> Changing the base currency will affect all future transactions. Historical data will remain in the original currency but will be converted for display purposes.
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> Save Settings
            </button>
        </form>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0">Active Currencies</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Symbol</th>
                        <th>Exchange Rate</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($currencies)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                <i class="bi bi-info-circle"></i> No currencies found. 
                                <a href="<?= BASE_URL ?>modules/currencies/index.php">Add currencies</a> to get started.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($currencies as $currency): ?>
                            <tr>
                                <td><strong><?= escapeHtml($currency['code']) ?></strong></td>
                                <td><?= escapeHtml($currency['name']) ?></td>
                                <td><?= escapeHtml($currency['symbol']) ?></td>
                                <td>
                                    <?php if ($currency['is_base']): ?>
                                        <span class="badge bg-primary">Base (1.000000)</span>
                                    <?php else: ?>
                                        <?= number_format($currency['exchange_rate'], 6) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($currency['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>modules/currencies/edit.php?id=<?= $currency['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                    <a href="<?= BASE_URL ?>modules/currencies/exchange_rates.php?currency_id=<?= $currency['id'] ?>" class="btn btn-sm btn-outline-info">
                                        <i class="bi bi-arrow-left-right"></i> Rates
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <a href="<?= BASE_URL ?>modules/currencies/index.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Manage Currencies
            </a>
        </div>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>
