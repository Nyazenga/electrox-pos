<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.edit');

$pageTitle = 'Add Currency';

$db = Database::getInstance();
$baseCurrency = $db->getRow("SELECT * FROM currencies WHERE is_base = 1");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $symbol = trim($_POST['symbol'] ?? '');
    $symbolPosition = $_POST['symbol_position'] ?? 'before';
    $decimalPlaces = intval($_POST['decimal_places'] ?? 2);
    $isBase = isset($_POST['is_base']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $exchangeRate = floatval($_POST['exchange_rate'] ?? 1.0);
    
    // Validation
    if (empty($code) || strlen($code) !== 3) {
        $error = 'Currency code must be exactly 3 characters (e.g., USD, ZAR)';
    } else if (empty($name)) {
        $error = 'Currency name is required';
    } else if (empty($symbol)) {
        $error = 'Currency symbol is required';
    } else {
        // Check if code already exists
        $existing = $db->getRow("SELECT id FROM currencies WHERE code = :code", [':code' => $code]);
        if ($existing) {
            $error = 'Currency code already exists';
        } else {
            // If setting as base, unset other base currencies
            if ($isBase) {
                $db->update('currencies', ['is_base' => 0], []);
                $exchangeRate = 1.0; // Base currency always has rate of 1
            }
            
            $currencyData = [
                'code' => $code,
                'name' => $name,
                'symbol' => $symbol,
                'symbol_position' => $symbolPosition,
                'decimal_places' => $decimalPlaces,
                'is_base' => $isBase,
                'is_active' => $isActive,
                'exchange_rate' => $exchangeRate,
                'created_by' => $_SESSION['user_id']
            ];
            
            $currencyId = $db->insert('currencies', $currencyData);
            
            if ($currencyId) {
                $_SESSION['success_message'] = 'Currency added successfully';
                redirectTo('modules/currencies/index.php');
            } else {
                $error = 'Failed to add currency: ' . $db->getLastError();
            }
        }
    }
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Add Currency</h2>
    <a href="index.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Back
    </a>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= escapeHtml($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Currency Code * <small class="text-muted">(3 letters, e.g., USD, ZAR)</small></label>
                    <input type="text" class="form-control" name="code" maxlength="3" required 
                           value="<?= escapeHtml($_POST['code'] ?? '') ?>" 
                           pattern="[A-Z]{3}" 
                           oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Currency Name *</label>
                    <input type="text" class="form-control" name="name" required 
                           value="<?= escapeHtml($_POST['name'] ?? '') ?>">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Symbol *</label>
                    <input type="text" class="form-control" name="symbol" required 
                           value="<?= escapeHtml($_POST['symbol'] ?? '') ?>" maxlength="10">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Symbol Position *</label>
                    <select class="form-select" name="symbol_position" required>
                        <option value="before" <?= ($_POST['symbol_position'] ?? 'before') === 'before' ? 'selected' : '' ?>>Before Amount (e.g., $100)</option>
                        <option value="after" <?= ($_POST['symbol_position'] ?? '') === 'after' ? 'selected' : '' ?>>After Amount (e.g., 100 kr)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Decimal Places *</label>
                    <input type="number" class="form-control" name="decimal_places" min="0" max="4" value="<?= escapeHtml($_POST['decimal_places'] ?? 2) ?>" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Exchange Rate * <small class="text-muted">(relative to base currency)</small></label>
                    <input type="number" class="form-control" name="exchange_rate" step="0.000001" min="0.000001" 
                           value="<?= escapeHtml($_POST['exchange_rate'] ?? ($baseCurrency ? $baseCurrency['exchange_rate'] : 1.0)) ?>" 
                           required <?= isset($_POST['is_base']) && $_POST['is_base'] ? 'readonly' : '' ?>>
                    <?php if ($baseCurrency): ?>
                        <small class="text-muted">1 <?= escapeHtml($baseCurrency['code']) ?> = <span id="rateDisplay"><?= number_format($_POST['exchange_rate'] ?? 1.0, 6) ?></span> <?= escapeHtml($_POST['code'] ?? 'XXX') ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="is_base" id="isBase" 
                               <?= isset($_POST['is_base']) ? 'checked' : '' ?> 
                               onchange="toggleBaseCurrency()">
                        <label class="form-check-label" for="isBase">
                            Set as Base Currency
                        </label>
                        <?php if ($baseCurrency): ?>
                            <small class="text-muted d-block">Current base: <?= escapeHtml($baseCurrency['code']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" 
                               <?= !isset($_POST['is_active']) || $_POST['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isActive">
                            Active
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Currency
                </button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleBaseCurrency() {
    const isBase = document.getElementById('isBase').checked;
    const exchangeRateInput = document.querySelector('input[name="exchange_rate"]');
    
    if (isBase) {
        exchangeRateInput.value = '1.000000';
        exchangeRateInput.readOnly = true;
    } else {
        exchangeRateInput.readOnly = false;
    }
}

document.querySelector('input[name="code"]').addEventListener('input', function() {
    const code = this.value;
    const rateDisplay = document.getElementById('rateDisplay');
    if (rateDisplay) {
        // Update display text if needed
    }
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

