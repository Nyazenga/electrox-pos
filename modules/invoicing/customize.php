<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.edit');

$pageTitle = 'Invoice Customization';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updated = false;
    $errors = [];
    
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'setting_') === 0) {
            $settingKey = str_replace('setting_', '', $key);
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            // Convert empty strings to null for optional fields, but keep '0' for checkboxes
            if ($value === '' && !in_array($settingKey, ['invoice_show_logo', 'invoice_show_tax_id'])) {
                $value = null;
            }
            // Ensure value is a string (null becomes empty string for database)
            if ($value === null) {
                $value = '';
            }
            
            try {
                // Ensure we have a valid value (empty string is valid)
                $valueToSave = (string)$value;
                
                $result = setSetting($settingKey, $valueToSave);
                if ($result === true) {
                    $updated = true;
                } else {
                    $db = Database::getInstance();
                    $error = $db->getLastError();
                    $errorMsg = $error ? " - {$error}" : '';
                    error_log("Failed to save setting {$settingKey}: {$errorMsg}");
                    $errors[] = "Failed to save: {$settingKey}" . $errorMsg;
                }
            } catch (Exception $e) {
                error_log("Exception saving setting {$settingKey}: " . $e->getMessage());
                $errors[] = "Error saving {$settingKey}: " . $e->getMessage();
            }
        }
    }
    
    // Handle checkboxes that weren't submitted (unchecked)
    $checkboxSettings = ['invoice_show_logo', 'invoice_show_tax_id'];
    foreach ($checkboxSettings as $setting) {
        if (!isset($_POST['setting_' . $setting])) {
            try {
                $result = setSetting($setting, '0');
                if ($result) {
                    $updated = true;
                } else {
                    $errors[] = "Failed to save: {$setting}";
                }
            } catch (Exception $e) {
                $errors[] = "Error saving {$setting}: " . $e->getMessage();
            }
        }
    }
    
    // Handle logo upload
    if (isset($_FILES['invoice_logo']) && $_FILES['invoice_logo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = APP_PATH . '/assets/images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = pathinfo($_FILES['invoice_logo']['name'], PATHINFO_EXTENSION);
        $filename = 'invoice_logo_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        
        if (move_uploaded_file($_FILES['invoice_logo']['tmp_name'], $targetPath)) {
            setSetting('invoice_logo', 'assets/images/' . $filename);
            $updated = true;
        }
    }
    
    if ($updated && empty($errors)) {
        $_SESSION['settings_updated'] = true;
        header('Location: customize.php?success=1');
        exit;
    } else {
        $error = !empty($errors) ? implode('<br>', $errors) : 'Failed to update settings.';
    }
}

if (isset($_GET['success']) || isset($_SESSION['settings_updated'])) {
    $success = 'Settings updated successfully!';
    unset($_SESSION['settings_updated']);
}

$settings = [
    'invoice_template' => getSetting('invoice_template', 'modern'),
    'invoice_primary_color' => getSetting('invoice_primary_color', '#1e3a8a'),
    'invoice_logo' => getSetting('invoice_logo', ''),
    'invoice_header_text' => getSetting('invoice_header_text', ''),
    'invoice_footer_text' => getSetting('invoice_footer_text', 'Thank you for your business!'),
    'invoice_show_logo' => getSetting('invoice_show_logo', '1'),
    'invoice_show_tax_id' => getSetting('invoice_show_tax_id', '1'),
    'invoice_default_terms' => getSetting('invoice_default_terms', ''),
];

require_once APP_PATH . '/includes/header.php';
?>

<style>
.settings-container {
    display: flex;
    gap: 20px;
    min-height: calc(100vh - 200px);
}

.settings-sidebar {
    width: 300px;
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.settings-content {
    flex: 1;
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.settings-menu-item {
    padding: 15px;
    border-left: 4px solid transparent;
    cursor: pointer;
    transition: all 0.3s;
    margin-bottom: 5px;
    border-radius: 8px;
}

.settings-menu-item:hover {
    background: var(--light-blue);
}

.settings-menu-item.active {
    background: var(--light-blue);
    border-left-color: var(--primary-blue);
    font-weight: 600;
}

.settings-section {
    display: none;
}

.settings-section.active {
    display: block;
}

.template-preview {
    border: 3px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    margin-bottom: 20px;
}

.template-preview:hover {
    border-color: var(--primary-blue);
}

.template-preview.active {
    border-color: var(--primary-blue);
    background: var(--light-blue);
}

.color-picker-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
}

.logo-preview {
    max-width: 200px;
    max-height: 100px;
    margin-top: 10px;
    border: 1px solid #dee2e6;
    padding: 10px;
    border-radius: 8px;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Invoice Customization</h2>
    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= escapeHtml($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= escapeHtml($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="settings-container">
    <div class="settings-sidebar">
        <div class="settings-menu-item active" data-section="template">
            <div>Template</div>
        </div>
        <div class="settings-menu-item" data-section="colors">
            <div>Colors</div>
        </div>
        <div class="settings-menu-item" data-section="logo">
            <div>Logo</div>
        </div>
        <div class="settings-menu-item" data-section="header">
            <div>Header</div>
        </div>
        <div class="settings-menu-item" data-section="footer">
            <div>Footer</div>
        </div>
        <div class="settings-menu-item" data-section="fields">
            <div>Fields & Display</div>
        </div>
    </div>
    
    <div class="settings-content">
        <form method="POST" enctype="multipart/form-data">
            <!-- Template Section -->
            <div id="template" class="settings-section active">
                <h4 class="mb-4">Invoice Template</h4>
                <div class="row">
                    <div class="col-md-6">
                        <div class="template-preview <?= $settings['invoice_template'] == 'modern' ? 'active' : '' ?>" onclick="selectTemplate('modern')">
                            <i class="bi bi-file-earmark-text" style="font-size: 48px; color: var(--primary-blue);"></i>
                            <div class="mt-2"><strong>Modern</strong></div>
                            <input type="radio" name="setting_invoice_template" value="modern" <?= $settings['invoice_template'] == 'modern' ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="template-preview <?= $settings['invoice_template'] == 'classic' ? 'active' : '' ?>" onclick="selectTemplate('classic')">
                            <i class="bi bi-file-text" style="font-size: 48px; color: var(--primary-blue);"></i>
                            <div class="mt-2"><strong>Classic</strong></div>
                            <input type="radio" name="setting_invoice_template" value="classic" <?= $settings['invoice_template'] == 'classic' ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="template-preview <?= $settings['invoice_template'] == 'minimal' ? 'active' : '' ?>" onclick="selectTemplate('minimal')">
                            <i class="bi bi-file-earmark" style="font-size: 48px; color: var(--primary-blue);"></i>
                            <div class="mt-2"><strong>Minimal</strong></div>
                            <input type="radio" name="setting_invoice_template" value="minimal" <?= $settings['invoice_template'] == 'minimal' ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="template-preview <?= $settings['invoice_template'] == 'elegant' ? 'active' : '' ?>" onclick="selectTemplate('elegant')">
                            <i class="bi bi-file-earmark-check" style="font-size: 48px; color: var(--primary-blue);"></i>
                            <div class="mt-2"><strong>Elegant</strong></div>
                            <input type="radio" name="setting_invoice_template" value="elegant" <?= $settings['invoice_template'] == 'elegant' ? 'checked' : '' ?>>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Colors Section -->
            <div id="colors" class="settings-section">
                <h4 class="mb-4">Color Scheme</h4>
                <div class="mb-3">
                    <label class="form-label">Primary Color</label>
                    <div class="color-picker-wrapper">
                        <input type="color" name="setting_invoice_primary_color" class="form-control form-control-color" value="<?= escapeHtml($settings['invoice_primary_color']) ?>">
                        <input type="text" class="form-control" value="<?= escapeHtml($settings['invoice_primary_color']) ?>" readonly style="max-width: 150px;">
                    </div>
                    <small class="text-muted">This color will be used for headers, borders, and accents</small>
                </div>
            </div>
            
            <!-- Logo Section -->
            <div id="logo" class="settings-section">
                <h4 class="mb-4">Company Logo</h4>
                <div class="mb-3">
                    <label class="form-label">Upload Logo</label>
                    <input type="file" name="invoice_logo" class="form-control" accept="image/*">
                    <small class="text-muted">Recommended size: 200x100px, PNG or JPG format</small>
                </div>
                <?php 
                $logoPath = '';
                if ($settings['invoice_logo']) {
                    // Check if path is relative or absolute
                    $fullPath = APP_PATH . '/' . ltrim($settings['invoice_logo'], '/');
                    if (file_exists($fullPath)) {
                        $logoPath = BASE_URL . ltrim($settings['invoice_logo'], '/');
                    }
                }
                if ($logoPath): ?>
                    <div class="mb-3">
                        <label class="form-label">Current Logo</label>
                        <div>
                            <img src="<?= htmlspecialchars($logoPath) ?>" alt="Logo" class="logo-preview" onerror="this.style.display='none';">
                            <div class="mt-2">
                                <small class="text-muted">Logo file: <?= htmlspecialchars($settings['invoice_logo']) ?></small>
                            </div>
                        </div>
                    </div>
                <?php elseif ($settings['invoice_logo']): ?>
                    <div class="alert alert-warning">
                        <small>Logo path is set but file not found: <?= htmlspecialchars($settings['invoice_logo']) ?></small>
                    </div>
                <?php endif; ?>
                <div class="mb-3 mt-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="setting_invoice_show_logo" value="1" id="showLogo" <?= $settings['invoice_show_logo'] == '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="showLogo">Show logo on invoices</label>
                    </div>
                </div>
            </div>
            
            <!-- Header Section -->
            <div id="header" class="settings-section">
                <h4 class="mb-4">Header Text</h4>
                <div class="mb-3">
                    <label class="form-label">Custom Header Text</label>
                    <textarea name="setting_invoice_header_text" class="form-control" rows="3" placeholder="Optional custom text to display at the top of invoices..."><?= escapeHtml($settings['invoice_header_text']) ?></textarea>
                    <small class="text-muted">This text will appear at the top of all invoices</small>
                </div>
            </div>
            
            <!-- Footer Section -->
            <div id="footer" class="settings-section">
                <h4 class="mb-4">Footer Text</h4>
                <div class="mb-3">
                    <label class="form-label">Footer Text</label>
                    <textarea name="setting_invoice_footer_text" class="form-control" rows="3"><?= escapeHtml($settings['invoice_footer_text']) ?></textarea>
                    <small class="text-muted">This text will appear at the bottom of all invoices</small>
                </div>
            </div>
            
            <!-- Fields & Display Section -->
            <div id="fields" class="settings-section">
                <h4 class="mb-4">Fields & Display Options</h4>
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="setting_invoice_show_tax_id" value="1" id="showTaxId" <?= $settings['invoice_show_tax_id'] == '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="showTaxId">Show Tax ID on invoices</label>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Default Terms & Conditions</label>
                    <textarea name="setting_invoice_default_terms" class="form-control" rows="8" placeholder="Payment terms, delivery terms, warranty information, etc..."><?= escapeHtml($settings['invoice_default_terms']) ?></textarea>
                    <small class="text-muted">These terms will be pre-filled when creating new invoices. Example: "Goods purchased remain the property of [Company Name] until fully paid for. Returned goods to be in their original state. No warranty for computer/printer ports damaged by lightning or power surges."</small>
                </div>
            </div>
            
            <div class="mt-4 pt-3 border-top">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-save"></i> Save Settings
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function selectTemplate(template) {
    document.querySelectorAll('.template-preview').forEach(el => el.classList.remove('active'));
    event.currentTarget.classList.add('active');
    event.currentTarget.querySelector('input[type="radio"]').checked = true;
}

document.querySelectorAll('.settings-menu-item').forEach(item => {
    item.addEventListener('click', function() {
        const section = this.dataset.section;
        
        // Update active menu item
        document.querySelectorAll('.settings-menu-item').forEach(i => i.classList.remove('active'));
        this.classList.add('active');
        
        // Update active section
        document.querySelectorAll('.settings-section').forEach(s => s.classList.remove('active'));
        document.getElementById(section).classList.add('active');
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

