<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

$setting = $primaryDb->getRow("SELECT * FROM system_settings WHERE setting_key = 'prices_include_tax'");

if (!$setting) {
    // Insert if doesn't exist
    $primaryDb->insert('system_settings', [
        'setting_key' => 'prices_include_tax',
        'setting_value' => '1',
        'setting_type' => 'boolean',
        'description' => 'Prices entered include tax (show tax separately on receipts)'
    ]);
    echo "Created prices_include_tax setting with value 1\n";
} else {
    // Update to 1 if not already
    if ($setting['setting_value'] != '1') {
        $primaryDb->update('system_settings', ['setting_value' => '1'], ['setting_key' => 'prices_include_tax']);
        echo "Updated prices_include_tax setting to 1\n";
    } else {
        echo "prices_include_tax is already set to 1\n";
    }
}


