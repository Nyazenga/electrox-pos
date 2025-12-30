<?php
/**
 * List branches for re-registration
 */

define('APP_PATH', __DIR__);
require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

echo "Branches with Fiscal Devices:\n";
echo "==============================\n\n";

$devices = $db->getRows(
    "SELECT fd.branch_id, b.branch_name, fd.device_id, fd.is_registered, fd.activation_key 
     FROM fiscal_devices fd 
     JOIN branches b ON fd.branch_id = b.id 
     WHERE fd.is_active = 1 
     ORDER BY b.branch_name"
);

foreach ($devices as $device) {
    echo "Branch: {$device['branch_name']} (ID: {$device['branch_id']})\n";
    echo "  Device ID: {$device['device_id']}\n";
    echo "  Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . "\n";
    echo "  Activation Key: " . ($device['activation_key'] ? 'Present' : 'MISSING') . "\n";
    echo "  Re-register URL: https://nedcom.co.zw/re_register_and_validate_certificate.php?branch_id={$device['branch_id']}\n";
    echo "\n";
}

