<?php
/**
 * Configure notification settings for cron jobs
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getPrimaryInstance();

echo "Configuring notification settings...\n\n";

// Set expiry notification email
$db->query("INSERT INTO settings (setting_key, value) VALUES ('expiry_notification_email', 'nyazengamd@gmail.com') ON DUPLICATE KEY UPDATE value = 'nyazengamd@gmail.com'");
echo "✓ Expiry notification email: nyazengamd@gmail.com\n";

// Enable expiry notifications
$db->query("INSERT INTO settings (setting_key, value) VALUES ('send_expiry_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
echo "✓ Expiry notifications: ENABLED\n";

// Set low stock notification email
$db->query("INSERT INTO settings (setting_key, value) VALUES ('low_stock_notification_email', 'nyazengamd@gmail.com') ON DUPLICATE KEY UPDATE value = 'nyazengamd@gmail.com'");
echo "✓ Low stock notification email: nyazengamd@gmail.com\n";

// Enable low stock notifications
$db->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
echo "✓ Low stock notifications: ENABLED\n";

echo "\n✅ Settings configured successfully!\n";

