<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$email = 'nyazengamd@gmail.com';
$primaryDb = Database::getPrimaryInstance();
$db = Database::getInstance();

echo "=== Notification Cron Test ===\n\n";

// Configure settings
echo "1. Configuring settings...\n";
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('expiry_notification_email', ?) ON DUPLICATE KEY UPDATE value = ?", [$email, $email]);
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_expiry_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('low_stock_notification_email', ?) ON DUPLICATE KEY UPDATE value = ?", [$email, $email]);
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
echo "   Settings configured.\n\n";

// Verify settings
echo "2. Verifying settings...\n";
$settings = $primaryDb->getRows("SELECT setting_key, value FROM settings WHERE setting_key LIKE '%notification%' ORDER BY setting_key");
foreach ($settings as $setting) {
    echo "   {$setting['setting_key']}: {$setting['value']}\n";
}
echo "\n";

// Check products
echo "3. Checking products...\n";
$expiring = $db->getOne("SELECT COUNT(*) FROM products WHERE status = 'Active' AND expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)");
$lowStock = $db->getOne("SELECT COUNT(*) FROM products WHERE status = 'Active' AND quantity_in_stock <= reorder_level AND reorder_level > 0");
echo "   Products expiring within 3 months: $expiring\n";
echo "   Products with low stock: $lowStock\n\n";

// Run cron jobs
echo "4. Running expiry notifications cron...\n";
include APP_PATH . '/cron/expiry_notifications.php';
echo "   Done.\n\n";

echo "5. Running low stock notifications cron...\n";
include APP_PATH . '/cron/low_stock_notifications.php';
echo "   Done.\n\n";

echo "=== Test Complete ===\n";
echo "If products were found, emails should have been sent to: $email\n";
echo "If no products were found, no emails will be sent (this is expected).\n";

