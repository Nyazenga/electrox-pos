<?php
/**
 * Update branch details with taxpayer information
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getPrimaryInstance();

echo "=== UPDATING BRANCH DETAILS ===\n\n";

try {
    $db->beginTransaction();
    
    // Update BELGRAVIA branch
    $belgravia = $db->getRow("SELECT * FROM branches WHERE branch_name = 'BELGRAVIA'");
    if ($belgravia) {
        echo "Updating BELGRAVIA branch details...\n";
        $db->update('branches', [
            'address' => '17 Phillips Avenue',
            'city' => 'HARARE',
            'phone' => '0776190449',
            'email' => 'accounts@electrox.co.zw'
        ], ['id' => $belgravia['id']]);
        echo "  ✓ Updated BELGRAVIA (ID: {$belgravia['id']})\n";
        echo "    Address: 17 Phillips Avenue, HARARE\n";
        echo "    Phone: 0776190449\n";
        echo "    Email: accounts@electrox.co.zw\n\n";
    }
    
    // Update RIDGEWAY branch
    $ridgeway = $db->getRow("SELECT * FROM branches WHERE branch_name = 'RIDGEWAY'");
    if ($ridgeway) {
        echo "Updating RIDGEWAY branch details...\n";
        $db->update('branches', [
            'address' => '147 ED Mnangagwa Rd',
            'city' => 'HARARE',
            'phone' => '0776190449',
            'email' => 'accounts@electrox.co.zw'
        ], ['id' => $ridgeway['id']]);
        echo "  ✓ Updated RIDGEWAY (ID: {$ridgeway['id']})\n";
        echo "    Address: 147 ED Mnangagwa Rd, HARARE\n";
        echo "    Phone: 0776190449\n";
        echo "    Email: accounts@electrox.co.zw\n\n";
    }
    
    $db->commitTransaction();
    echo "=== UPDATE COMPLETE ===\n";
    
} catch (Exception $e) {
    $db->rollbackTransaction();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

