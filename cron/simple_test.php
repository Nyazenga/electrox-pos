<?php
set_time_limit(60);
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting test...\n";

require_once dirname(dirname(__FILE__)) . '/config.php';
echo "Config loaded\n";

require_once APP_PATH . '/includes/db.php';
echo "DB includes loaded\n";

require_once APP_PATH . '/includes/settings_functions.php';
echo "Settings functions loaded\n";

$primaryDb = Database::getPrimaryInstance();
echo "Primary DB instance created\n";

$email = 'nyazengamd@gmail.com';

// Configure
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('low_stock_notification_email', ?) ON DUPLICATE KEY UPDATE value = ?", [$email, $email]);
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
echo "Settings configured\n";

// Verify
require_once APP_PATH . '/includes/settings_functions.php';
$send = getSetting('send_low_stock_notifications', '0');
$email_setting = getSetting('low_stock_notification_email', '');
echo "send_low_stock_notifications: $send\n";
echo "low_stock_notification_email: $email_setting\n";

// Get products
$db = Database::getInstance();
$count = $db->getOne("SELECT COUNT(*) FROM products WHERE status = 'Active' AND quantity_in_stock <= reorder_level AND reorder_level > 0");
echo "Low stock products: $count\n";

if ($count > 0) {
    echo "Products found - running cron...\n";
    include APP_PATH . '/cron/low_stock_notifications.php';
    echo "Cron executed\n";
} else {
    echo "No products found\n";
}

echo "Done\n";

