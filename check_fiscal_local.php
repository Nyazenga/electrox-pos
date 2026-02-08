<?php
/**
 * Check Fiscal Data Status on Localhost
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

echo "========================================\n";
echo "Fiscal Data Status Check (Localhost)\n";
echo "========================================\n\n";

// Check fiscal_devices
$devices = $primaryDb->getRows("SELECT * FROM fiscal_devices");
echo "Fiscal Devices: " . count($devices) . "\n";
if (!empty($devices)) {
    foreach ($devices as $device) {
        echo "  - Device ID: {$device['device_id']}, Branch: {$device['branch_id']}, Registered: " . ($device['is_registered'] ? 'Yes' : 'No') . ", Active: " . ($device['is_active'] ? 'Yes' : 'No') . "\n";
    }
} else {
    echo "  ⚠ No fiscal devices found!\n";
}

echo "\n";

// Check fiscal_config
$configs = $primaryDb->getRows("SELECT branch_id, device_id, applicable_taxes FROM fiscal_config");
echo "Fiscal Configs: " . count($configs) . "\n";
if (!empty($configs)) {
    foreach ($configs as $config) {
        $taxes = json_decode($config['applicable_taxes'] ?? '[]', true);
        echo "  - Branch: {$config['branch_id']}, Device: {$config['device_id']}, Taxes: " . count($taxes) . "\n";
        if (!empty($taxes)) {
            foreach ($taxes as $tax) {
                echo "    * {$tax['taxName']} ({$tax['taxPercent']}%) - Code: {$tax['taxCode']}\n";
            }
        } else {
            echo "    ⚠ No applicable taxes configured!\n";
        }
    }
} else {
    echo "  ⚠ No fiscal configs found!\n";
}

echo "\n========================================\n";
echo "Summary:\n";
echo "========================================\n";
if (empty($devices) || empty($configs)) {
    echo "⚠ Fiscal data is missing. You need to restore from backup.\n";
    echo "\nTo restore:\n";
    echo "1. Download backup from server:\n";
    echo "   pscp.exe -pw \"GRCAdmin123/\" root@31.97.199.82:/tmp/fiscal_backup_20260203_175512.sql .\n";
    echo "2. Run restore script:\n";
    echo "   php restore_fiscal_simple.php fiscal_backup_20260203_175512.sql\n";
} else {
    $hasTaxes = false;
    foreach ($configs as $config) {
        $taxes = json_decode($config['applicable_taxes'] ?? '[]', true);
        if (!empty($taxes)) {
            $hasTaxes = true;
            break;
        }
    }
    if (!$hasTaxes) {
        echo "⚠ Fiscal devices exist but no taxes configured.\n";
        echo "You need to sync fiscal configuration from ZIMRA.\n";
    } else {
        echo "✓ Fiscal data looks good!\n";
    }
}
