<?php
// Debug low stock notifications
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "=== DEBUGGING LOW STOCK NOTIFICATIONS ===\n\n";

// Define APP_PATH
define('APP_PATH', dirname(dirname(__FILE__)));

require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';
require_once APP_PATH . '/includes/mailer.php';

$emailRecipient = 'nyazengamd@gmail.com';

echo "1. Checking notification settings...\n";
$primaryDb = Database::getPrimaryInstance();
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
$sendNotifications = getSetting('send_low_stock_notifications', '0') == '1';
echo "   send_low_stock_notifications: " . ($sendNotifications ? '1 (ENABLED)' : '0 (DISABLED)') . "\n\n";

if (!$sendNotifications) {
    echo "ERROR: Notifications are disabled!\n";
    exit(1);
}

echo "2. Getting low stock products...\n";
$db = Database::getInstance();
$products = $db->getRows("SELECT p.*, 
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

$count = count($products);
echo "   Found $count products with low stock\n\n";

if (empty($products)) {
    echo "ERROR: No products found with low stock!\n";
    exit(1);
}

echo "3. Building email...\n";
$subject = "Low Stock Alert - " . date('Y-m-d');

$body = "<html><body>";
$body .= "<h2>Low Stock Level Notification</h2>";
$body .= "<p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>";
$body .= "<p>The following " . count($products) . " product(s) are at or below their reorder levels:</p>";
$body .= "<hr>";

$currentBranch = null;
foreach ($products as $product) {
    if ($currentBranch !== $product['branch_name']) {
        $currentBranch = $product['branch_name'];
        if ($currentBranch) {
            $body .= "<h3>" . htmlspecialchars($currentBranch) . "</h3>";
        }
    }
    
    $body .= "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
    $body .= "<p><strong>Product:</strong> " . htmlspecialchars($product['display_name']) . "</p>";
    $body .= "<p><strong>Code:</strong> " . htmlspecialchars($product['product_code'] ?: 'N/A') . "</p>";
    $body .= "<p><strong>Current Stock:</strong> " . htmlspecialchars($product['quantity_in_stock']) . "</p>";
    $body .= "<p><strong>Reorder Level:</strong> " . htmlspecialchars($product['reorder_level']) . "</p>";
    $body .= "<p><strong>Category:</strong> " . htmlspecialchars($product['category_name'] ?: 'Uncategorized') . "</p>";
    $body .= "</div>";
}

$body .= "<hr>";
$body .= "<p>Please review and restock these items as needed.</p>";
$body .= "<p><em>Generated: " . date('Y-m-d H:i:s') . "</em></p>";
$body .= "</body></html>";

echo "   Email body built (" . strlen($body) . " characters)\n\n";

echo "4. Sending email to: $emailRecipient\n";
try {
    $mailer = new Mailer();
    echo "   Mailer object created\n";
    
    $mailSent = $mailer->send($emailRecipient, $subject, $body, true);
    
    if ($mailSent) {
        echo "   ✓✓✓ EMAIL SENT SUCCESSFULLY! ✓✓✓\n";
        error_log("LOW STOCK DEBUG: Email sent successfully to {$emailRecipient}");
    } else {
        $error = $mailer->getMailer()->ErrorInfo;
        echo "   ✗✗✗ FAILED TO SEND EMAIL ✗✗✗\n";
        echo "   Error: $error\n";
        error_log("LOW STOCK DEBUG: Failed to send email - Error: $error");
    }
} catch (Exception $e) {
    echo "   ✗✗✗ EXCEPTION ✗✗✗\n";
    echo "   " . $e->getMessage() . "\n";
    echo "   Stack trace:\n" . $e->getTraceAsString() . "\n";
    error_log("LOW STOCK DEBUG: Exception - " . $e->getMessage());
}

echo "\n=== DEBUG COMPLETE ===\n";

