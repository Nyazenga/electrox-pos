<?php
// Debug and run notification cron jobs
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';

$email = 'nyazengamd@gmail.com';
$primaryDb = Database::getPrimaryInstance();
$db = Database::getInstance();

echo "=== Debugging Notification Cron Jobs ===\n\n";

// Step 1: Configure settings
echo "1. Configuring settings...\n";
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('expiry_notification_email', ?) ON DUPLICATE KEY UPDATE value = ?", [$email, $email]);
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_expiry_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('low_stock_notification_email', ?) ON DUPLICATE KEY UPDATE value = ?", [$email, $email]);
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
echo "   ✓ Settings configured\n\n";

// Step 2: Verify settings
echo "2. Verifying settings...\n";
$sendExpiry = getSetting('send_expiry_notifications', '0');
$expiryEmail = getSetting('expiry_notification_email', '');
$sendLowStock = getSetting('send_low_stock_notifications', '0');
$lowStockEmail = getSetting('low_stock_notification_email', '');

echo "   send_expiry_notifications: $sendExpiry\n";
echo "   expiry_notification_email: $expiryEmail\n";
echo "   send_low_stock_notifications: $sendLowStock\n";
echo "   low_stock_notification_email: $lowStockEmail\n\n";

// Step 3: Check products
echo "3. Checking products...\n";
$lowStockProducts = $db->getRows("SELECT p.*, 
    COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as display_name,
    pc.name as category_name,
    b.branch_name
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    LEFT JOIN branches b ON p.branch_id = b.id
    WHERE p.status = 'Active' 
    AND p.quantity_in_stock <= p.reorder_level
    AND p.reorder_level > 0
    ORDER BY p.quantity_in_stock ASC, p.product_name ASC");

$lowStockCount = count($lowStockProducts);
echo "   Products with low stock: $lowStockCount\n";
if ($lowStockCount > 0) {
    echo "   First 5 products:\n";
    foreach (array_slice($lowStockProducts, 0, 5) as $p) {
        echo "     - {$p['display_name']}: Stock={$p['quantity_in_stock']}, Reorder={$p['reorder_level']}\n";
    }
}
echo "\n";

$threeMonthsFromNow = date('Y-m-d', strtotime('+3 months'));
$today = date('Y-m-d');
$expiringProducts = $db->getRows("SELECT p.*, 
    COALESCE(p.product_name, CONCAT(p.brand, ' ', p.model)) as display_name,
    pc.name as category_name,
    b.branch_name,
    DATEDIFF(p.expiry_date, CURDATE()) as days_until_expiry
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    LEFT JOIN branches b ON p.branch_id = b.id
    WHERE p.status = 'Active' 
    AND p.expiry_date IS NOT NULL
    AND p.expiry_date >= :today
    AND p.expiry_date <= :three_months", [
    ':today' => $today,
    ':three_months' => $threeMonthsFromNow
]);

$expiringCount = count($expiringProducts);
echo "   Products expiring within 3 months: $expiringCount\n\n";

// Step 4: Test Mailer
echo "4. Testing Mailer class...\n";
require_once APP_PATH . '/includes/mailer.php';
try {
    $mailer = new Mailer();
    echo "   ✓ Mailer class loaded successfully\n";
} catch (Exception $e) {
    echo "   ✗ Error loading Mailer: " . $e->getMessage() . "\n";
}
echo "\n";

// Step 5: Run low stock notifications
echo "5. Running low stock notifications cron...\n";
if ($sendLowStock == '1' && !empty($lowStockEmail) && $lowStockCount > 0) {
    echo "   Conditions met - should send email\n";
    ob_start();
    include APP_PATH . '/cron/low_stock_notifications.php';
    $output = ob_get_clean();
    if (!empty($output)) {
        echo "   Output: $output\n";
    }
    echo "   ✓ Low stock cron executed\n";
} else {
    echo "   ✗ Conditions not met:\n";
    echo "     - send_low_stock_notifications: $sendLowStock\n";
    echo "     - low_stock_notification_email: " . ($lowStockEmail ?: 'EMPTY') . "\n";
    echo "     - Products found: $lowStockCount\n";
}
echo "\n";

// Step 6: Run expiry notifications
echo "6. Running expiry notifications cron...\n";
if ($sendExpiry == '1' && !empty($expiryEmail) && $expiringCount > 0) {
    echo "   Conditions met - should send email\n";
    ob_start();
    include APP_PATH . '/cron/expiry_notifications.php';
    $output = ob_get_clean();
    if (!empty($output)) {
        echo "   Output: $output\n";
    }
    echo "   ✓ Expiry cron executed\n";
} else {
    echo "   ✗ Conditions not met:\n";
    echo "     - send_expiry_notifications: $sendExpiry\n";
    echo "     - expiry_notification_email: " . ($expiryEmail ?: 'EMPTY') . "\n";
    echo "     - Products found: $expiringCount\n";
}
echo "\n";

echo "=== Debug Complete ===\n";
echo "Check email: $email\n";
echo "Check error logs for any issues.\n";

