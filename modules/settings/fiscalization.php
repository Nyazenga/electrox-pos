<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/fiscal_service.php';
require_once APP_PATH . '/includes/zimra_api.php';

$auth = Auth::getInstance();
$auth->requireLogin();
$auth->requirePermission('settings.edit');

$pageTitle = 'Fiscalization Settings';

// Use PRIMARY database for fiscal tables
$db = Database::getPrimaryInstance();
$success = null;
$error = null;

// Get all branches from PRIMARY database
$branches = $db->getRows("SELECT * FROM branches ORDER BY branch_name");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_device') {
        $branchId = intval($_POST['branch_id']);
        $deviceId = intval($_POST['device_id']);
        $deviceSerialNo = trim($_POST['device_serial_no']);
        $activationKey = trim($_POST['activation_key']);
        $deviceModelName = trim($_POST['device_model_name'] ?? 'Server');
        $deviceModelVersion = trim($_POST['device_model_version'] ?? 'v1');
        $enableFiscalization = isset($_POST['enable_fiscalization']) ? 1 : 0;
        
        // Validate
        if (empty($deviceSerialNo) || empty($activationKey)) {
            $error = 'Device Serial Number and Activation Key are required';
        } else {
            try {
                $db->beginTransaction();
                
                // Update branch fiscalization enabled flag
                $db->update('branches', [
                    'fiscalization_enabled' => $enableFiscalization
                ], ['id' => $branchId]);
                
                // Check if device exists (use primary DB)
                $existing = $db->getRow(
                    "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND device_id = :device_id",
                    [':branch_id' => $branchId, ':device_id' => $deviceId]
                );
                
                $deviceData = [
                    'branch_id' => $branchId,
                    'device_id' => $deviceId,
                    'device_serial_no' => $deviceSerialNo,
                    'activation_key' => $activationKey,
                    'device_model_name' => $deviceModelName,
                    'device_model_version' => $deviceModelVersion,
                    'is_active' => 1
                ];
                
                if ($existing) {
                    $db->update('fiscal_devices', $deviceData, ['id' => $existing['id']]);
                } else {
                    $db->insert('fiscal_devices', $deviceData);
                }
                
                $db->commitTransaction();
                $success = 'Fiscal device settings saved successfully!';
            } catch (Exception $e) {
                $db->rollbackTransaction();
                $error = 'Error saving settings: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'register_device') {
        $branchId = intval($_POST['branch_id']);
        
        try {
            $fiscalService = new FiscalService($branchId);
            $result = $fiscalService->registerDevice();
            $success = 'Device registered successfully with ZIMRA!';
        } catch (Exception $e) {
            $error = 'Error registering device: ' . $e->getMessage();
        }
    } elseif ($action === 'sync_config') {
        $branchId = intval($_POST['branch_id']);
        
        try {
            $fiscalService = new FiscalService($branchId);
            $result = $fiscalService->syncConfig();
            $success = 'Configuration synced successfully from ZIMRA!';
        } catch (Exception $e) {
            $error = 'Error syncing configuration: ' . $e->getMessage();
        }
    } elseif ($action === 'verify_taxpayer') {
        $deviceId = intval($_POST['device_id']);
        $activationKey = trim($_POST['activation_key']);
        $deviceSerialNo = trim($_POST['device_serial_no']);
        
        try {
            $api = new ZimraApi('Server', 'v1', true);
            $result = $api->verifyTaxpayerInformation($deviceId, $activationKey, $deviceSerialNo);
            $success = 'Taxpayer information verified! Taxpayer: ' . $result['taxPayerName'];
            $_SESSION['verify_result'] = $result;
        } catch (Exception $e) {
            $error = 'Error verifying taxpayer: ' . $e->getMessage();
        }
    } elseif ($action === 'open_fiscal_day') {
        $branchId = intval($_POST['branch_id']);
        
        try {
            $fiscalService = new FiscalService($branchId);
            
            // COMPREHENSIVE VALIDATION: Check ZIMRA status first (source of truth)
            $status = $fiscalService->getFiscalDayStatus();
            if (!$status || !isset($status['fiscalDayStatus'])) {
                throw new Exception('Could not retrieve fiscal day status from ZIMRA. Please try again.');
            }
            
            $fiscalDayStatus = $status['fiscalDayStatus'];
            $fiscalDayNo = $status['lastFiscalDayNo'] ?? null;
            
            // Check if day is already open
            if ($fiscalDayStatus === 'FiscalDayOpened') {
                $error = 'Fiscal day is already open (Day No: ' . ($fiscalDayNo ?? 'Unknown') . '). Please close it first before opening a new one.';
            }
            // Check if a previous close attempt failed (day is still considered "open" for receipt submission)
            elseif ($fiscalDayStatus === 'FiscalDayCloseFailed') {
                $error = 'Fiscal day close previously failed (Day No: ' . ($fiscalDayNo ?? 'Unknown') . '). Please close the current fiscal day first before opening a new one.';
            }
            // Check if close is in progress
            elseif ($fiscalDayStatus === 'FiscalDayCloseInitiated') {
                $error = 'Fiscal day close is in progress (Day No: ' . ($fiscalDayNo ?? 'Unknown') . '). Please wait for the close operation to complete before opening a new day.';
            }
            // Only allow opening if day is closed
            elseif ($fiscalDayStatus === 'FiscalDayClosed') {
                $result = $fiscalService->openFiscalDay();
                $success = 'Fiscal day opened successfully! Day No: ' . $result['fiscalDayNo'];
            }
            // Unknown status
            else {
                $error = 'Unknown fiscal day status: ' . $fiscalDayStatus . '. Please check ZIMRA status and try again.';
            }
        } catch (Exception $e) {
            $error = 'Error opening fiscal day: ' . $e->getMessage();
        }
    } elseif ($action === 'close_fiscal_day') {
        $branchId = intval($_POST['branch_id']);
        
        try {
            $fiscalService = new FiscalService($branchId);
            
            // COMPREHENSIVE VALIDATION: Check ZIMRA status first (source of truth)
            $status = $fiscalService->getFiscalDayStatus();
            if (!$status || !isset($status['fiscalDayStatus'])) {
                throw new Exception('Could not retrieve fiscal day status from ZIMRA. Please try again.');
            }
            
            $fiscalDayStatus = $status['fiscalDayStatus'];
            $fiscalDayNo = $status['lastFiscalDayNo'] ?? null;
            
            // Check if day is closed
            if ($fiscalDayStatus === 'FiscalDayClosed') {
                $error = 'Fiscal day is already closed (Last Day No: ' . ($fiscalDayNo ?? 'Unknown') . '). No action needed.';
            }
            // Check if close is already in progress
            elseif ($fiscalDayStatus === 'FiscalDayCloseInitiated') {
                $error = 'Fiscal day close is already in progress (Day No: ' . ($fiscalDayNo ?? 'Unknown') . '). Please wait for the operation to complete.';
            }
            // Allow closing if day is open or if previous close failed (retry)
            elseif ($fiscalDayStatus === 'FiscalDayOpened' || $fiscalDayStatus === 'FiscalDayCloseFailed') {
                $result = $fiscalService->closeFiscalDay();
                $success = 'Fiscal day close initiated successfully! Operation ID: ' . ($result['operationID'] ?? 'N/A') . ' (Day No: ' . ($fiscalDayNo ?? 'Unknown') . ')';
            }
            // Unknown status
            else {
                $error = 'Cannot close fiscal day. Status: ' . $fiscalDayStatus . '. Please check ZIMRA status and try again.';
            }
        } catch (Exception $e) {
            $error = 'Error closing fiscal day: ' . $e->getMessage();
        }
    } elseif ($action === 'get_status') {
        $branchId = intval($_POST['branch_id']);
        
        try {
            $fiscalService = new FiscalService($branchId);
            $result = $fiscalService->getFiscalDayStatus();
            if ($result) {
                $success = 'Fiscal day status: ' . $result['fiscalDayStatus'];
                $_SESSION['status_result'] = $result;
            } else {
                $error = 'Could not retrieve fiscal day status';
            }
        } catch (Exception $e) {
            $error = 'Error getting status: ' . $e->getMessage();
        }
    }
}

// Get device info for each branch from PRIMARY database
$branchDevices = [];
foreach ($branches as $branch) {
    $device = $db->getRow(
        "SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1",
        [':branch_id' => $branch['id']]
    );
    
    $config = null;
    if ($device) {
        $config = $db->getRow(
            "SELECT * FROM fiscal_config WHERE branch_id = :branch_id AND device_id = :device_id",
            [':branch_id' => $branch['id'], ':device_id' => $device['device_id']]
        );
    }
    
    // Get fiscal day status from LOCAL database
    // Check for open days OR days that are in the process of closing
    $fiscalDay = null;
    $fiscalDayCloseInitiated = null;
    if ($device) {
        $fiscalDay = $db->getRow(
            "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status = 'FiscalDayOpened' ORDER BY id DESC LIMIT 1",
            [':branch_id' => $branch['id'], ':device_id' => $device['device_id']]
        );
        
        // Also check for days that are in the process of closing (asynchronous operation)
        $fiscalDayCloseInitiated = $db->getRow(
            "SELECT * FROM fiscal_days WHERE branch_id = :branch_id AND device_id = :device_id AND status = 'FiscalDayCloseInitiated' ORDER BY id DESC LIMIT 1",
            [':branch_id' => $branch['id'], ':device_id' => $device['device_id']]
        );
    }
    
    // Get ACTUAL fiscal day status from ZIMRA (if device is registered)
    $zimraStatus = null;
    if ($device && $device['is_registered'] && !empty($device['certificate_pem'])) {
        try {
            require_once APP_PATH . '/includes/fiscal_service.php';
            $fiscalService = new FiscalService($branch['id']);
            $zimraStatus = $fiscalService->getFiscalDayStatus();
        } catch (Exception $e) {
            // If we can't get status, that's okay - we'll show local status
            error_log("Could not get ZIMRA status for branch {$branch['id']}: " . $e->getMessage());
        }
    }
    
    $branchDevices[$branch['id']] = [
        'device' => $device,
        'config' => $config,
        'fiscalDay' => $fiscalDay,
        'fiscalDayCloseInitiated' => $fiscalDayCloseInitiated,
        'zimraStatus' => $zimraStatus
    ];
}

require_once APP_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Fiscalization Settings (ZIMRA)</h2>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= escapeHtml($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= escapeHtml($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['verify_result'])): ?>
    <div class="alert alert-info">
        <h5>Taxpayer Verification Result:</h5>
        <p><strong>Name:</strong> <?= escapeHtml($_SESSION['verify_result']['taxPayerName']) ?></p>
        <p><strong>TIN:</strong> <?= escapeHtml($_SESSION['verify_result']['taxPayerTIN']) ?></p>
        <?php if (isset($_SESSION['verify_result']['vatNumber'])): ?>
            <p><strong>VAT Number:</strong> <?= escapeHtml($_SESSION['verify_result']['vatNumber']) ?></p>
        <?php endif; ?>
        <p><strong>Branch Name:</strong> <?= escapeHtml($_SESSION['verify_result']['deviceBranchName']) ?></p>
    </div>
    <?php unset($_SESSION['verify_result']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['status_result'])): ?>
    <div class="alert alert-info">
        <h5>Fiscal Day Status:</h5>
        <p><strong>Status:</strong> <?= escapeHtml($_SESSION['status_result']['fiscalDayStatus']) ?></p>
        <?php if (isset($_SESSION['status_result']['lastFiscalDayNo'])): ?>
            <p><strong>Fiscal Day No:</strong> <?= escapeHtml($_SESSION['status_result']['lastFiscalDayNo']) ?></p>
        <?php endif; ?>
        <?php if (isset($_SESSION['status_result']['lastReceiptGlobalNo'])): ?>
            <p><strong>Last Receipt Global No:</strong> <?= escapeHtml($_SESSION['status_result']['lastReceiptGlobalNo']) ?></p>
        <?php endif; ?>
    </div>
    <?php unset($_SESSION['status_result']); ?>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Device Configuration</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="deviceForm">
            <input type="hidden" name="action" value="save_device">
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Branch *</label>
                    <select name="branch_id" class="form-select" required id="branchSelect">
                        <option value="">Select Branch</option>
                        <?php foreach ($branches as $branch): 
                            $deviceInfo = $branchDevices[$branch['id']] ?? null;
                            $device = $deviceInfo['device'] ?? null;
                        ?>
                            <option value="<?= $branch['id'] ?>" 
                                data-enabled="<?= $branch['fiscalization_enabled'] ?? 0 ?>"
                                data-device-id="<?= $device ? $device['device_id'] : '' ?>"
                                data-serial="<?= $device ? escapeHtml($device['device_serial_no']) : '' ?>"
                                data-key="<?= $device ? escapeHtml($device['activation_key']) : '' ?>"
                                data-model="<?= $device ? escapeHtml($device['device_model_name']) : 'Server' ?>"
                                data-version="<?= $device ? escapeHtml($device['device_model_version']) : 'v1' ?>">
                                <?= escapeHtml($branch['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Enable Fiscalization</label>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="enable_fiscalization" 
                            id="enableFiscalization" value="1">
                        <label class="form-check-label" for="enableFiscalization">
                            Enable fiscalization for this branch
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Device ID *</label>
                    <input type="number" name="device_id" class="form-control" required 
                        id="deviceIdInput" placeholder="e.g., 30199 or 30200">
                    <small class="text-muted">Test Device: 30200 (both branches use this for testing)</small>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Device Serial Number *</label>
                    <input type="text" name="device_serial_no" class="form-control" required 
                        id="deviceSerialInput" placeholder="e.g., electrox-1 or electrox-2" maxlength="20">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Activation Key *</label>
                    <input type="text" name="activation_key" class="form-control" required 
                        id="activationKeyInput" placeholder="8 character key" maxlength="8">
                    <small class="text-muted">Head Office: 00544726, Hillside: 00294543</small>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Device Model Name</label>
                    <input type="text" name="device_model_name" class="form-control" 
                        id="deviceModelInput" value="Server" placeholder="Server">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Device Model Version</label>
                    <input type="text" name="device_model_version" class="form-control" 
                        id="deviceVersionInput" value="v1" placeholder="v1">
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> Save Device Settings
            </button>
        </form>
    </div>
</div>

<!-- Step 1: Initial Setup - Verify Taxpayer -->
<?php if ($auth->hasPermission('fiscalization.verify_taxpayer')): ?>
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="bi bi-1-circle"></i> Step 1: Verify Taxpayer Information</h5>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            <strong>Purpose:</strong> Verify taxpayer information <strong>before</strong> device registration.<br>
            <small class="text-warning">⚠️ <strong>Note:</strong> Activation keys may only work before registration. If device is already registered, this may fail.</small>
        </p>
        <form method="POST" class="row g-3">
            <input type="hidden" name="action" value="verify_taxpayer">
            <div class="col-md-3">
                <label class="form-label">Device ID *</label>
                <input type="number" name="device_id" class="form-control form-control-sm" 
                    placeholder="e.g., 30200" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Activation Key *</label>
                <input type="text" name="activation_key" class="form-control form-control-sm" 
                    placeholder="8 character key" required maxlength="8">
            </div>
            <div class="col-md-3">
                <label class="form-label">Serial No *</label>
                <input type="text" name="device_serial_no" class="form-control form-control-sm" 
                    placeholder="e.g., electrox-2" required>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-check-circle"></i> Verify
                </button>
            </div>
        </form>
        <div class="mt-3">
            <small class="text-muted">
                <strong>Test Device Combinations:</strong><br>
                Device 30200: Key 00294543, Serial electrox-2 (Both branches use this for testing)
            </small>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Step 2: Register Device -->
<?php if ($auth->hasPermission('fiscalization.register_device')): ?>
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="bi bi-2-circle"></i> Step 2: Register Device with ZIMRA</h5>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            <strong>Purpose:</strong> Register the device with ZIMRA and obtain the device certificate.<br>
            <small><strong>Prerequisites:</strong> Device must be configured in "Device Configuration" section above, and taxpayer must be verified.</small>
        </p>
        <form method="POST" class="row g-3">
            <input type="hidden" name="action" value="register_device">
            <div class="col-md-4">
                <label class="form-label">Select Branch *</label>
                <select name="branch_id" class="form-select form-select-sm" required>
                    <option value="">Select Branch</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>">
                            <?= escapeHtml($branch['branch_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-success">
                    <i class="bi bi-key"></i> Register Device
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Daily Operations: Fiscal Day Management -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-calendar-check"></i> Daily Operations: Fiscal Day Management</h5>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            <strong>Purpose:</strong> Manage fiscal days - open at start of business, close at end of day.<br>
            <small><strong>Note:</strong> You must close the current fiscal day before opening a new one.</small>
        </p>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="border rounded p-3">
                    <h6 class="mb-2"><i class="bi bi-calendar-plus text-success"></i> Open Fiscal Day</h6>
                    <p class="text-muted small mb-2">Open a new fiscal day for the selected branch</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="open_fiscal_day">
                        <div class="mb-2">
                            <select name="branch_id" class="form-select form-select-sm" required>
                                <option value="">Select Branch</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= $branch['id'] ?>">
                                        <?= escapeHtml($branch['branch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-success w-100">
                            <i class="bi bi-calendar-plus"></i> Open Fiscal Day
                        </button>
                    </form>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3">
                    <h6 class="mb-2"><i class="bi bi-calendar-x text-warning"></i> Close Fiscal Day</h6>
                    <p class="text-muted small mb-2">Close the current fiscal day (required before opening a new one)</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="close_fiscal_day">
                        <div class="mb-2">
                            <select name="branch_id" class="form-select form-select-sm" required>
                                <option value="">Select Branch</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= $branch['id'] ?>">
                                        <?= escapeHtml($branch['branch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-warning w-100">
                            <i class="bi bi-calendar-x"></i> Close Fiscal Day
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="border rounded p-3">
                    <h6 class="mb-2"><i class="bi bi-info-circle text-info"></i> Check Fiscal Day Status</h6>
                    <p class="text-muted small mb-2">Get the current fiscal day status from ZIMRA</p>
                    <?php if ($auth->hasPermission('fiscalization.view_status')): ?>
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="get_status">
                        <div class="col-md-4">
                            <select name="branch_id" class="form-select form-select-sm" required>
                                <option value="">Select Branch</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= $branch['id'] ?>">
                                        <?= escapeHtml($branch['branch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <button type="submit" class="btn btn-sm btn-info">
                                <i class="bi bi-info-circle"></i> Get Status
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-warning">You do not have permission to view fiscal day status.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Maintenance: Sync Configuration -->
<?php if ($auth->hasPermission('fiscalization.sync_config')): ?>
<div class="card mb-4">
    <div class="card-header bg-secondary text-white">
        <h5 class="mb-0"><i class="bi bi-arrow-repeat"></i> Maintenance: Sync Configuration</h5>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            <strong>Purpose:</strong> Sync device configuration from ZIMRA to update local settings (taxpayer info, taxes, etc.)
        </p>
        <form method="POST" class="row g-3">
            <input type="hidden" name="action" value="sync_config">
            <div class="col-md-4">
                <label class="form-label">Select Branch *</label>
                <select name="branch_id" class="form-select form-select-sm" required>
                    <option value="">Select Branch</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= $branch['id'] ?>">
                            <?= escapeHtml($branch['branch_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-secondary">
                    <i class="bi bi-arrow-repeat"></i> Sync Configuration from ZIMRA
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-0">Branch Device Status</h5>
            <small class="text-muted">Fiscal Day status is fetched from ZIMRA in real-time (updates every 3 seconds)</small>
        </div>
        <div>
            <span id="statusLastUpdate" class="badge bg-secondary">Last update: --</span>
            <button id="refreshStatusBtn" class="btn btn-sm btn-outline-primary ms-2">
                <i class="bi bi-arrow-clockwise"></i> Refresh Now
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Device ID</th>
                        <th>Serial No</th>
                        <th>Activation Key</th>
                        <th>Registered</th>
                        <th>Has Certificate</th>
                        <th>Fiscalization Enabled</th>
                        <th>Fiscal Day</th>
                        <th>Last Sync</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="statusTableBody">
                    <?php foreach ($branches as $branch): 
                        $deviceInfo = $branchDevices[$branch['id']] ?? [];
                        $device = $deviceInfo['device'] ?? null;
                        $config = $deviceInfo['config'] ?? null;
                        $fiscalDay = $deviceInfo['fiscalDay'] ?? null;
                        $fiscalDayCloseInitiated = $deviceInfo['fiscalDayCloseInitiated'] ?? null;
                        $zimraStatus = $deviceInfo['zimraStatus'] ?? null;
                        
                        // Use ZIMRA status if available, otherwise use local status
                        $actualFiscalDayStatus = null;
                        $isDayOpen = false;
                        $isClosing = false;
                        $statusSource = 'unknown';
                        
                        if ($zimraStatus && isset($zimraStatus['fiscalDayStatus'])) {
                            $actualFiscalDayStatus = $zimraStatus['fiscalDayStatus'];
                            $statusSource = 'zimra';
                            $isDayOpen = ($actualFiscalDayStatus === 'FiscalDayOpened' || $actualFiscalDayStatus === 'FiscalDayCloseFailed');
                            if ($isDayOpen && $fiscalDayCloseInitiated && $actualFiscalDayStatus === 'FiscalDayOpened') {
                                $isClosing = true;
                            }
                        } elseif ($fiscalDay) {
                            $actualFiscalDayStatus = $fiscalDay['status'];
                            $statusSource = 'local';
                            $isDayOpen = ($actualFiscalDayStatus === 'FiscalDayOpened' || $actualFiscalDayStatus === 'FiscalDayCloseFailed');
                        } elseif ($fiscalDayCloseInitiated) {
                            $actualFiscalDayStatus = 'FiscalDayCloseInitiated';
                            $statusSource = 'local';
                            $isDayOpen = true;
                            $isClosing = true;
                        } else {
                            $actualFiscalDayStatus = 'FiscalDayClosed';
                            $statusSource = 'assumed';
                            $isDayOpen = false;
                        }
                        
                        $showZimraWarning = ($device && $device['is_registered'] && !empty($device['certificate_pem']) && !$zimraStatus);
                    ?>
                        <tr data-branch-id="<?= $branch['id'] ?>">
                            <td><strong><?= escapeHtml($branch['branch_name']) ?></strong></td>
                            <td><?= $device ? escapeHtml($device['device_id']) : '<span class="text-muted">Not Set</span>' ?></td>
                            <td><?= $device ? escapeHtml($device['device_serial_no']) : '<span class="text-muted">Not Set</span>' ?></td>
                            <td><?= $device ? escapeHtml($device['activation_key']) : '<span class="text-muted">Not Set</span>' ?></td>
                            <td>
                                <?php if ($device && $device['is_registered']): ?>
                                    <span class="badge bg-success">Yes</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($device && !empty($device['certificate_pem'])): ?>
                                    <span class="badge bg-success">Yes</span>
                                    <?php if ($device['certificate_valid_till']): ?>
                                        <br><small class="text-muted">Valid till: <?= date('Y-m-d', strtotime($device['certificate_valid_till'])) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-danger">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($branch['fiscalization_enabled']): ?>
                                    <span class="badge bg-success">Enabled</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td class="fiscal-day-status" data-status="<?= htmlspecialchars($actualFiscalDayStatus ?? '') ?>" data-day-no="<?= htmlspecialchars(($zimraStatus['lastFiscalDayNo'] ?? $fiscalDay['fiscal_day_no'] ?? '') ?? '') ?>">
                                <?php if ($isClosing): ?>
                                    <span class="badge bg-info">Closing...</span>
                                    <br><small class="text-muted">Close initiated, waiting for ZIMRA</small>
                                    <?php if ($fiscalDayCloseInitiated): ?>
                                        <br><small class="text-muted">Day #<?= $fiscalDayCloseInitiated['fiscal_day_no'] ?></small>
                                    <?php elseif ($zimraStatus && isset($zimraStatus['lastFiscalDayNo'])): ?>
                                        <br><small class="text-muted">Day #<?= $zimraStatus['lastFiscalDayNo'] ?></small>
                                    <?php elseif ($fiscalDay): ?>
                                        <br><small class="text-muted">Day #<?= $fiscalDay['fiscal_day_no'] ?></small>
                                    <?php endif; ?>
                                    <br><small class="text-info">⏳ Please wait, then refresh to check status</small>
                                <?php elseif ($isDayOpen): ?>
                                    <span class="badge bg-success">Open</span>
                                    <?php if ($actualFiscalDayStatus === 'FiscalDayCloseFailed'): ?>
                                        <br><small class="text-danger">Close Failed - Retry Close</small>
                                    <?php endif; ?>
                                    <?php if ($zimraStatus && isset($zimraStatus['lastFiscalDayNo'])): ?>
                                        <br><small class="text-muted">Day #<?= $zimraStatus['lastFiscalDayNo'] ?></small>
                                    <?php elseif ($fiscalDay): ?>
                                        <br><small class="text-muted">Day #<?= $fiscalDay['fiscal_day_no'] ?></small>
                                    <?php endif; ?>
                                    <?php if ($showZimraWarning): ?>
                                        <br><small class="text-warning">⚠ Could not fetch ZIMRA status</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-warning">Closed</span>
                                    <?php if ($zimraStatus && isset($zimraStatus['lastFiscalDayNo'])): ?>
                                        <br><small class="text-muted">Last Day #<?= $zimraStatus['lastFiscalDayNo'] ?></small>
                                    <?php endif; ?>
                                    <?php if ($showZimraWarning): ?>
                                        <br><small class="text-warning">⚠ Could not fetch ZIMRA status</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $config && $config['last_synced'] ? escapeHtml($config['last_synced']) : '<span class="text-muted">Never</span>' ?></td>
                            <td>
                                <?php if ($device && $device['certificate_valid_till']): 
                                    $expiry = new DateTime($device['certificate_valid_till']);
                                    $now = new DateTime();
                                    if ($expiry < $now):
                                ?>
                                    <span class="badge bg-danger">Certificate Expired</span>
                                <?php elseif ($expiry->diff($now)->days < 30): ?>
                                    <span class="badge bg-warning">Expiring Soon</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Active</span>
                                <?php endif; ?>
                                <?php elseif ($device): ?>
                                    <span class="badge bg-warning">Not Registered</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Not Configured</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const branchSelect = document.getElementById('branchSelect');
    const enableSwitch = document.getElementById('enableFiscalization');
    const deviceIdInput = document.getElementById('deviceIdInput');
    const deviceSerialInput = document.getElementById('deviceSerialInput');
    const activationKeyInput = document.getElementById('activationKeyInput');
    const deviceModelInput = document.getElementById('deviceModelInput');
    const deviceVersionInput = document.getElementById('deviceVersionInput');
    
    if (branchSelect) {
        branchSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const enabled = selectedOption.getAttribute('data-enabled') === '1';
            enableSwitch.checked = enabled;
            
            // Populate form fields with existing device data
            const deviceId = selectedOption.getAttribute('data-device-id');
            const serial = selectedOption.getAttribute('data-serial');
            const key = selectedOption.getAttribute('data-key');
            const model = selectedOption.getAttribute('data-model');
            const version = selectedOption.getAttribute('data-version');
            
            if (deviceIdInput) deviceIdInput.value = deviceId || '';
            if (deviceSerialInput) deviceSerialInput.value = serial || '';
            if (activationKeyInput) activationKeyInput.value = key || '';
            if (deviceModelInput) deviceModelInput.value = model || 'Server';
            if (deviceVersionInput) deviceVersionInput.value = version || 'v1';
        });
    }
    
    // AJAX Status Checker - Updates every 3 seconds
    let statusCheckInterval = null;
    const statusLastUpdate = document.getElementById('statusLastUpdate');
    const refreshStatusBtn = document.getElementById('refreshStatusBtn');
    const statusTableBody = document.getElementById('statusTableBody');
    
    function updateFiscalDayStatus() {
        fetch('<?= APP_PATH ?>/ajax/get_fiscal_day_status.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.statuses) {
                    // Update last update timestamp
                    if (statusLastUpdate) {
                        statusLastUpdate.textContent = 'Last update: ' + (data.timestamp || '--');
                    }
                    
                    // Update each row in the status table
                    data.statuses.forEach(status => {
                        const row = statusTableBody.querySelector(`tr[data-branch-id="${status.branch_id}"]`);
                        if (row) {
                            const statusCell = row.querySelector('.fiscal-day-status');
                            if (statusCell) {
                                let html = '';
                                
                                if (status.fiscal_day_status === 'FiscalDayOpened' || status.fiscal_day_status === 'FiscalDayCloseFailed') {
                                    const badgeColor = status.fiscal_day_status === 'FiscalDayCloseFailed' ? 'danger' : 'success';
                                    const badgeText = status.fiscal_day_status === 'FiscalDayCloseFailed' ? 'Close Failed' : 'Open';
                                    html = `<span class="badge bg-${badgeColor}">${badgeText}</span>`;
                                    if (status.fiscal_day_status === 'FiscalDayCloseFailed') {
                                        html += '<br><small class="text-danger">Close Failed - Retry Close</small>';
                                    }
                                    if (status.fiscal_day_no) {
                                        html += `<br><small class="text-muted">Day #${status.fiscal_day_no}</small>`;
                                    }
                                } else if (status.fiscal_day_status === 'FiscalDayClosed') {
                                    html = '<span class="badge bg-warning">Closed</span>';
                                    if (status.fiscal_day_no) {
                                        html += `<br><small class="text-muted">Last Day #${status.fiscal_day_no}</small>`;
                                    }
                                } else {
                                    html = '<span class="badge bg-secondary">Unknown</span>';
                                }
                                
                                if (status.error) {
                                    html += `<br><small class="text-danger">⚠ ${status.error}</small>`;
                                }
                                
                                statusCell.innerHTML = html;
                                statusCell.setAttribute('data-status', status.fiscal_day_status || '');
                                statusCell.setAttribute('data-day-no', status.fiscal_day_no || '');
                            }
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Error updating fiscal day status:', error);
                if (statusLastUpdate) {
                    statusLastUpdate.textContent = 'Last update: Error';
                    statusLastUpdate.className = 'badge bg-danger';
                }
            });
    }
    
    // Start periodic status checking every 3 seconds
    if (statusTableBody) {
        updateFiscalDayStatus(); // Initial update
        statusCheckInterval = setInterval(updateFiscalDayStatus, 3000); // Every 3 seconds
    }
    
    // Manual refresh button
    if (refreshStatusBtn) {
        refreshStatusBtn.addEventListener('click', function() {
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Refreshing...';
            updateFiscalDayStatus();
            setTimeout(() => {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refresh Now';
            }, 1000);
        });
    }
    
    // Clean up interval when page unloads
    window.addEventListener('beforeunload', function() {
        if (statusCheckInterval) {
            clearInterval(statusCheckInterval);
        }
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

