<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

$db = Database::getInstance();

echo "=== Checking Currency Storage ===\n\n";

// Check system_settings for currency-related keys
$currencySettings = $db->getRows(
    "SELECT setting_key, setting_type, setting_value FROM system_settings WHERE setting_key LIKE '%currency%' OR setting_type = 'json'"
);

echo "Currency-related settings in system_settings:\n";
if (empty($currencySettings)) {
    echo "  No currency settings found\n";
} else {
    foreach ($currencySettings as $setting) {
        echo "  Key: {$setting['setting_key']}, Type: {$setting['setting_type']}\n";
        if ($setting['setting_type'] === 'json') {
            $value = json_decode($setting['setting_value'], true);
            if (is_array($value)) {
                echo "    Value (first 200 chars): " . substr($setting['setting_value'], 0, 200) . "\n";
            }
        } else {
            echo "    Value: " . substr($setting['setting_value'], 0, 100) . "\n";
        }
    }
}

// Check if currencies table exists
echo "\n=== Checking for currencies table ===\n";
try {
    $currencies = $db->getRows("SELECT COUNT(*) as count FROM currencies");
    if ($currencies !== false) {
        echo "  ✓ currencies table EXISTS\n";
        $count = $db->getRow("SELECT COUNT(*) as count FROM currencies");
        echo "  Count: " . ($count['count'] ?? 0) . "\n";
    }
} catch (Exception $e) {
    echo "  ✗ currencies table does NOT exist: " . $e->getMessage() . "\n";
}

// Check all JSON settings
echo "\n=== All JSON settings ===\n";
$jsonSettings = $db->getRows("SELECT setting_key, setting_type FROM system_settings WHERE setting_type = 'json'");
if (empty($jsonSettings)) {
    echo "  No JSON settings found\n";
} else {
    foreach ($jsonSettings as $setting) {
        echo "  Key: {$setting['setting_key']}\n";
    }
}

