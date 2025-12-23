<?php
/**
 * Check Stored Tax Configuration
 * 
 * This script shows what tax configuration is currently stored in the database.
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "========================================\n";
echo "STORED TAX CONFIGURATION CHECK\n";
echo "========================================\n\n";

$primaryDb = Database::getPrimaryInstance();

// Get all fiscal configs
$configs = $primaryDb->getRows(
    "SELECT fc.*, b.name as branch_name, fd.device_id 
     FROM fiscal_config fc
     JOIN branches b ON fc.branch_id = b.id
     JOIN fiscal_devices fd ON fc.device_id = fd.device_id
     ORDER BY fc.branch_id, fc.device_id"
);

if (empty($configs)) {
    echo "No fiscal configurations found in database.\n";
    exit;
}

foreach ($configs as $config) {
    echo "========================================\n";
    echo "Branch: {$config['branch_name']} (ID: {$config['branch_id']})\n";
    echo "Device ID: {$config['device_id']}\n";
    echo "Last Synced: {$config['last_synced']}\n";
    echo "========================================\n\n";
    
    $applicableTaxes = json_decode($config['applicable_taxes'], true);
    
    if (empty($applicableTaxes)) {
        echo "No applicable taxes stored.\n\n";
        continue;
    }
    
    echo "Stored Applicable Taxes:\n";
    echo "------------------------\n";
    foreach ($applicableTaxes as $index => $tax) {
        echo "Tax #" . ($index + 1) . ":\n";
        echo "  taxID: " . ($tax['taxID'] ?? 'NOT SET') . "\n";
        echo "  taxPercent: " . ($tax['taxPercent'] ?? 'NOT SET') . "\n";
        echo "  taxCode: " . ($tax['taxCode'] ?? 'NOT SET') . "\n";
        echo "  taxName: " . ($tax['taxName'] ?? 'NOT SET') . "\n";
        if (isset($tax['taxValidFrom'])) {
            echo "  taxValidFrom: " . $tax['taxValidFrom'] . "\n";
        }
        if (isset($tax['taxValidTill'])) {
            echo "  taxValidTill: " . $tax['taxValidTill'] . "\n";
        }
        echo "\n";
    }
    
    echo "Raw JSON:\n";
    echo json_encode($applicableTaxes, JSON_PRETTY_PRINT) . "\n\n";
}

echo "========================================\n";
echo "ANALYSIS\n";
echo "========================================\n";
echo "If you see taxID=4 for the 15.5% tax, that means:\n";
echo "1. The config was synced with the old code that assigned sequential IDs\n";
echo "2. You need to re-sync the configuration to get the correct taxID=517 from ZIMRA\n";
echo "\n";
echo "To fix: Go to Settings > Fiscalization > Sync Config\n";

