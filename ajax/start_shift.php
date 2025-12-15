<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

initSession();

header('Content-Type: application/json');

$auth = Auth::getInstance();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$auth->requirePermission('pos.access');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['starting_cash'])) {
    echo json_encode(['success' => false, 'message' => 'Starting cash amount is required']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Verify we're connected to the correct database
    $currentDb = $db->executeQuery("SELECT DATABASE() as db")->fetch()['db'] ?? 'unknown';
    $expectedDb = 'electrox_' . getCurrentTenantDbName();
    
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
    
    $db->beginTransaction();
    
    $branchId = $_SESSION['branch_id'] ?? null;
    $userId = $_SESSION['user_id'];
    
    if (!$userId) {
        throw new Exception('User ID not found in session');
    }
    
    // Check if there's already an open shift
    if ($branchId) {
        $existingShift = $db->getRow("SELECT * FROM shifts WHERE branch_id = :branch_id AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
            ':branch_id' => $branchId,
            ':user_id' => $userId
        ]);
    } else {
        $existingShift = $db->getRow("SELECT * FROM shifts WHERE (branch_id = 0 OR branch_id IS NULL) AND user_id = :user_id AND status = 'open' ORDER BY id DESC LIMIT 1", [
            ':user_id' => $userId
        ]);
    }
    
    if ($existingShift) {
        throw new Exception('You already have an active shift');
    }
    
    // Get next shift number
    if ($branchId) {
        $lastShift = $db->getRow("SELECT shift_number FROM shifts WHERE branch_id = :branch_id ORDER BY id DESC LIMIT 1", [':branch_id' => $branchId]);
    } else {
        $lastShift = $db->getRow("SELECT shift_number FROM shifts ORDER BY id DESC LIMIT 1");
    }
    $shiftNumber = ($lastShift ? intval($lastShift['shift_number']) : 0) + 1;
    
    $startingCash = floatval($input['starting_cash']);
    
    // Create new shift - handle null branch_id
    $shiftData = [
        'shift_number' => $shiftNumber,
        'user_id' => $userId,
        'opened_at' => date('Y-m-d H:i:s'),
        'opened_by' => $userId,
        'starting_cash' => $startingCash,
        'expected_cash' => $startingCash,
        'status' => 'open'
    ];
    
    // Only add branch_id if it's not null, otherwise set to 0
    $shiftData['branch_id'] = $branchId !== null ? $branchId : 0;
    
    $shiftId = $db->insert('shifts', $shiftData);
    
    if (!$shiftId) {
        $error = $db->getLastError();
        throw new Exception('Failed to create shift record: ' . ($error ?: 'Unknown error'));
    }
    
    $db->commitTransaction();
    
    // Verify the shift was created
    $verifyShift = $db->getRow("SELECT * FROM shifts WHERE id = :id", [':id' => $shiftId]);
    if (!$verifyShift) {
        throw new Exception('Shift was not created successfully');
    }
    
    logActivity($userId, 'shift_start', ['shift_id' => $shiftId, 'shift_number' => $shiftNumber]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Shift started successfully',
        'shift_id' => $shiftId,
        'debug' => [
            'database' => $currentDb,
            'expected_db' => $expectedDb,
            'user_id' => $userId,
            'branch_id' => $branchId
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        try {
            $db->rollbackTransaction();
        } catch (Exception $rollbackEx) {
            // Ignore rollback errors
        }
    }
    logError("Start shift error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to start shift: ' . $e->getMessage(),
        'debug' => [
            'user_id' => $_SESSION['user_id'] ?? 'NOT SET',
            'branch_id' => $_SESSION['branch_id'] ?? 'NOT SET',
            'tenant' => getCurrentTenantDbName() ?? 'NOT SET'
        ]
    ]);
}

