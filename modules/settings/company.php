<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.edit');

$pageTitle = 'Company Settings';

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
    'company_name' => getSetting('company_name', ''),
    'company_address' => getSetting('company_address', ''),
    'company_phone' => getSetting('company_phone', ''),
    'company_email' => getSetting('company_email', ''),
    'company_tax_id' => getSetting('company_tax_id', ''),
    'company_tin' => getSetting('company_tin', ''),
    'company_vat_number' => getSetting('company_vat_number', ''),
    'company_bank_name' => getSetting('company_bank_name', ''),
    'company_bank_account' => getSetting('company_bank_account', ''),
    'company_bank_branch' => getSetting('company_bank_branch', ''),
];

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Company Settings</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?= escapeHtml($success) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">Company Information</div>
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label>Company Name</label>
                <input type="text" name="setting_company_name" value="<?= escapeHtml($settings['company_name']) ?>" class="form-control">
            </div>
            <div class="mb-3">
                <label>Address</label>
                <textarea name="setting_company_address" class="form-control" rows="3"><?= escapeHtml($settings['company_address']) ?></textarea>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label>Phone</label>
                    <input type="text" name="setting_company_phone" value="<?= escapeHtml($settings['company_phone']) ?>" class="form-control">
                </div>
                <div class="col-md-6 mb-3">
                    <label>Email</label>
                    <input type="email" name="setting_company_email" value="<?= escapeHtml($settings['company_email']) ?>" class="form-control">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label>Tax ID</label>
                    <input type="text" name="setting_company_tax_id" value="<?= escapeHtml($settings['company_tax_id']) ?>" class="form-control">
                </div>
                <div class="col-md-6 mb-3">
                    <label>TIN #</label>
                    <input type="text" name="setting_company_tin" value="<?= escapeHtml($settings['company_tin']) ?>" class="form-control" placeholder="e.g., 2001286483">
                </div>
            </div>
            <div class="mb-3">
                <label>VAT Number</label>
                <input type="text" name="setting_company_vat_number" value="<?= escapeHtml($settings['company_vat_number']) ?>" class="form-control" placeholder="e.g., 220108354">
            </div>
            
            <hr class="my-4">
            <h5 class="mb-3">Banking Details</h5>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label>Bank Name</label>
                    <input type="text" name="setting_company_bank_name" value="<?= escapeHtml($settings['company_bank_name']) ?>" class="form-control" placeholder="e.g., CBZ Bank">
                </div>
                <div class="col-md-6 mb-3">
                    <label>Account Number</label>
                    <input type="text" name="setting_company_bank_account" value="<?= escapeHtml($settings['company_bank_account']) ?>" class="form-control" placeholder="e.g., 66162012930020">
                </div>
            </div>
            <div class="mb-3">
                <label>Bank Branch</label>
                <input type="text" name="setting_company_bank_branch" value="<?= escapeHtml($settings['company_bank_branch']) ?>" class="form-control" placeholder="e.g., Avondale Branch">
            </div>
            
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
        </form>
    </div>
</div>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

