<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.edit');

$pageTitle = 'POS Settings';

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
    'pos_receipt_header' => getSetting('pos_receipt_header', 'Thank you for shopping with us!'),
    'pos_receipt_footer' => getSetting('pos_receipt_footer', 'Visit us again!'),
    'pos_default_tax_rate' => getSetting('pos_default_tax_rate', '15'),
    'pos_auto_print' => getSetting('pos_auto_print', '0'),
];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>POS Settings</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?= escapeHtml($success) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">POS Configuration</div>
    <div class="card-body">
        <!-- Receipt Logo Note -->
        <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle"></i> <strong>Receipt Logo:</strong> To upload or configure the receipt logo, please use the <a href="<?= BASE_URL ?>modules/pos/customize.php" class="alert-link">POS Customization</a> page.
        </div>
        
        <form method="POST">
            <div class="mb-3">
                <label>Receipt Header Text</label>
                <input type="text" name="setting_pos_receipt_header" value="<?= escapeHtml($settings['pos_receipt_header']) ?>" class="form-control">
            </div>
            <div class="mb-3">
                <label>Receipt Footer Text</label>
                <input type="text" name="setting_pos_receipt_footer" value="<?= escapeHtml($settings['pos_receipt_footer']) ?>" class="form-control">
            </div>
            <div class="mb-3">
                <label>Default Tax Rate (%)</label>
                <input type="number" name="setting_pos_default_tax_rate" value="<?= escapeHtml($settings['pos_default_tax_rate']) ?>" class="form-control" step="0.01">
            </div>
            <div class="mb-3">
                <div class="form-check">
                    <input type="checkbox" name="setting_pos_auto_print" value="1" <?= $settings['pos_auto_print'] == '1' ? 'checked' : '' ?> class="form-check-input" id="autoPrint">
                    <label class="form-check-label" for="autoPrint">Auto Print Receipt</label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
        </form>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

