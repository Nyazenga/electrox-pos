<?php
// Simple script to update taxCode for taxID 514 to 'B'
$dbHost = 'localhost';
$dbUser = 'grcadmin';
$dbPass = 'GRCAdmin123/';
$dbName = 'electrox_primary';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Updating taxCode for taxID 514 to 'B'...\n";
    
    // Get all fiscal configs
    $configs = $pdo->query("SELECT id, branch_id, device_id, applicable_taxes FROM fiscal_config")->fetchAll(PDO::FETCH_ASSOC);
    
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
            $stmt = $pdo->prepare("UPDATE fiscal_config SET applicable_taxes = ? WHERE id = ?");
            $stmt->execute([json_encode($taxes), $config['id']]);
            $updated++;
        }
    }
    
    echo "\n✓ Updated $updated fiscal config(s)\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
