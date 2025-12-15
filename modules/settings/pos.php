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
    // Handle receipt logo upload
    if (isset($_FILES['receipt_logo']) && $_FILES['receipt_logo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = APP_PATH . '/assets/uploads/receipts/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileType = $_FILES['receipt_logo']['type'];
        
        if (in_array($fileType, $allowedTypes)) {
            $extension = pathinfo($_FILES['receipt_logo']['name'], PATHINFO_EXTENSION);
            $fileName = 'receipt_logo_' . time() . '.' . $extension;
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['receipt_logo']['tmp_name'], $filePath)) {
                $logoPath = '/assets/uploads/receipts/' . $fileName;
                setSetting('pos_receipt_logo', $logoPath);
            }
        }
    }
    
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'setting_') === 0) {
            $settingKey = str_replace('setting_', '', $key);
            setSetting($settingKey, $value);
        }
    }
    $success = 'Settings updated successfully!';
}

$settings = [
    'pos_receipt_logo' => getSetting('pos_receipt_logo', ''),
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
        <form method="POST" enctype="multipart/form-data">
            <!-- Receipt Logo -->
            <div class="mb-4">
                <label class="form-label fw-bold">Receipt Logo</label>
                <?php 
                $receiptLogoPath = $settings['pos_receipt_logo'];
                $receiptLogoUrl = '';
                $receiptLogoFullPath = '';
                if ($receiptLogoPath) {
                    $receiptLogoUrl = BASE_URL . ltrim($receiptLogoPath, '/');
                    $receiptLogoFullPath = APP_PATH . '/' . ltrim($receiptLogoPath, '/');
                }
                ?>
                <?php if ($receiptLogoPath && file_exists($receiptLogoFullPath)): ?>
                    <div class="mb-3">
                        <p class="text-muted mb-2">Current Logo:</p>
                        <img src="<?= htmlspecialchars($receiptLogoUrl) ?>" alt="Receipt Logo" style="max-width: 200px; max-height: 100px; border: 1px solid #ddd; padding: 5px; border-radius: 4px;" onerror="this.style.display='none';">
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-2">No logo uploaded</p>
                <?php endif; ?>
                <input type="file" name="receipt_logo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                <small class="text-muted">Upload a logo to display on receipts (JPEG, PNG, GIF, or WebP)</small>
            </div>
            
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

