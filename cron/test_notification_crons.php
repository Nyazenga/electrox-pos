<?php
/**
 * Test script for expiry and low stock notification cron jobs
 * Configures settings and runs both cron jobs
 */

// Set time limit
set_time_limit(300);

// Include configuration
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';

$notificationEmail = 'nyazengamd@gmail.com';

echo "=== Testing Notification Cron Jobs ===\n\n";

// Step 1: Configure notification settings
echo "1. Configuring notification settings...\n";
$primaryDb = Database::getPrimaryInstance();

// Set expiry notification email
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('expiry_notification_email', :email) ON DUPLICATE KEY UPDATE value = :email", [':email' => $notificationEmail]);
echo "   ✓ Expiry notification email: $notificationEmail\n";

// Enable expiry notifications
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_expiry_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
echo "   ✓ Expiry notifications: ENABLED\n";

// Set low stock notification email
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('low_stock_notification_email', :email) ON DUPLICATE KEY UPDATE value = :email", [':email' => $notificationEmail]);
echo "   ✓ Low stock notification email: $notificationEmail\n";

// Enable low stock notifications
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
echo "   ✓ Low stock notifications: ENABLED\n\n";

// Step 2: Verify settings
echo "2. Verifying settings...\n";
$expiryEnabled = getSetting('send_expiry_notifications', '0');
$expiryEmail = getSetting('expiry_notification_email', '');
$lowStockEnabled = getSetting('send_low_stock_notifications', '0');
$lowStockEmail = getSetting('low_stock_notification_email', '');

echo "   Expiry notifications: " . ($expiryEnabled == '1' ? 'ENABLED' : 'DISABLED') . "\n";
echo "   Expiry email: " . ($expiryEmail ?: 'NOT SET') . "\n";
echo "   Low stock notifications: " . ($lowStockEnabled == '1' ? 'ENABLED' : 'DISABLED') . "\n";
echo "   Low stock email: " . ($lowStockEmail ?: 'NOT SET') . "\n\n";

// Step 3: Run expiry notifications cron
echo "3. Running expiry notifications cron...\n";
ob_start();
include APP_PATH . '/cron/expiry_notifications.php';
$expiryOutput = ob_get_clean();
echo "   ✓ Expiry notifications cron executed\n\n";

// Step 4: Run low stock notifications cron
echo "4. Running low stock notifications cron...\n";
ob_start();
include APP_PATH . '/cron/low_stock_notifications.php';
$lowStockOutput = ob_get_clean();
echo "   ✓ Low stock notifications cron executed\n\n";

// Step 5: Check for products
echo "5. Checking for products...\n";
$db = Database::getInstance();
$primaryDb = Database::getPrimaryInstance();

// Check expiring products
$threeMonthsFromNow = date('Y-m-d', strtotime('+3 months'));
$today = date('Y-m-d');
$expiringCount = $db->getOne("SELECT COUNT(*) FROM products 
    WHERE status = 'Active' 
    AND expiry_date IS NOT NULL
    AND expiry_date >= :today
    AND expiry_date <= :three_months", [
    ':today' => $today,
    ':three_months' => $threeMonthsFromNow
]);
echo "   Products expiring within 3 months: $expiringCount\n";

// Check low stock products
$lowStockCount = $db->getOne("SELECT COUNT(*) FROM products 
    WHERE status = 'Active' 
    AND quantity_in_stock <= reorder_level
    AND reorder_level > 0");
echo "   Products with low stock: $lowStockCount\n\n";

echo "=== Test Complete ===\n";
echo "Please check your email ($notificationEmail) for notifications.\n";
echo "If products were found, you should receive emails.\n";
echo "If no products were found, no emails will be sent (this is expected behavior).\n";

