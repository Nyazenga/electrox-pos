<?php
/**
 * Low Stock Level Notification Cron Job
 * Run daily at 7:00 AM
 * Sends email notifications for products with stock levels at or below reorder level
 */

// Set script execution time limit
set_time_limit(300); // 5 minutes

// Define APP_PATH before requiring config (same as fiscal day cron jobs)
define('APP_PATH', dirname(dirname(__FILE__)));

require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/mailer.php';

// Email recipient - use same as fiscal day cron jobs
$emailRecipient = 'nyazengamd@gmail.com';

// Force enable notifications
$primaryDb = Database::getPrimaryInstance();
$primaryDb->query("INSERT INTO settings (setting_key, value) VALUES ('send_low_stock_notifications', '1') ON DUPLICATE KEY UPDATE value = '1'");

try {
    // Get products with low stock from primary database
    $products = $primaryDb->getRows("SELECT p.*, 
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
    
    if ($products === false || empty($products)) {
        error_log("LOW STOCK NOTIFICATION CRON: No low stock products found");
        exit(0); // No low stock products
    }
    
    error_log("LOW STOCK NOTIFICATION CRON: Found " . count($products) . " products with low stock");
    
    // Prepare email content - use HTML format like fiscal day cron jobs
    $subject = "Low Stock Alert - " . date('Y-m-d');
    
    // Build HTML email body
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
    
    // Send email using Mailer class - use HTML format like fiscal day cron jobs
    try {
        $mailer = new Mailer();
        $mailSent = $mailer->send($emailRecipient, $subject, $body, true);
        
        if ($mailSent) {
            error_log("LOW STOCK NOTIFICATION CRON: Email sent successfully to {$emailRecipient}");
        } else {
            $mailerError = $mailer->getMailer()->ErrorInfo;
            error_log("LOW STOCK NOTIFICATION CRON: Failed to send email to {$emailRecipient} - Error: $mailerError");
        }
    } catch (Exception $e) {
        error_log("LOW STOCK NOTIFICATION CRON: Exception sending email: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("LOW STOCK NOTIFICATION CRON: Error: " . $e->getMessage());
}

exit(0);
