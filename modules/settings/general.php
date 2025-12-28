<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.edit');

$pageTitle = 'General Settings';

$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();
$success = '';
$error = '';

// Get all timezones
$timezones = timezone_identifiers_list();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle settings update
    $settingsToUpdate = [
        'allow_negative_stock' => isset($_POST['allow_negative_stock']) ? '1' : '0',
        'allow_product_discounts' => isset($_POST['allow_product_discounts']) ? '1' : '0',
        'allow_invoice_discounts' => isset($_POST['allow_invoice_discounts']) ? '1' : '0',
        'allow_cashier_view_stock' => isset($_POST['allow_cashier_view_stock']) ? '1' : '0',
        'allow_credit_sales' => isset($_POST['allow_credit_sales']) ? '1' : '0',
        'add_delivery_costs' => isset($_POST['add_delivery_costs']) ? '1' : '0',
        'send_low_stock_notifications' => isset($_POST['send_low_stock_notifications']) ? '1' : '0',
        'low_stock_notification_email' => $_POST['low_stock_notification_email'] ?? '',
        'send_expiry_notifications' => isset($_POST['send_expiry_notifications']) ? '1' : '0',
        'expiry_notification_email' => $_POST['expiry_notification_email'] ?? '',
        'timezone' => $_POST['timezone'] ?? 'Africa/Harare',
        'check_low_stock_at_login' => isset($_POST['check_low_stock_at_login']) ? '1' : '0',
        'allow_stock_take' => isset($_POST['allow_stock_take']) ? '1' : '0',
        'disallow_duplicate_logins' => isset($_POST['disallow_duplicate_logins']) ? '1' : '0',
        'prices_include_tax' => isset($_POST['prices_include_tax']) ? '1' : '0',
    ];
    
    $allUpdated = true;
    foreach ($settingsToUpdate as $key => $value) {
        if (!setSetting($key, $value)) {
            $allUpdated = false;
        }
    }
    
    if ($allUpdated) {
        $success = 'Settings updated successfully!';
    } else {
        $error = 'Some settings could not be updated. Please try again.';
    }
}

// Load current settings
$settings = [
    'allow_negative_stock' => getSetting('allow_negative_stock', '0'),
    'allow_product_discounts' => getSetting('allow_product_discounts', '1'),
    'allow_invoice_discounts' => getSetting('allow_invoice_discounts', '1'),
    'allow_cashier_view_stock' => getSetting('allow_cashier_view_stock', '1'),
    'allow_credit_sales' => getSetting('allow_credit_sales', '0'),
    'add_delivery_costs' => getSetting('add_delivery_costs', '0'),
    'send_low_stock_notifications' => getSetting('send_low_stock_notifications', '0'),
    'low_stock_notification_email' => getSetting('low_stock_notification_email', ''),
    'send_expiry_notifications' => getSetting('send_expiry_notifications', '0'),
    'expiry_notification_email' => getSetting('expiry_notification_email', ''),
    'timezone' => getSetting('timezone', 'Africa/Harare'),
    'check_low_stock_at_login' => getSetting('check_low_stock_at_login', '0'),
    'allow_stock_take' => getSetting('allow_stock_take', '1'),
    'disallow_duplicate_logins' => getSetting('disallow_duplicate_logins', '0'),
    'prices_include_tax' => getSetting('prices_include_tax', '1'), // Default ticked
];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>General Settings</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i> <?= escapeHtml($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle"></i> <?= escapeHtml($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="POST">
    <!-- Inventory Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-archive"></i> Inventory Settings</h5>
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="allow_negative_stock" id="allow_negative_stock" 
                       <?= $settings['allow_negative_stock'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="allow_negative_stock">
                    <strong>Allow Negative Stock Values?</strong>
                    <small class="d-block text-muted">Allow products to be sold even when stock is zero or negative. When GRN or stock take is done, it will calculate: -(negative sales) + (new stock)</small>
                </label>
            </div>
            
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="allow_stock_take" id="allow_stock_take" 
                       <?= $settings['allow_stock_take'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="allow_stock_take">
                    <strong>Allow Stock Take?</strong>
                    <small class="d-block text-muted">Enable stock take feature. Stock take will overwrite negative sales, unlike GRN which adds on top.</small>
                </label>
            </div>
            
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="allow_cashier_view_stock" id="allow_cashier_view_stock" 
                       <?= $settings['allow_cashier_view_stock'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="allow_cashier_view_stock">
                    <strong>Allow Cashier to View Stock Balances?</strong>
                    <small class="d-block text-muted">Show stock balance information on the POS interface</small>
                </label>
            </div>
            
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="check_low_stock_at_login" id="check_low_stock_at_login" 
                       <?= $settings['check_low_stock_at_login'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="check_low_stock_at_login">
                    <strong>Check Low Stock Levels at Login?</strong>
                    <small class="d-block text-muted">Display a modal notification on login showing all products with low stock levels</small>
                </label>
            </div>
        </div>
    </div>

    <!-- Discount Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-percent"></i> Discount Settings</h5>
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="allow_product_discounts" id="allow_product_discounts" 
                       <?= $settings['allow_product_discounts'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="allow_product_discounts">
                    <strong>Allow Product Discounts?</strong>
                    <small class="d-block text-muted">Allow discounts to be applied to individual products during sale</small>
                </label>
            </div>
            
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="allow_invoice_discounts" id="allow_invoice_discounts" 
                       <?= $settings['allow_invoice_discounts'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="allow_invoice_discounts">
                    <strong>Allow Invoice Discounts?</strong>
                    <small class="d-block text-muted">Allow discounts to be applied to the entire invoice/receipt</small>
                </label>
            </div>
        </div>
    </div>

    <!-- Sales Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-cart"></i> Sales Settings</h5>
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="allow_credit_sales" id="allow_credit_sales" 
                       <?= $settings['allow_credit_sales'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="allow_credit_sales">
                    <strong>Allow Account/Credit Sales?</strong>
                    <small class="d-block text-muted">Enable credit sales feature. Customers can be billed on account with payment terms</small>
                </label>
            </div>
            
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="add_delivery_costs" id="add_delivery_costs" 
                       <?= $settings['add_delivery_costs'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="add_delivery_costs">
                    <strong>Add Delivery Costs?</strong>
                    <small class="d-block text-muted">Allow adding delivery costs to sales</small>
                </label>
            </div>
            
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="prices_include_tax" id="prices_include_tax" 
                       <?= $settings['prices_include_tax'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="prices_include_tax">
                    <strong>Prices Entered Include Tax?</strong>
                    <small class="d-block text-muted">When enabled, receipts show price without tax (tax shown separately in VAT Summary). POS screen shows price including tax. Default: Enabled</small>
                </label>
            </div>
        </div>
    </div>

    <!-- Notification Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-bell"></i> Notification Settings</h5>
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="send_low_stock_notifications" id="send_low_stock_notifications" 
                       <?= $settings['send_low_stock_notifications'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="send_low_stock_notifications">
                    <strong>Send Low Stock Level Notifications?</strong>
                    <small class="d-block text-muted">Send daily email notifications for low stock levels at 7:00 AM (based on timezone)</small>
                </label>
            </div>
            
            <div class="mb-3" id="low_stock_email_group" style="display: <?= $settings['send_low_stock_notifications'] == '1' ? 'block' : 'none' ?>;">
                <label class="form-label">Low Stock Notification Email</label>
                <input type="email" class="form-control" name="low_stock_notification_email" 
                       value="<?= escapeHtml($settings['low_stock_notification_email']) ?>" 
                       placeholder="email@example.com">
                <small class="text-muted">Email address to receive low stock notifications</small>
            </div>
            
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="send_expiry_notifications" id="send_expiry_notifications" 
                       <?= $settings['send_expiry_notifications'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="send_expiry_notifications">
                    <strong>Send Product Expiry Date Notification?</strong>
                    <small class="d-block text-muted">Send daily email notifications for products within 3 months of expiry at 7:00 AM (based on timezone)</small>
                </label>
            </div>
            
            <div class="mb-3" id="expiry_email_group" style="display: <?= $settings['send_expiry_notifications'] == '1' ? 'block' : 'none' ?>;">
                <label class="form-label">Expiry Notification Email</label>
                <input type="email" class="form-control" name="expiry_notification_email" 
                       value="<?= escapeHtml($settings['expiry_notification_email']) ?>" 
                       placeholder="email@example.com">
                <small class="text-muted">Email address to receive product expiry notifications</small>
            </div>
        </div>
    </div>

    <!-- System Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-gear"></i> System Settings</h5>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label"><strong>Timezone</strong></label>
                <select class="form-select" name="timezone" id="timezone">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?= $tz ?>" <?= $settings['timezone'] == $tz ? 'selected' : '' ?>><?= $tz ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Timezone for cron jobs and notifications</small>
            </div>
            
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="disallow_duplicate_logins" id="disallow_duplicate_logins" 
                       <?= $settings['disallow_duplicate_logins'] == '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="disallow_duplicate_logins">
                    <strong>Disallow Duplicate Logins?</strong>
                    <small class="d-block text-muted">When enabled, logging in with the same credentials will logout the previous session</small>
                </label>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<script>
// Show/hide email fields based on checkbox state
document.getElementById('send_low_stock_notifications').addEventListener('change', function() {
    document.getElementById('low_stock_email_group').style.display = this.checked ? 'block' : 'none';
});

document.getElementById('send_expiry_notifications').addEventListener('change', function() {
    document.getElementById('expiry_email_group').style.display = this.checked ? 'block' : 'none';
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

