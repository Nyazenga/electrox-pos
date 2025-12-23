<?php
/**
 * Check if system is ready for interface testing
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

echo "========================================\n";
echo "INTERFACE TESTING READINESS CHECK\n";
echo "========================================\n\n";

// Check branches
echo "1. BRANCH FISCALIZATION STATUS:\n";
$branches = $db->getRows('SELECT id, name, fiscalization_enabled FROM branches');
foreach ($branches as $b) {
    $status = $b['fiscalization_enabled'] ? '✅ ENABLED' : '❌ DISABLED';
    echo "   Branch {$b['id']} ({$b['name']}): $status\n";
}

// Check devices
echo "\n2. DEVICE STATUS:\n";
$devices = $db->getRows('SELECT branch_id, device_id, device_serial_no, is_registered, is_active FROM fiscal_devices ORDER BY branch_id, device_id');
if (empty($devices)) {
    echo "   ⚠ No devices configured\n";
} else {
    foreach ($devices as $d) {
        $registered = $d['is_registered'] ? '✅ Yes' : '❌ No';
        $active = $d['is_active'] ? '✅ Yes' : '❌ No';
        echo "   Branch {$d['branch_id']}, Device {$d['device_id']} ({$d['device_serial_no']}):\n";
        echo "      Registered: $registered\n";
        echo "      Active: $active\n";
    }
}

// Check fiscal days
echo "\n3. FISCAL DAY STATUS:\n";
$fiscalDays = $db->getRows('SELECT branch_id, device_id, fiscal_day_no, status FROM fiscal_days WHERE status IN ("FiscalDayOpened", "FiscalDayCloseFailed") ORDER BY branch_id, device_id');
if (empty($fiscalDays)) {
    echo "   ℹ No open fiscal days (will auto-open on first sale)\n";
} else {
    foreach ($fiscalDays as $fd) {
        echo "   Branch {$fd['branch_id']}, Device {$fd['device_id']}: Day {$fd['fiscal_day_no']} - {$fd['status']}\n";
    }
}

echo "\n========================================\n";
echo "READY TO TEST?\n";
echo "========================================\n\n";

// Check if ready
$ready = true;
$issues = [];

foreach ($branches as $b) {
    if ($b['fiscalization_enabled']) {
        // Check if branch has a registered device
        $branchDevice = $db->getRow(
            'SELECT * FROM fiscal_devices WHERE branch_id = :branch_id AND is_active = 1',
            [':branch_id' => $b['id']]
        );
        
        if (!$branchDevice) {
            $ready = false;
            $issues[] = "Branch {$b['id']} ({$b['name']}) has fiscalization enabled but no active device";
        } elseif (!$branchDevice['is_registered']) {
            $ready = false;
            $issues[] = "Branch {$b['id']} ({$b['name']}) device {$branchDevice['device_id']} is not registered";
        }
    }
}

if ($ready) {
    echo "✅ YES! System is ready for testing!\n\n";
    echo "Next steps:\n";
    echo "1. Go to POS module\n";
    echo "2. Make a test sale\n";
    echo "3. Check receipt for fiscal details (QR code, verification code, etc.)\n";
} else {
    echo "❌ NOT READY - Issues found:\n\n";
    foreach ($issues as $issue) {
        echo "   - $issue\n";
    }
    echo "\nTo fix:\n";
    echo "1. Go to Settings > Fiscalization (ZIMRA)\n";
    echo "2. Select the branch with issues\n";
    echo "3. Register the device if not registered\n";
    echo "4. Ensure fiscalization is enabled\n";
}

echo "\n========================================\n";

