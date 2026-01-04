<?php
// Test notification cron jobs and output results
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$outputFile = '/tmp/notification_test_output.txt';
$output = fopen($outputFile, 'w');

$email = 'nyazengamd@gmail.com';
$primaryDb = Database::getPrimaryInstance();

fwrite($output, "=== Notification Cron Test ===\n\n");

// Configure settings
fwrite($output, "1. Configuring settings...\n");
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('expiry_notification_email', ?) ON DUPLICATE KEY UPDATE value = ?", [$email, $email]);
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_expiry_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('low_stock_notification_email', ?) ON DUPLICATE KEY UPDATE value = ?", [$email, $email]);
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
fwrite($output, "   Settings configured.\n\n");

// Verify settings
fwrite($output, "2. Verifying settings...\n");
$settings = $primaryDb->getRows("SELECT setting_key, value FROM settings WHERE setting_key LIKE '%notification%' ORDER BY setting_key");
foreach ($settings as $setting) {
    fwrite($output, "   {$setting['setting_key']}: {$setting['value']}\n");
}
fwrite($output, "\n");

// Run expiry notifications
fwrite($output, "3. Running expiry notifications cron...\n");
ob_start();
include APP_PATH . '/cron/expiry_notifications.php';
$expiryOutput = ob_get_clean();
fwrite($output, "   Expiry cron executed.\n\n");

// Run low stock notifications
fwrite($output, "4. Running low stock notifications cron...\n");
ob_start();
include APP_PATH . '/cron/low_stock_notifications.php';
$lowStockOutput = ob_get_clean();
fwrite($output, "   Low stock cron executed.\n\n");

fwrite($output, "=== Test Complete ===\n");
fwrite($output, "Check email: $email\n");
fclose($output);

echo "Test completed. Output saved to: $outputFile\n";

