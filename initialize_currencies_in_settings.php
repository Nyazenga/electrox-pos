<?php
require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/session.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/functions.php';

initSession();
$_SESSION['tenant_name'] = 'primary';

$db = Database::getInstance();

echo "=== Initializing Currencies in system_settings ===\n\n";

// Check if currencies setting exists
$existing = $db->getRow("SELECT * FROM system_settings WHERE setting_key = 'currencies'");

if ($existing) {
    echo "✓ Currencies setting already exists\n";
    $currencies = json_decode($existing['setting_value'], true);
    if (is_array($currencies)) {
        echo "  Found " . count($currencies) . " currencies\n";
        foreach ($currencies as $c) {
            echo "    - {$c['code']}: {$c['name']} (Base: " . ($c['is_base'] ?? 0) . ")\n";
        }
    }
} else {
    echo "✗ Currencies setting not found. Creating default...\n";
    
    // Get default currency from settings
    $defaultCode = getSetting('default_currency', 'USD');
    
    // Create default currencies
    $defaultCurrencies = [
        [
            'id' => 1,
            'code' => $defaultCode,
            'name' => $defaultCode === 'USD' ? 'US Dollar' : ($defaultCode === 'ZWL' ? 'Zimbabwean Dollar' : $defaultCode),
            'symbol' => $defaultCode === 'USD' ? '$' : ($defaultCode === 'ZWL' ? 'ZWL' : $defaultCode),
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'is_base' => 1,
            'is_active' => 1,
            'exchange_rate' => 1.000000
        ]
    ];
    
    // Add common currencies if default is USD
    if ($defaultCode === 'USD') {
        $defaultCurrencies[] = [
            'id' => 2,
            'code' => 'ZWL',
            'name' => 'Zimbabwean Dollar',
            'symbol' => 'ZWL',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'is_base' => 0,
            'is_active' => 1,
            'exchange_rate' => 35.000000
        ];
    }
    
    $settingId = $db->insert('system_settings', [
        'setting_key' => 'currencies',
        'setting_value' => json_encode($defaultCurrencies),
        'setting_type' => 'json',
        'category' => 'Financial',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ]);
    
    if ($settingId) {
        echo "✓ Created currencies setting with " . count($defaultCurrencies) . " currencies\n";
    } else {
        echo "✗ Failed to create currencies setting: " . $db->getLastError() . "\n";
    }
}

