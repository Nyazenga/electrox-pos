<?php
/**
 * Verify Fiscal Data and Taxes on Localhost
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

echo "========================================\n";
echo "Fiscal Data & Taxes Verification\n";
echo "========================================\n\n";

// Check fiscal_devices
$devices = $primaryDb->getRows("SELECT * FROM fiscal_devices");
echo "Fiscal Devices: " . count($devices) . "\n";
if (empty($devices)) {
    echo "  ✗ No fiscal devices found!\n";
    echo "  You need to restore fiscal data from backup.\n";
    exit(1);
}

foreach ($devices as $device) {
    echo "  - Device ID: {$device['device_id']}, Branch: {$device['branch_id']}\n";
}

echo "\n";

// Check fiscal_config and taxes
$configs = $primaryDb->getRows("SELECT branch_id, device_id, applicable_taxes FROM fiscal_config");
echo "Fiscal Configs: " . count($configs) . "\n";

if (empty($configs)) {
    echo "  ✗ No fiscal configs found!\n";
    echo "  You need to restore fiscal data from backup.\n";
    exit(1);
}

$hasTaxes = false;
foreach ($configs as $config) {
    $taxes = json_decode($config['applicable_taxes'] ?? '[]', true);
    echo "  - Branch: {$config['branch_id']}, Device: {$config['device_id']}, Taxes: " . count($taxes) . "\n";
    
    if (!empty($taxes)) {
        $hasTaxes = true;
        foreach ($taxes as $tax) {
            $taxName = $tax['taxName'] ?? 'Unknown';
            $taxPercent = $tax['taxPercent'] ?? 0;
            $taxCode = $tax['taxCode'] ?? '';
            $taxID = $tax['taxID'] ?? '';
            echo "    * {$taxName} ({$taxPercent}%) - Code: {$taxCode} - ID: {$taxID}\n";
        }
    } else {
        echo "    ⚠ No applicable taxes configured!\n";
    }
}

echo "\n========================================\n";
if ($hasTaxes) {
    echo "✓ Fiscal data restored successfully!\n";
    echo "✓ Taxes are available for product creation.\n";
} else {
    echo "⚠ Fiscal devices exist but no taxes configured.\n";
    echo "You need to sync fiscal configuration from ZIMRA.\n";
    echo "\nTo sync:\n";
    echo "1. Go to Fiscal Configuration page\n";
    echo "2. Click 'Sync Configuration from ZIMRA' for each branch\n";
    echo "3. This will fetch applicable taxes from ZIMRA\n";
}
echo "========================================\n";
