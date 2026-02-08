<?php
/**
 * Update taxCode for taxID 514 (5% Non-VAT Withholding Tax) to 'B'
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

echo "========================================\n";
echo "Updating taxCode for taxID 514 to 'B'\n";
echo "========================================\n\n";

// Get all fiscal configs
$configs = $primaryDb->getRows("SELECT id, branch_id, device_id, applicable_taxes FROM fiscal_config");

$updated = 0;
foreach ($configs as $config) {
    $taxes = json_decode($config['applicable_taxes'], true);
    if (!is_array($taxes)) {
        continue;
    }
    
    $updatedTaxes = false;
    foreach ($taxes as &$tax) {
        if (isset($tax['taxID']) && intval($tax['taxID']) === 514) {
            if (empty($tax['taxCode']) || $tax['taxCode'] === '') {
                $tax['taxCode'] = 'B';
                $updatedTaxes = true;
                echo "  - Branch {$config['branch_id']}, Device {$config['device_id']}: Updated taxID 514 taxCode to 'B'\n";
            }
        }
    }
    unset($tax);
    
    if ($updatedTaxes) {
        $primaryDb->update('fiscal_config', [
            'applicable_taxes' => json_encode($taxes)
        ], ['id' => $config['id']]);
        $updated++;
    }
}

echo "\n========================================\n";
echo "✓ Updated $updated fiscal config(s)\n";
echo "========================================\n";
