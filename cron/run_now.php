<?php
// Simple script to run low stock notifications NOW with full output
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "Starting low stock notification...\n";
flush();

define('APP_PATH', dirname(dirname(__FILE__)));

echo "APP_PATH defined: " . APP_PATH . "\n";
flush();

require_once APP_PATH . '/config.php';
echo "Config loaded\n";
flush();

require_once APP_PATH . '/includes/db.php';
echo "DB loaded\n";
flush();

require_once APP_PATH . '/includes/settings_functions.php';
echo "Settings functions loaded\n";
flush();

require_once APP_PATH . '/includes/mailer.php';
echo "Mailer loaded\n";
flush();

$emailRecipient = 'nyazengamd@gmail.com';

// Force enable
$primaryDb = Database::getPrimaryInstance();
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
echo "Settings configured\n";
flush();

// Get products
$db = Database::getInstance();
echo "Getting products...\n";
flush();

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
echo "Found $count products\n";
flush();

if (empty($products)) {
    echo "No products - exiting\n";
    exit(0);
}

// Build email
$subject = "Low Stock Alert - " . date('Y-m-d');
$body = "<html><body><h2>Low Stock Level Notification</h2><p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p><p>The following " . count($products) . " product(s) are at or below their reorder levels:</p><hr>";

foreach ($products as $product) {
    $body .= "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
    $body .= "<p><strong>Product:</strong> " . htmlspecialchars($product['display_name']) . "</p>";
    $body .= "<p><strong>Code:</strong> " . htmlspecialchars($product['product_code'] ?: 'N/A') . "</p>";
    $body .= "<p><strong>Current Stock:</strong> " . htmlspecialchars($product['quantity_in_stock']) . "</p>";
    $body .= "<p><strong>Reorder Level:</strong> " . htmlspecialchars($product['reorder_level']) . "</p>";
    $body .= "</div>";
}

$body .= "<hr><p>Please review and restock these items as needed.</p><p><em>Generated: " . date('Y-m-d H:i:s') . "</em></p></body></html>";

echo "Sending email to: $emailRecipient\n";
flush();

try {
    $mailer = new Mailer();
    echo "Mailer created\n";
    flush();
    
    $mailSent = $mailer->send($emailRecipient, $subject, $body, true);
    
    if ($mailSent) {
        echo "SUCCESS: Email sent!\n";
        error_log("LOW STOCK: Email sent to $emailRecipient");
    } else {
        $error = $mailer->getMailer()->ErrorInfo;
        echo "FAILED: $error\n";
        error_log("LOW STOCK: Failed - $error");
    }
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    error_log("LOW STOCK: Exception - " . $e->getMessage());
}

echo "Done\n";

