<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.edit');

$pageTitle = 'Inventory Settings';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'setting_') === 0) {
            $settingKey = str_replace('setting_', '', $key);
            setSetting($settingKey, $value);
        }
    }
    $success = 'Settings updated successfully!';
}

$settings = [
    'inventory_auto_reorder' => getSetting('inventory_auto_reorder', '0'),
    'inventory_low_stock_alert' => getSetting('inventory_low_stock_alert', '1'),
    'inventory_tracking_method' => getSetting('inventory_tracking_method', 'FIFO'),
];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Inventory Settings</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?= escapeHtml($success) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">Inventory Configuration</div>
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <div class="form-check">
                    <input type="checkbox" name="setting_inventory_auto_reorder" value="1" <?= $settings['inventory_auto_reorder'] == '1' ? 'checked' : '' ?> class="form-check-input" id="autoReorder">
                    <label class="form-check-label" for="autoReorder">Enable Auto Reorder</label>
                </div>
            </div>
            <div class="mb-3">
                <div class="form-check">
                    <input type="checkbox" name="setting_inventory_low_stock_alert" value="1" <?= $settings['inventory_low_stock_alert'] == '1' ? 'checked' : '' ?> class="form-check-input" id="lowStockAlert">
                    <label class="form-check-label" for="lowStockAlert">Enable Low Stock Alerts</label>
                </div>
            </div>
            <div class="mb-3">
                <label>Inventory Tracking Method</label>
                <select name="setting_inventory_tracking_method" class="form-select">
                    <option value="FIFO" <?= $settings['inventory_tracking_method'] == 'FIFO' ? 'selected' : '' ?>>FIFO (First In, First Out)</option>
                    <option value="LIFO" <?= $settings['inventory_tracking_method'] == 'LIFO' ? 'selected' : '' ?>>LIFO (Last In, First Out)</option>
                    <option value="AVERAGE" <?= $settings['inventory_tracking_method'] == 'AVERAGE' ? 'selected' : '' ?>>Average Cost</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
        </form>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

