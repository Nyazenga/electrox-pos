<?php
// Quick test of fiscal data
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'electrox_primary';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check devices
    $devices = $pdo->query("SELECT COUNT(*) FROM fiscal_devices")->fetchColumn();
    echo "Fiscal Devices: $devices\n";
    
    // Check configs
    $configs = $pdo->query("SELECT COUNT(*) FROM fiscal_config")->fetchColumn();
    echo "Fiscal Configs: $configs\n";
    
    // Check taxes
    $config = $pdo->query("SELECT applicable_taxes FROM fiscal_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($config) {
        $taxes = json_decode($config['applicable_taxes'] ?? '[]', true);
        echo "Taxes in first config: " . count($taxes) . "\n";
        if (!empty($taxes)) {
            foreach ($taxes as $tax) {
                echo "  - {$tax['taxName']} ({$tax['taxPercent']}%)\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
