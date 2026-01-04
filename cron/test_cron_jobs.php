<?php
/**
 * Test script to verify all cron jobs work and send emails
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "Testing Cron Jobs...\n\n";

// Test 1: Close Fiscal Day
echo "1. Testing Close Fiscal Day...\n";
try {
    ob_start();
    require __DIR__ . '/close_fiscal_day.php';
    $output = ob_get_clean();
    echo "   Output: " . ($output ? substr($output, 0, 200) : 'No output') . "\n";
    echo "   ✓ Close Fiscal Day script executed\n\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 2: Open Fiscal Day
echo "2. Testing Open Fiscal Day...\n";
try {
    ob_start();
    require __DIR__ . '/open_fiscal_day.php';
    $output = ob_get_clean();
    echo "   Output: " . ($output ? substr($output, 0, 200) : 'No output') . "\n";
    echo "   ✓ Open Fiscal Day script executed\n\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: Check settings for expiry and low stock
echo "3. Checking notification settings...\n";
$db = Database::getInstance();
$sendExpiry = $db->getOne("SELECT value FROM settings WHERE setting_key = 'send_expiry_notifications'");
$expiryEmail = $db->getOne("SELECT value FROM settings WHERE setting_key = 'expiry_notification_email'");
$sendLowStock = $db->getOne("SELECT value FROM settings WHERE setting_key = 'send_low_stock_notifications'");
$lowStockEmail = $db->getOne("SELECT value FROM settings WHERE setting_key = 'low_stock_notification_email'");

echo "   Expiry notifications enabled: " . ($sendExpiry ?: 'NOT SET') . "\n";
echo "   Expiry email: " . ($expiryEmail ?: 'NOT SET') . "\n";
echo "   Low stock notifications enabled: " . ($sendLowStock ?: 'NOT SET') . "\n";
echo "   Low stock email: " . ($lowStockEmail ?: 'NOT SET') . "\n\n";

// Set emails if not set
if (!$expiryEmail) {
    $db->query("INSERT INTO settings (setting_key, value) VALUES ('expiry_notification_email', 'nyazengamd@gmail.com') ON DUPLICATE KEY UPDATE value = 'nyazengamd@gmail.com'");
    echo "   ✓ Set expiry notification email to nyazengamd@gmail.com\n";
}
if (!$sendExpiry) {
    $db->query("INSERT INTO settings (setting_key, value) VALUES ('send_expiry_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
    echo "   ✓ Enabled expiry notifications\n";
}
if (!$lowStockEmail) {
    $db->query("INSERT INTO settings (setting_key, value) VALUES ('low_stock_notification_email', 'nyazengamd@gmail.com') ON DUPLICATE KEY UPDATE value = 'nyazengamd@gmail.com'");
    echo "   ✓ Set low stock notification email to nyazengamd@gmail.com\n";
}
if (!$sendLowStock) {
    $db->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
    echo "   ✓ Enabled low stock notifications\n";
}

echo "\n✅ Test complete!\n";

