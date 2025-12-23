<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

$auth = Auth::getInstance();
$auth->requireLogin();
// Allow if user can access POS (pos.view or pos.create_sale)
if (!$auth->hasPermission('pos.view') && !$auth->hasPermission('pos.create_sale')) {
    $auth->requirePermission('pos.view'); // This will show access denied
}

$pageTitle = 'Start Shift';

$db = Database::getInstance();
$branchId = $_SESSION['branch_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    redirectTo('login.php');
}

// Check if shifts table exists, create if not
$tableExists = $db->getRow("SHOW TABLES LIKE 'shifts'");
if (!$tableExists) {
    // Create shifts table if it doesn't exist
    $createTableSql = "CREATE TABLE IF NOT EXISTS `shifts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `shift_number` int(11) NOT NULL,
        `branch_id` int(11) DEFAULT 0,
        `user_id` int(11) NOT NULL,
        `opened_at` datetime NOT NULL,
        `closed_at` datetime DEFAULT NULL,
        `opened_by` int(11) NOT NULL,
        `closed_by` int(11) DEFAULT NULL,
        `starting_cash` decimal(10,2) DEFAULT 0.00,
        `expected_cash` decimal(10,2) DEFAULT 0.00,
        `actual_cash` decimal(10,2) DEFAULT NULL,
        `difference` decimal(10,2) DEFAULT 0.00,
        `status` enum('open','closed') DEFAULT 'open',
        `notes` text DEFAULT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_branch_id` (`branch_id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->executeQuery($createTableSql);
}

// Check if shift already exists
if ($branchId) {
    $currentShift = $db->getRow("SELECT * FROM shifts WHERE branch_id = :branch_id AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
        ':branch_id' => $branchId,
        ':user_id' => $userId
    ]);
} else {
    // If no branch_id, check for any open shift for this user (branch_id = 0 or NULL)
    $currentShift = $db->getRow("SELECT * FROM shifts WHERE (branch_id = 0 OR branch_id IS NULL) AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
        ':user_id' => $userId
    ]);
}

// If shift exists, redirect to POS
if ($currentShift) {
    redirectTo('modules/pos/index.php');
}

require_once APP_PATH . '/includes/header.php';
?>

<style>
.start-shift-container {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: calc(100vh - 200px);
    padding: 40px 20px;
}

.start-shift-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    padding: 40px;
    max-width: 500px;
    width: 100%;
}

.start-shift-header {
    text-align: center;
    margin-bottom: 30px;
}

.start-shift-header h2 {
    color: var(--primary-blue);
    margin-bottom: 10px;
}

.start-shift-header p {
    color: var(--text-muted);
    font-size: 14px;
}

.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text-dark);
}

.form-control-lg {
    font-size: 24px;
    padding: 15px;
    text-align: center;
    font-weight: 600;
}

.btn-start-shift {
    width: 100%;
    padding: 15px;
    font-size: 18px;
    font-weight: 600;
}

/* ========== MOBILE RESPONSIVE STYLES ========== */

/* Tablet and below (max-width: 1024px) */
@media (max-width: 1024px) {
    .start-shift-container {
        padding: 30px 20px;
        min-height: calc(100vh - 150px);
    }
    
    .start-shift-card {
        padding: 30px;
    }
}

/* Mobile (max-width: 768px) */
@media (max-width: 768px) {
    .start-shift-container {
        padding: 20px 15px;
        min-height: calc(100vh - 60px);
        align-items: flex-start;
        padding-top: 40px;
    }
    
    .start-shift-card {
        padding: 25px 20px;
        max-width: 100%;
        border-radius: 0;
        box-shadow: none;
    }
    
    .start-shift-header h2 {
        font-size: 24px;
    }
    
    .start-shift-header p {
        font-size: 13px;
    }
    
    .form-control-lg {
        font-size: 20px;
        padding: 12px;
    }
    
    .btn-start-shift {
        padding: 15px;
        font-size: 16px;
    }
}

/* Small Mobile (max-width: 480px) */
@media (max-width: 480px) {
    .start-shift-container {
        padding: 15px 10px;
        padding-top: 30px;
    }
    
    .start-shift-card {
        padding: 20px 15px;
    }
    
    .start-shift-header h2 {
        font-size: 20px;
    }
    
    .form-control-lg {
        font-size: 18px;
        padding: 10px;
    }
    
    .btn-start-shift {
        padding: 12px;
        font-size: 15px;
    }
}
</style>

<div class="start-shift-container">
    <div class="start-shift-card">
        <div class="start-shift-header">
            <h2><i class="bi bi-play-circle"></i> Start Shift</h2>
            <p>Enter the starting cash amount to begin your shift</p>
        </div>
        
        <form id="startShiftForm">
            <div class="form-group">
                <label for="startingCash">Starting Cash Amount</label>
                <input type="number" 
                       class="form-control form-control-lg" 
                       id="startingCash" 
                       name="startingCash"
                       placeholder="0.00" 
                       step="0.01" 
                       min="0" 
                       value="" 
                       required
                       autofocus>
            </div>
            
            <button type="submit" class="btn btn-primary btn-start-shift">
                <i class="bi bi-play-fill"></i> Start Shift
            </button>
        </form>
    </div>
</div>

<script>
// Clear field on focus if it's empty or has default value
document.getElementById('startingCash').addEventListener('focus', function() {
    if (this.value === '0.00' || this.value === '0' || this.value === '') {
        this.value = '';
    }
});

// Clear field when user starts typing
document.getElementById('startingCash').addEventListener('input', function(e) {
    // If user types and field starts with 0.00 or 0, clear it
    if (this.value.startsWith('0.00') && e.inputType === 'insertText') {
        this.value = this.value.replace(/^0\.00/, '');
    }
    if (this.value.startsWith('0') && this.value.length > 1 && e.inputType === 'insertText' && this.value[1] !== '.') {
        this.value = this.value.substring(1);
    }
});

document.getElementById('startShiftForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const startingCash = parseFloat(document.getElementById('startingCash').value) || 0;
    
    if (startingCash < 0) {
        Swal.fire('Error', 'Starting cash cannot be negative', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Starting Shift...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('<?= BASE_URL ?>ajax/start_shift.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({
            starting_cash: startingCash
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: 'Success',
                text: 'Shift started successfully',
                icon: 'success',
                confirmButtonText: 'Continue to POS'
            }).then(() => {
                // Force a hard redirect to ensure the shift is detected
                window.location.href = '<?= BASE_URL ?>modules/pos/index.php?shift_started=1';
            });
        } else {
            console.error('Start shift error:', data);
            Swal.fire({
                title: 'Error',
                text: data.message || 'Failed to start shift',
                icon: 'error',
                footer: data.debug ? '<small>Debug: ' + JSON.stringify(data.debug) + '</small>' : ''
            });
        }
    })
    .catch(error => {
        Swal.fire('Error', 'Failed to start shift', 'error');
    });
});
</script>

<?php require_once APP_PATH . '/includes/footer.php'; ?>

