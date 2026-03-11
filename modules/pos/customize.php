<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
// This page matches sidebar "POS Customization" menu item
$auth->requirePermission('pos.customize');

$pageTitle = 'POS Customization';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updated = false;
    $errors = [];
    
    // Handle receipt logo upload - store in uploads directory to prevent deletion on deployment
    if (isset($_FILES['receipt_logo']) && $_FILES['receipt_logo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = APP_PATH . '/uploads/receipt_logos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = pathinfo($_FILES['receipt_logo']['name'], PATHINFO_EXTENSION);
        $filename = 'receipt_logo_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        
        if (move_uploaded_file($_FILES['receipt_logo']['tmp_name'], $targetPath)) {
            $logoPath = 'uploads/receipt_logos/' . $filename;
            // Verify file was actually saved
            if (file_exists($targetPath)) {
                // Save to database
                $saveResult = setSetting('pos_receipt_logo', $logoPath);
                if ($saveResult) {
                    // Verify it was saved correctly by reading it back
                    $savedPath = getSetting('pos_receipt_logo', '');
                    if ($savedPath === $logoPath) {
                        $updated = true;
                    } else {
                        $errors[] = "Logo uploaded but failed to save setting to database. File: {$logoPath}, Saved: {$savedPath}";
                        error_log("Receipt logo setting mismatch - Expected: {$logoPath}, Got: {$savedPath}");
                    }
                } else {
                    $errors[] = "Logo file uploaded but failed to save to database. Please try again.";
                    error_log("Failed to save pos_receipt_logo setting after file upload");
                }
            } else {
                $errors[] = "Failed to upload receipt logo. File was not saved to disk.";
            }
        } else {
            $errors[] = "Failed to upload receipt logo. Please check directory permissions.";
        }
    } elseif (isset($_FILES['receipt_logo']) && $_FILES['receipt_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        // File upload error occurred (but not "no file" error)
        $errorMsg = "Upload error code: " . $_FILES['receipt_logo']['error'];
        switch ($_FILES['receipt_logo']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = "File is too large. Maximum size allowed: " . ini_get('upload_max_filesize');
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMsg = "File upload was interrupted. Please try again.";
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $errorMsg = "Temporary folder is missing. Please contact system administrator.";
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $errorMsg = "Failed to write file to disk. Please check directory permissions.";
                break;
            case UPLOAD_ERR_EXTENSION:
                $errorMsg = "File upload was stopped by extension. Please contact system administrator.";
                break;
        }
        $errors[] = "Receipt logo upload failed: " . $errorMsg;
    }
    
    // Handle checkboxes - if not set, set to '0'
    if (!isset($_POST['setting_pos_receipt_summary'])) {
        $_POST['setting_pos_receipt_summary'] = '0';
    }
    if (!isset($_POST['setting_pos_dual_display'])) {
        $_POST['setting_pos_dual_display'] = '0';
    }
    if (!isset($_POST['setting_pos_auto_print'])) {
        $_POST['setting_pos_auto_print'] = '0';
    }
    
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'setting_') === 0) {
            $settingKey = str_replace('setting_', '', $key);
            // Handle array values
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            
            try {
                $result = setSetting($settingKey, $value);
                if ($result) {
                    $updated = true;
                } else {
                    $errors[] = "Failed to save: {$settingKey}";
                }
            } catch (Exception $e) {
                $errors[] = "Error saving {$settingKey}: " . $e->getMessage();
            }
        }
    }
    
    if ($updated && empty($errors)) {
        $_SESSION['settings_updated'] = true;
        header('Location: customize.php?success=1');
        exit;
    } else {
        $error = !empty($errors) ? implode('<br>', $errors) : 'Failed to update settings. Please try again.';
    }
}

if (isset($_GET['success']) || isset($_SESSION['settings_updated'])) {
    $success = 'Settings updated successfully!';
    unset($_SESSION['settings_updated']);
}

$settings = [
    'pos_home_layout' => getSetting('pos_home_layout', 'grid'),
    'pos_cart_layout' => getSetting('pos_cart_layout', 'increase_qty'),
    'pos_language' => getSetting('pos_language', 'english'),
    'pos_transaction_days' => getSetting('pos_transaction_days', '30'),
    'pos_receipt_summary' => getSetting('pos_receipt_summary', '0'),
    'pos_printer_setup' => getSetting('pos_printer_setup', ''),
    'pos_dual_display' => getSetting('pos_dual_display', '0'),
    'pos_receipt_logo' => getSetting('pos_receipt_logo', ''),
    'pos_receipt_header' => getSetting('pos_receipt_header', 'Thank you for shopping with us!'),
    'pos_receipt_footer' => getSetting('pos_receipt_footer', 'Visit us again!'),
    'pos_default_tax_rate' => getSetting('pos_default_tax_rate', '15'),
    'pos_auto_print' => getSetting('pos_auto_print', '0'),
];

require_once APP_PATH . '/includes/header.php';
?>

<style>
.settings-container {
    display: flex;
    gap: 20px;
    height: calc(100vh - 80px);
}

.settings-sidebar {
    width: 300px;
    background: white;
    border-radius: 12px;
    padding: 20px;
}

.settings-content {
    flex: 1;
    background: white;
    border-radius: 12px;
    padding: 30px;
    overflow-y: auto;
}

.settings-menu-item {
    padding: 15px;
    border-left: 4px solid transparent;
    cursor: pointer;
    transition: all 0.3s;
    margin-bottom: 5px;
    border-radius: 8px;
    user-select: none;
    -webkit-user-select: none;
    pointer-events: auto !important;
}

.settings-menu-item:hover {
    background: var(--light-blue);
}

.settings-menu-item.active {
    background: var(--light-blue);
    border-left-color: var(--primary-blue);
    font-weight: 600;
}

.settings-menu-item.active .sub-item {
    display: block;
    margin-top: 10px;
    padding-left: 20px;
    font-size: 13px;
    color: var(--text-muted);
    font-weight: normal;
}

.sub-item {
    display: none;
}

.layout-option {
    border: 3px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    margin-bottom: 20px;
}

.layout-option:hover {
    border-color: var(--primary-blue);
}

.layout-option.active {
    border-color: var(--primary-blue);
    background: var(--light-blue);
}

.layout-preview {
    width: 200px;
    height: 150px;
    background: #f3f4f6;
    border-radius: 8px;
    margin: 0 auto 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 48px;
    color: var(--text-muted);
}

/* ========== MOBILE RESPONSIVE STYLES ========== */

/* Tablet and below (max-width: 1024px) */
@media (max-width: 1024px) {
    .settings-container {
        flex-direction: column;
        height: auto;
        min-height: calc(100vh - 80px);
    }
    
    .settings-sidebar {
        width: 100%;
        order: 1;
    }
    
    .settings-content {
        order: 2;
        min-height: 50vh;
    }
}

/* Mobile (max-width: 768px) */
@media (max-width: 768px) {
    .settings-container {
        flex-direction: column;
        height: auto;
        min-height: calc(100vh - 60px);
        gap: 15px;
        padding: 0;
    }
    
    .settings-sidebar {
        width: 100%;
        border-radius: 0;
        padding: 15px;
        order: 1;
    }
    
    .settings-content {
        border-radius: 0;
        padding: 20px 15px;
        order: 2;
    }
    
    .settings-menu-item {
        padding: 12px;
        font-size: 14px;
    }
    
    .layout-option {
        padding: 15px;
    }
    
    .layout-preview {
        width: 150px;
        height: 120px;
        font-size: 36px;
    }
    
    .d-flex.justify-content-between {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start !important;
    }
    
    .d-flex.justify-content-between .btn {
        width: 100%;
    }
}

/* Small Mobile (max-width: 480px) */
@media (max-width: 480px) {
    .settings-sidebar {
        padding: 10px;
    }
    
    .settings-content {
        padding: 15px 10px;
    }
    
    .settings-menu-item {
        padding: 10px;
        font-size: 13px;
    }
    
    .layout-preview {
        width: 120px;
        height: 100px;
        font-size: 32px;
    }
    
    h2 {
        font-size: 20px;
    }
}

.cart-layout-card {
    border: 2px solid #e5e7eb;
    transition: all 0.3s ease;
    user-select: none;
}

.cart-layout-card:hover {
    transform: translateY(-2px);
}

.cart-layout-card.selected {
    border-color: var(--primary-blue);
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.2);
    background-color: #f8f9ff;
}

.cart-layout-card input[type="radio"] {
    cursor: pointer;
    margin-top: 10px;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>POS Customization</h2>
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
        <div class="settings-menu-item active" data-section="home-layout">
            <div>Home Screen Layout</div>
            <div class="sub-item"><?= ucfirst($settings['pos_home_layout']) ?></div>
        </div>
        <div class="settings-menu-item" data-section="cart-layout">
            <div>Item Cart Layout</div>
            <div class="sub-item"><?= $settings['pos_cart_layout'] == 'increase_qty' ? 'Increase Qty' : 'Multiple Line' ?></div>
        </div>
        <div class="settings-menu-item" data-section="language">
            <div>Language</div>
            <div class="sub-item"><?= ucfirst($settings['pos_language']) ?></div>
        </div>
        <div class="settings-menu-item" data-section="transaction-days">
            <div>Transaction Data Available Days</div>
            <div class="sub-item"><?= $settings['pos_transaction_days'] ?></div>
        </div>
        <div class="settings-menu-item" data-section="receipt-summary">
            <div>Show Receipt Summary Page</div>
            <div class="sub-item"><?= $settings['pos_receipt_summary'] == '1' ? 'On' : 'Off' ?></div>
        </div>
        <div class="settings-menu-item" data-section="receipt-config">
            <div>Receipt Configuration</div>
        </div>
        <div class="settings-menu-item" data-section="printer">
            <div>Printer Setup</div>
        </div>
        <div class="settings-menu-item" data-section="web-app">
            <div>Install Web App</div>
        </div>
        <div class="settings-menu-item" data-section="dual-display">
            <div>Dual Display</div>
        </div>
    </div>
    
    <div class="settings-content">
        <!-- Home Screen Layout -->
        <div id="home-layout" class="settings-section">
            <h4 class="mb-4">Home Screen Layout</h4>
            <form method="POST" id="homeLayoutForm">
                <div class="row">
                    <div class="col-md-6">
                        <div class="layout-option <?= $settings['pos_home_layout'] == 'grid' ? 'active' : '' ?>" onclick="selectLayout('grid', this)">
                            <div class="layout-preview">
                                <i class="bi bi-grid-3x3-gap"></i>
                            </div>
                            <input type="radio" name="setting_pos_home_layout" value="grid" <?= $settings['pos_home_layout'] == 'grid' ? 'checked' : '' ?>>
                            <div class="mt-2"><strong>Grid</strong></div>
                            <div class="text-muted small mt-1">Standard grid, 150px min width</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="layout-option <?= $settings['pos_home_layout'] == 'simple-grid' ? 'active' : '' ?>" onclick="selectLayout('simple-grid', this)">
                            <div class="layout-preview">
                                <i class="bi bi-grid"></i>
                            </div>
                            <input type="radio" name="setting_pos_home_layout" value="simple-grid" <?= $settings['pos_home_layout'] == 'simple-grid' ? 'checked' : '' ?>>
                            <div class="mt-2"><strong>Simple Grid</strong></div>
                            <div class="text-muted small mt-1">Compact cards, 180px min width</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="layout-option <?= $settings['pos_home_layout'] == 'list' ? 'active' : '' ?>" onclick="selectLayout('list', this)">
                            <div class="layout-preview">
                                <i class="bi bi-list-ul"></i>
                            </div>
                            <input type="radio" name="setting_pos_home_layout" value="list" <?= $settings['pos_home_layout'] == 'list' ? 'checked' : '' ?>>
                            <div class="mt-2"><strong>List</strong></div>
                            <div class="text-muted small mt-1">Horizontal list view</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="layout-option <?= $settings['pos_home_layout'] == 'retail' ? 'active' : '' ?>" onclick="selectLayout('retail', this)">
                            <div class="layout-preview">
                                <i class="bi bi-shop"></i>
                            </div>
                            <input type="radio" name="setting_pos_home_layout" value="retail" <?= $settings['pos_home_layout'] == 'retail' ? 'checked' : '' ?>>
                            <div class="mt-2"><strong>Retail</strong></div>
                            <div class="text-muted small mt-1">Large cards with shadows, 240px min width</div>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-4">Save</button>
            </form>
        </div>
        
        <!-- Cart Layout -->
        <div id="cart-layout" class="settings-section" style="display: none;">
            <h4 class="mb-4">Item Cart Layout</h4>
            <form method="POST">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card cart-layout-card <?= $settings['pos_cart_layout'] == 'increase_qty' ? 'selected' : '' ?>" 
                             data-value="increase_qty">
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <i class="bi bi-cart-check" style="font-size: 64px; color: var(--primary-blue);"></i>
                                </div>
                                <input type="radio" name="setting_pos_cart_layout" value="increase_qty" id="cart_layout_increase_qty" <?= $settings['pos_cart_layout'] == 'increase_qty' ? 'checked' : '' ?>>
                                <div class="mt-2"><strong>Increase Qty</strong></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card cart-layout-card <?= $settings['pos_cart_layout'] == 'multiple_line' ? 'selected' : '' ?>" 
                             data-value="multiple_line">
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <i class="bi bi-cart-plus" style="font-size: 64px; color: var(--primary-blue);"></i>
                                </div>
                                <input type="radio" name="setting_pos_cart_layout" value="multiple_line" id="cart_layout_multiple_line" <?= $settings['pos_cart_layout'] == 'multiple_line' ? 'checked' : '' ?>>
                                <div class="mt-2"><strong>Multiple Line</strong></div>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-muted">When selling THE SAME ITEM, you can add to the existing line (increase quantity) or add a new line.</p>
                <button type="submit" class="btn btn-primary mt-4">Save</button>
            </form>
        </div>
        
        <!-- Language -->
        <div id="language" class="settings-section" style="display: none;">
            <h4 class="mb-4">Language</h4>
            <form method="POST">
                <div class="mb-3">
                    <select name="setting_pos_language" class="form-select form-select-lg">
                        <option value="english" <?= $settings['pos_language'] == 'english' ? 'selected' : '' ?>>English</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
        
        <!-- Transaction Days -->
        <div id="transaction-days" class="settings-section" style="display: none;">
            <h4 class="mb-4">Transaction Data Available Days</h4>
            <form method="POST">
                <div class="mb-3">
                    <input type="number" name="setting_pos_transaction_days" value="<?= escapeHtml($settings['pos_transaction_days']) ?>" class="form-control form-control-lg" min="1" max="365">
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
        
        <!-- Receipt Summary -->
        <div id="receipt-summary" class="settings-section" style="display: none;">
            <h4 class="mb-4">Show Receipt Summary Page</h4>
            <form method="POST">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="setting_pos_receipt_summary" value="1" id="receiptSummary" <?= $settings['pos_receipt_summary'] == '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="receiptSummary">Enable Receipt Summary Page</label>
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
        
        <!-- Receipt Configuration -->
        <div id="receipt-config" class="settings-section" style="display: none;">
            <h4 class="mb-4">Receipt Configuration</h4>
            <form method="POST" enctype="multipart/form-data">
                <!-- Receipt Logo -->
                <div class="mb-4">
                    <label class="form-label fw-bold">Receipt Logo</label>
                    <?php 
                    $receiptLogoPath = $settings['pos_receipt_logo'];
                    // If no logo setting, try to find most recent receipt_logo file in uploads directory
                    // Also check old assets/images location for backward compatibility
                    if (empty($receiptLogoPath)) {
                        // Check new location first
                        $logoDir = APP_PATH . '/uploads/receipt_logos/';
                        $logoFiles = array_merge(
                            glob($logoDir . 'receipt_logo_*.png'),
                            glob($logoDir . 'receipt_logo_*.jpg'),
                            glob($logoDir . 'receipt_logo_*.jpeg')
                        );
                        // Also check old location for backward compatibility
                        $oldLogoDir = APP_PATH . '/assets/images/';
                        $logoFiles = array_merge($logoFiles,
                            glob($oldLogoDir . 'receipt_logo_*.png'),
                            glob($oldLogoDir . 'receipt_logo_*.jpg'),
                            glob($oldLogoDir . 'receipt_logo_*.jpeg'),
                            glob($oldLogoDir . 'invoice_logo_*.png'),
                            glob($oldLogoDir . 'invoice_logo_*.jpg'),
                            glob($oldLogoDir . 'invoice_logo_*.jpeg')
                        );
                        if (!empty($logoFiles)) {
                            usort($logoFiles, function($a, $b) {
                                return filemtime($b) - filemtime($a);
                            });
                            $mostRecent = $logoFiles[0];
                            // Determine path based on location
                            if (strpos($mostRecent, '/uploads/receipt_logos/') !== false) {
                                $receiptLogoPath = 'uploads/receipt_logos/' . basename($mostRecent);
                            } else {
                                $receiptLogoPath = 'assets/images/' . basename($mostRecent);
                            }
                        }
                    }
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
                            <div class="mt-2">
                                <small class="text-muted">Logo file: <?= htmlspecialchars($receiptLogoPath) ?></small>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-2">No logo uploaded</p>
                    <?php endif; ?>
                    <input type="file" name="receipt_logo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                    <small class="text-muted">Upload a logo to display on receipts (JPEG, PNG, GIF, or WebP)</small>
                </div>
                
                <!-- Receipt Header Text -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Receipt Header Text</label>
                    <input type="text" name="setting_pos_receipt_header" value="<?= escapeHtml($settings['pos_receipt_header']) ?>" class="form-control" placeholder="Thank you for shopping with us!">
                </div>
                
                <!-- Receipt Footer Text -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Receipt Footer Text</label>
                    <input type="text" name="setting_pos_receipt_footer" value="<?= escapeHtml($settings['pos_receipt_footer']) ?>" class="form-control" placeholder="Visit us again!">
                </div>
                
                <!-- Default Tax Rate -->
                <div class="mb-3">
                    <label class="form-label fw-bold">Default Tax Rate (%)</label>
                    <input type="number" name="setting_pos_default_tax_rate" value="<?= escapeHtml($settings['pos_default_tax_rate']) ?>" class="form-control" step="0.01" min="0" max="100" placeholder="15">
                </div>
                
                <!-- Auto Print Receipt -->
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="setting_pos_auto_print" value="1" id="autoPrint" <?= $settings['pos_auto_print'] == '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="autoPrint">Auto Print Receipt</label>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
            </form>
        </div>
        
        <!-- Printer Setup -->
        <div id="printer" class="settings-section" style="display: none;">
            <h4 class="mb-4">Printer Setup</h4>
            <p class="text-muted mb-4">Please follow the below instructions.</p>
            
            <div class="mb-4">
                <ol class="mb-4" style="line-height: 2;">
                    <li>Download the printer setup 
                        <a href="<?= BASE_URL ?>/printersetupwifi_v2.0.zip" class="btn btn-sm btn-primary ms-2" download>
                            <i class="bi bi-download"></i> Download
                        </a>
                    </li>
                    <li>Unzip the setup zip file.</li>
                    <li>Click on the setup.exe and install the software.</li>
                    <li>Run the Thermal printer setup shortcut on the desktop.</li>
                    <li>Refresh the device list.</li>
                    <li>Select the correct device.</li>
                </ol>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5>Configured Printers</h5>
                <button type="button" class="btn btn-primary" onclick="openAddPrinterModal()">
                    <i class="bi bi-plus-circle"></i> Add New
                </button>
            </div>
            
            <div id="printers-list">
                <!-- Printers will be loaded here via AJAX -->
                <div class="text-center text-muted py-4">
                    <i class="bi bi-printer" style="font-size: 48px; opacity: 0.3;"></i>
                    <p class="mt-2">You do not have printers yet.</p>
                </div>
            </div>
        </div>
        
        <!-- Web App -->
        <div id="web-app" class="settings-section" style="display: none;">
            <h4 class="mb-4">Install Web App</h4>
            <p class="text-muted">Install this application as a web app on your device for easier access.</p>
            <button class="btn btn-primary" onclick="installWebApp()">Install Web App</button>
        </div>
        
        <!-- Dual Display -->
        <div id="dual-display" class="settings-section" style="display: none;">
            <h4 class="mb-4">Dual Display</h4>
            <p class="text-muted">To customize the second display with images, go to the software setup in the back-office web portal.</p>
            <form method="POST">
                <input type="hidden" name="setting_pos_dual_display" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="setting_pos_dual_display" value="1" id="dualDisplay" <?= $settings['pos_dual_display'] == '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="dualDisplay">Enable Dual Display</label>
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
    </div>
</div>

<!-- Add/Edit Printer Modal -->
<div class="modal fade" id="addPrinterModal" tabindex="-1" aria-labelledby="addPrinterModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addPrinterModalLabel">Add Printer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addPrinterForm">
                    <input type="hidden" id="printerId" name="printer_id" value="">
                    <div class="mb-3">
                        <label class="form-label">Printer name</label>
                        <input type="text" class="form-control" id="printerName" name="printer_name" placeholder="Printer name" required>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0">Print receipts and bills</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="printReceiptsBills" name="print_receipts_bills" checked>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Connection mode</label>
                        <select class="form-select" id="connectionMode" name="connection_mode" required>
                            <option value="USB" selected>USB</option>
                            <option value="Network">Network</option>
                            <option value="Bluetooth">Bluetooth</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0">Refresh the device list.</label>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="refreshDeviceList()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                            <i class="bi bi-info-circle text-muted ms-2" data-bs-toggle="tooltip" title="Make sure the Thermal printer setup is running on your desktop"></i>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Device list</label>
                        <select class="form-select" id="deviceList" name="device_id" required>
                            <option value="">-- Select a device --</option>
                        </select>
                        <small class="text-muted">If no devices appear, make sure the Thermal printer setup is running.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select paper size</label>
                        <select class="form-select" id="paperSize" name="paper_size" required>
                            <option value="80mm" selected>80mm</option>
                            <option value="58mm">58mm</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0">Status</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="printerStatus" name="status" checked>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0">Connect the cash drawer to the printer</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="cashDrawerConnected" name="cash_drawer_connected">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="savePrinter()">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    function initSettingsMenu() {
        try {
            var menuItems = document.querySelectorAll('.settings-menu-item');
            
            if (menuItems.length === 0) {
                console.warn('No menu items found');
                return;
            }
            
            for (var i = 0; i < menuItems.length; i++) {
                var item = menuItems[i];
                
                // Make sure it's clickable
                item.style.cursor = 'pointer';
                item.style.pointerEvents = 'auto';
                
                // Remove any existing listeners by cloning
                var newItem = item.cloneNode(true);
                item.parentNode.replaceChild(newItem, item);
                item = newItem;
                
                // Add click handler
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    var section = this.getAttribute('data-section');
                    if (!section) {
                        console.error('No data-section attribute found');
                        return;
                    }
                    
                    // Remove active class from all items
                    var allItems = document.querySelectorAll('.settings-menu-item');
                    for (var j = 0; j < allItems.length; j++) {
                        allItems[j].classList.remove('active');
                    }
                    
                    // Add active class to clicked item
                    this.classList.add('active');
                    
                    // Hide all sections
                    var allSections = document.querySelectorAll('.settings-section');
                    for (var k = 0; k < allSections.length; k++) {
                        allSections[k].style.display = 'none';
                    }
                    
                    // Show target section
                    var targetSection = document.getElementById(section);
                    if (targetSection) {
                        targetSection.style.display = 'block';
                    } else {
                        console.error('Section not found:', section);
                    }
                });
            }
        } catch (error) {
            console.error('Error initializing settings menu:', error);
        }
    }
    
    // Try multiple initialization methods
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSettingsMenu);
    } else {
        // DOM already loaded
        initSettingsMenu();
    }
    
    // Also try after a short delay as fallback
    setTimeout(initSettingsMenu, 100);
})();

function selectLayout(layout, element) {
    document.querySelectorAll('.layout-option').forEach(opt => opt.classList.remove('active'));
    if (element) {
        element.classList.add('active');
    }
    const radioInput = document.querySelector(`input[value="${layout}"]`);
    if (radioInput) {
        radioInput.checked = true;
    }
}

function installWebApp() {
    if ('serviceWorker' in navigator) {
        Swal.fire('Web App', 'Web app installation feature to be implemented', 'info');
    } else {
        Swal.fire('Not Supported', 'Web app installation is not supported in this browser', 'warning');
    }
}

// Make cart layout cards clickable
document.addEventListener('DOMContentLoaded', function() {
    const cartLayoutCards = document.querySelectorAll('.cart-layout-card');
    
    cartLayoutCards.forEach(card => {
        card.addEventListener('click', function(e) {
            // Don't trigger if clicking directly on the radio button
            if (e.target.type === 'radio') {
                return;
            }
            
            const value = this.dataset.value;
            const radio = document.getElementById('cart_layout_' + value);
            
            if (radio) {
                radio.checked = true;
                
                // Update visual state
                cartLayoutCards.forEach(c => {
                    c.classList.remove('selected');
                });
                
                this.classList.add('selected');
            }
        });
        
        // Hover effects are handled by CSS
    });
    
    // Load printers when printer section is shown
    const printerMenuItem = document.querySelector('[data-section="printer"]');
    if (printerMenuItem) {
        printerMenuItem.addEventListener('click', function() {
            setTimeout(function() {
                loadPrinters();
            }, 100);
        });
    }
    
    // Initialize settings menu on page load
    const activeMenuItem = document.querySelector('.settings-menu-item.active');
    if (activeMenuItem) {
        const section = activeMenuItem.dataset.section;
        if (section) {
            const targetSection = document.getElementById(section);
            if (targetSection) {
                targetSection.style.display = 'block';
            }
        }
    }
});

// Printer Management Functions
let currentBranchId = <?= json_encode($auth->getUser()['branch_id'] ?? 0) ?>;

let currentEditingPrinterId = null;

function openAddPrinterModal() {
    currentEditingPrinterId = null;
    document.getElementById('addPrinterModalLabel').textContent = 'Add Printer';
    document.getElementById('addPrinterForm').reset();
    document.getElementById('printerId').value = '';
    document.getElementById('deviceList').innerHTML = '<option value="">-- Select a device --</option>';
    refreshDeviceList();
    const modal = new bootstrap.Modal(document.getElementById('addPrinterModal'));
    modal.show();
}

function editPrinter(printerId) {
    currentEditingPrinterId = printerId;
    
    // Fetch printer details
    fetch('<?= BASE_URL ?>/ajax/get_printer.php?printer_id=' + printerId, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.printer) {
            const printer = data.printer;
            
            // Update modal title
            document.getElementById('addPrinterModalLabel').textContent = 'Update printer';
            
            // Fill form fields
            document.getElementById('printerId').value = printer.id;
            document.getElementById('printerName').value = printer.printer_name;
            document.getElementById('connectionMode').value = printer.connection_mode;
            document.getElementById('paperSize').value = printer.paper_size;
            document.getElementById('printReceiptsBills').checked = printer.print_receipts == 1 || printer.print_bills == 1;
            document.getElementById('printerStatus').checked = printer.status === 'active';
            document.getElementById('cashDrawerConnected').checked = printer.cash_drawer_connected == 1;
            
            // Set device list
            document.getElementById('deviceList').innerHTML = `<option value="${printer.device_id}" selected>${printer.device_id}</option>`;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('addPrinterModal'));
            modal.show();
        } else {
            Swal.fire('Error', data.message || 'Failed to load printer details', 'error');
        }
    })
    .catch(error => {
        console.error('Error loading printer:', error);
        Swal.fire('Error', 'An error occurred while loading printer details', 'error');
    });
}

function refreshDeviceList() {
    const deviceList = document.getElementById('deviceList');
    deviceList.innerHTML = '<option value="">Loading devices...</option>';
    
    // Try to detect printers from localhost service (Sales Intellect style)
    // The ESC/POS Thermal Printer Partner service runs on localhost:8080 (or similar)
    const servicePorts = [8080, 3000, 5000, 9000];
    let devicesFound = false;
    
    // Try multiple ports
    Promise.all(servicePorts.map(port => {
        return fetch(`http://localhost:${port}/api/printers`, {
            method: 'GET',
            mode: 'cors',
            headers: {
                'Content-Type': 'application/json'
            }
        }).then(response => {
            if (response.ok) {
                return response.json();
            }
            throw new Error('Service not available');
        }).then(data => {
            if (data && data.printers && data.printers.length > 0) {
                devicesFound = true;
                deviceList.innerHTML = '<option value="">-- Select a device --</option>';
                data.printers.forEach(printer => {
                    const option = document.createElement('option');
                    option.value = printer.id || printer.name;
                    option.textContent = printer.name || printer.id;
                    option.dataset.connection = printer.connection || 'USB';
                    deviceList.appendChild(option);
                });
            }
        }).catch(() => {
            // Service not available on this port, continue
        });
    })).then(() => {
        if (!devicesFound) {
            // Fallback: Use AJAX to get printers from server (which may query localhost service)
            fetch('<?= BASE_URL ?>/ajax/get_printers.php', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.printers && data.printers.length > 0) {
                    deviceList.innerHTML = '<option value="">-- Select a device --</option>';
                    data.printers.forEach(printer => {
                        const option = document.createElement('option');
                        option.value = printer.id || printer.name;
                        option.textContent = printer.name || printer.id;
                        deviceList.appendChild(option);
                    });
                } else {
                    deviceList.innerHTML = '<option value="">No devices found. Make sure Thermal printer setup is running.</option>';
                }
            })
            .catch(error => {
                console.error('Error fetching printers:', error);
                deviceList.innerHTML = '<option value="">Error loading devices. Make sure Thermal printer setup is running.</option>';
            });
        }
    });
}

function loadPrinters() {
    fetch('<?= BASE_URL ?>/ajax/get_saved_printers.php', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        const printersList = document.getElementById('printers-list');
        
        if (data.success && data.printers && data.printers.length > 0) {
            // Create table structure
            printersList.innerHTML = `
                <table class="table table-hover">
                    <thead style="background-color: #f8f9fa;">
                        <tr>
                            <th>Name</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="printers-table-body">
                    </tbody>
                </table>
            `;
            
            const tbody = document.getElementById('printers-table-body');
            data.printers.forEach(printer => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${escapeHtml(printer.printer_name)}</td>
                    <td>
                        <button class="btn btn-sm btn-link text-primary p-0 me-2" onclick="editPrinter(${printer.id})" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-link text-danger p-0" onclick="deletePrinter(${printer.id})" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(row);
            });
        } else {
            printersList.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-printer" style="font-size: 48px; opacity: 0.3;"></i>
                    <p class="mt-2">You do not have printers yet.</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error loading printers:', error);
    });
}

function savePrinter() {
    const form = document.getElementById('addPrinterForm');
    const formData = new FormData(form);
    
    // Add branch_id
    formData.append('branch_id', currentBranchId);
    
    // Get checkbox values
    formData.append('print_receipts', document.getElementById('printReceiptsBills').checked ? '1' : '0');
    formData.append('print_bills', document.getElementById('printReceiptsBills').checked ? '1' : '0');
    formData.append('status', document.getElementById('printerStatus').checked ? 'active' : 'inactive');
    formData.append('cash_drawer_connected', document.getElementById('cashDrawerConnected').checked ? '1' : '0');
    
    // Add printer_id if editing
    const printerId = document.getElementById('printerId').value;
    if (printerId) {
        formData.append('printer_id', printerId);
    }
    
    fetch('<?= BASE_URL ?>/ajax/save_printer.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const message = printerId ? 'Printer updated successfully!' : 'Printer saved successfully!';
            Swal.fire('Success', message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('addPrinterModal')).hide();
            loadPrinters();
        } else {
            Swal.fire('Error', data.message || 'Failed to save printer', 'error');
        }
    })
    .catch(error => {
        console.error('Error saving printer:', error);
        Swal.fire('Error', 'An error occurred while saving the printer', 'error');
    });
}

function deletePrinter(printerId) {
    Swal.fire({
        title: 'Delete printer',
        text: 'Are you sure you want to delete this printer ?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'DELETE',
        cancelButtonText: 'CANCEL'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= BASE_URL ?>/ajax/delete_printer.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ printer_id: printerId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deleted!', 'Printer has been deleted.', 'success');
                    loadPrinters();
                } else {
                    Swal.fire('Error', data.message || 'Failed to delete printer', 'error');
                }
            })
            .catch(error => {
                console.error('Error deleting printer:', error);
                Swal.fire('Error', 'An error occurred while deleting the printer', 'error');
            });
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

