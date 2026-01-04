<?php
// Force run low stock notifications with full debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';
require_once APP_PATH . '/includes/mailer.php';

$email = 'nyazengamd@gmail.com';
$primaryDb = Database::getPrimaryInstance();
$db = Database::getInstance();

echo "=== FORCE RUN LOW STOCK NOTIFICATIONS ===\n\n";

// Step 1: Configure settings
echo "1. Configuring settings...\n";
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('low_stock_notification_email', ?) ON DUPLICATE KEY UPDATE value = ?", [$email, $email]);
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");
echo "   ✓ Settings configured\n\n";

// Step 2: Verify settings
echo "2. Verifying settings...\n";
$sendNotifications = getSetting('send_low_stock_notifications', '0') == '1';
$notificationEmail = getSetting('low_stock_notification_email', '');
echo "   send_low_stock_notifications: " . ($sendNotifications ? '1' : '0') . "\n";
echo "   low_stock_notification_email: $notificationEmail\n\n";

if (!$sendNotifications || empty($notificationEmail)) {
    echo "ERROR: Notifications disabled or no email configured!\n";
    exit(1);
}

// Step 3: Get products
echo "3. Getting low stock products...\n";
$branches = $primaryDb->getRows("SELECT * FROM branches WHERE status = 'Active'");
if ($branches === false) $branches = [];

$lowStockProducts = [];

foreach ($branches as $branch) {
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
                               AND (p.branch_id = :branch_id OR :branch_id IS NULL)
                               ORDER BY p.quantity_in_stock ASC, p.product_name ASC", [
        ':branch_id' => $branch['id']
    ]);
    
    if ($products !== false && !empty($products)) {
        foreach ($products as $product) {
            $lowStockProducts[] = $product;
        }
    }
}

$productCount = count($lowStockProducts);
echo "   Found $productCount products with low stock\n";

if (empty($lowStockProducts)) {
    echo "   No products found - exiting\n";
    exit(0);
}

// Step 4: Build email message
echo "4. Building email message...\n";
$subject = "Low Stock Alert - " . date('Y-m-d');
$message = "Low Stock Level Notification\n\n";
$message .= "The following products are at or below their reorder levels:\n\n";

$currentBranch = null;
foreach ($lowStockProducts as $product) {
    if ($currentBranch !== $product['branch_name']) {
        $currentBranch = $product['branch_name'];
        $message .= "\n=== " . ($currentBranch ?: 'All Branches') . " ===\n";
    }
    
    $message .= sprintf("Product: %s\n", $product['display_name']);
    $message .= sprintf("  Code: %s\n", $product['product_code'] ?: 'N/A');
    $message .= sprintf("  Current Stock: %s\n", $product['quantity_in_stock']);
    $message .= sprintf("  Reorder Level: %s\n", $product['reorder_level']);
    $message .= sprintf("  Category: %s\n", $product['category_name'] ?: 'Uncategorized');
    $message .= "\n";
}

$message .= "\nPlease review and restock these items as needed.\n";
$message .= "\nGenerated: " . date('Y-m-d H:i:s');

echo "   Message built (length: " . strlen($message) . " chars)\n\n";

// Step 5: Send email
echo "5. Sending email to: $notificationEmail\n";
try {
    $mailer = new Mailer();
    echo "   Mailer object created\n";
    
    $mailSent = $mailer->send($notificationEmail, $subject, $message, false);
    
    if ($mailSent) {
        echo "   ✓ EMAIL SENT SUCCESSFULLY!\n";
        error_log("Low stock notification sent successfully to: $notificationEmail");
    } else {
        $mailerError = $mailer->getMailer()->ErrorInfo;
        echo "   ✗ FAILED TO SEND EMAIL\n";
        echo "   Error: $mailerError\n";
        error_log("Failed to send low stock notification to: $notificationEmail - Error: $mailerError");
    }
} catch (Exception $e) {
    echo "   ✗ EXCEPTION: " . $e->getMessage() . "\n";
    error_log("Error sending low stock notification: " . $e->getMessage());
}

echo "\n=== COMPLETE ===\n";

