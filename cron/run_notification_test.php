<?php
// Simple script to configure and test notification cron jobs
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$email = 'nyazengamd@gmail.com';
$primaryDb = Database::getPrimaryInstance();

// Configure settings
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('expiry_notification_email', ?) ON DUPLICATE KEY UPDATE value = ?", [$email, $email]);
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_expiry_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('low_stock_notification_email', ?) ON DUPLICATE KEY UPDATE value = ?", [$email, $email]);
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");

echo "Settings configured. Running cron jobs...\n";

// Run expiry notifications
include APP_PATH . '/cron/expiry_notifications.php';
echo "Expiry notifications cron executed.\n";

// Run low stock notifications
include APP_PATH . '/cron/low_stock_notifications.php';
echo "Low stock notifications cron executed.\n";

echo "Done. Check email: $email\n";

