<?php
/**
 * Test all cron jobs and send test emails
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/mailer.php';

$testEmail = 'nyazengamd@gmail.com';

echo "Testing Cron Jobs and Email Functionality...\n\n";

// Test 1: Configure settings
echo "1. Configuring notification settings...\n";
$db = Database::getPrimaryInstance();
$db->query("INSERT INTO settings (setting_key, value) VALUES ('expiry_notification_email', '$testEmail') ON DUPLICATE KEY UPDATE value = '$testEmail'");
$db->query("INSERT INTO settings (setting_key, value) VALUES ('send_expiry_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
$db->query("INSERT INTO settings (setting_key, value) VALUES ('low_stock_notification_email', '$testEmail') ON DUPLICATE KEY UPDATE value = '$testEmail'");
$db->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
echo "   ✓ Settings configured\n\n";

// Test 2: Send test email
echo "2. Testing email sending...\n";
try {
    $mailer = new Mailer();
    $mailer->send($testEmail, 'Cron Job Test - ' . date('Y-m-d H:i:s'), 'This is a test email to verify cron job email functionality is working.', false);
    echo "   ✓ Test email sent to $testEmail\n\n";
} catch (Exception $e) {
    echo "   ✗ Email error: " . $e->getMessage() . "\n\n";
}

// Test 3: Close Fiscal Day
echo "3. Testing Close Fiscal Day cron...\n";
echo "   Running close_fiscal_day.php...\n";
ob_start();
try {
    include __DIR__ . '/close_fiscal_day.php';
    $output = ob_get_clean();
    echo "   ✓ Close Fiscal Day executed (check email for results)\n\n";
} catch (Exception $e) {
    ob_end_clean();
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 4: Open Fiscal Day
echo "4. Testing Open Fiscal Day cron...\n";
echo "   Running open_fiscal_day.php...\n";
ob_start();
try {
    include __DIR__ . '/open_fiscal_day.php';
    $output = ob_get_clean();
    echo "   ✓ Open Fiscal Day executed (check email for results)\n\n";
} catch (Exception $e) {
    ob_end_clean();
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 5: Expiry Notifications
echo "5. Testing Expiry Notifications cron...\n";
echo "   Running expiry_notifications.php...\n";
ob_start();
try {
    include __DIR__ . '/expiry_notifications.php';
    $output = ob_get_clean();
    echo "   ✓ Expiry Notifications executed (check email for results)\n\n";
} catch (Exception $e) {
    ob_end_clean();
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 6: Low Stock Notifications
echo "6. Testing Low Stock Notifications cron...\n";
echo "   Running low_stock_notifications.php...\n";
ob_start();
try {
    include __DIR__ . '/low_stock_notifications.php';
    $output = ob_get_clean();
    echo "   ✓ Low Stock Notifications executed (check email for results)\n\n";
} catch (Exception $e) {
    ob_end_clean();
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

echo "✅ All cron jobs tested!\n";
echo "\nPlease check your email ($testEmail) for test results.\n";

