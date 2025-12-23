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
                $updated = true;
            } else {
                $errors[] = "Failed to upload receipt logo.";
            }
        } else {
            $errors[] = "Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.";
        }
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
                        <div class="layout-option <?= $settings['pos_home_layout'] == 'grid' ? 'active' : '' ?>" onclick="selectLayout('grid')">
                            <div class="layout-preview">
                                <i class="bi bi-grid-3x3-gap"></i>
                            </div>
                            <input type="radio" name="setting_pos_home_layout" value="grid" <?= $settings['pos_home_layout'] == 'grid' ? 'checked' : '' ?>>
                            <div class="mt-2"><strong>Grid</strong></div>
                            <div class="text-muted small mt-1">Standard grid, 150px min width</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="layout-option <?= $settings['pos_home_layout'] == 'simple-grid' ? 'active' : '' ?>" onclick="selectLayout('simple-grid')">
                            <div class="layout-preview">
                                <i class="bi bi-grid"></i>
                            </div>
                            <input type="radio" name="setting_pos_home_layout" value="simple-grid" <?= $settings['pos_home_layout'] == 'simple-grid' ? 'checked' : '' ?>>
                            <div class="mt-2"><strong>Simple Grid</strong></div>
                            <div class="text-muted small mt-1">Compact cards, 180px min width</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="layout-option <?= $settings['pos_home_layout'] == 'list' ? 'active' : '' ?>" onclick="selectLayout('list')">
                            <div class="layout-preview">
                                <i class="bi bi-list-ul"></i>
                            </div>
                            <input type="radio" name="setting_pos_home_layout" value="list" <?= $settings['pos_home_layout'] == 'list' ? 'checked' : '' ?>>
                            <div class="mt-2"><strong>List</strong></div>
                            <div class="text-muted small mt-1">Horizontal list view</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="layout-option <?= $settings['pos_home_layout'] == 'retail' ? 'active' : '' ?>" onclick="selectLayout('retail')">
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
            <form method="POST">
                <div class="mb-3">
                    <label>Printer Name</label>
                    <input type="text" name="setting_pos_printer_setup" value="<?= escapeHtml($settings['pos_printer_setup']) ?>" class="form-control">
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
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

<script>
document.querySelectorAll('.settings-menu-item').forEach(item => {
    item.addEventListener('click', function() {
        document.querySelectorAll('.settings-menu-item').forEach(i => i.classList.remove('active'));
        this.classList.add('active');
        
        const section = this.dataset.section;
        document.querySelectorAll('.settings-section').forEach(s => s.style.display = 'none');
        document.getElementById(section).style.display = 'block';
    });
});

function selectLayout(layout) {
    document.querySelectorAll('.layout-option').forEach(opt => opt.classList.remove('active'));
    event.currentTarget.classList.add('active');
    document.querySelector(`input[value="${layout}"]`).checked = true;
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
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

