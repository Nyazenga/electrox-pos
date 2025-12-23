<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

$db = Database::getInstance();

echo "=== Checking System Settings for Currencies ===\n\n";

// Get all settings
$allSettings = $db->getRows("SELECT setting_key, setting_type, LEFT(setting_value, 500) as setting_value_preview FROM system_settings ORDER BY setting_key");

echo "All system_settings entries:\n";
foreach ($allSettings as $setting) {
    echo "  {$setting['setting_key']} ({$setting['setting_type']}): " . substr($setting['setting_value_preview'], 0, 100) . "\n";
}

// Check specifically for currencies key
echo "\n=== Checking for 'currencies' key ===\n";
$currenciesSetting = $db->getRow("SELECT * FROM system_settings WHERE setting_key = 'currencies'");
if ($currenciesSetting) {
    echo "✓ Found 'currencies' setting\n";
    echo "  Type: {$currenciesSetting['setting_type']}\n";
    echo "  Value length: " . strlen($currenciesSetting['setting_value']) . " chars\n";
    if ($currenciesSetting['setting_type'] === 'json') {
        $currencies = json_decode($currenciesSetting['setting_value'], true);
        if (is_array($currencies)) {
            echo "  Contains " . count($currencies) . " currencies\n";
            if (!empty($currencies)) {
                echo "  First currency: " . json_encode($currencies[0]) . "\n";
            }
        }
    } else {
        echo "  Value: " . substr($currenciesSetting['setting_value'], 0, 200) . "\n";
    }
} else {
    echo "✗ No 'currencies' key found in system_settings\n";
    echo "  Currencies might be stored with a different key or need to be created\n";
}

