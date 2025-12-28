<?php
/**
 * Low Stock Level Notification Cron Job
 * Run daily at 7:00 AM
 * Sends email notifications for products with stock levels at or below reorder level
 */

// Set time limit for long-running script
set_time_limit(300);

// Include configuration
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';

// Get notification settings
$sendNotifications = getSetting('send_low_stock_notifications', '0') == '1';
$notificationEmail = getSetting('low_stock_notification_email', '');

if (!$sendNotifications || empty($notificationEmail)) {
    exit(0); // Notifications disabled or no email configured
}

try {
    $db = Database::getInstance();
    $primaryDb = Database::getPrimaryInstance();
    
    // Get all branches
    $branches = $primaryDb->getRows("SELECT * FROM branches WHERE status = 'Active'");
    if ($branches === false) $branches = [];
    
    $lowStockProducts = [];
    
    // Check each branch
    foreach ($branches as $branch) {
        // Switch to branch database if multi-tenant, otherwise use same database
        $branchDb = $db; // For now, using same database
        
        // Get products with low stock
        $products = $branchDb->getRows("SELECT p.*, 
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
    
    if (empty($lowStockProducts)) {
        exit(0); // No low stock products
    }
    
    // Prepare email content
    $subject = "Low Stock Alert - " . date('Y-m-d');
    $message = "Low Stock Level Notification\n\n";
    $message .= "The following products are at or below their reorder levels:\n\n";
    
    $currentBranch = null;
    foreach ($lowStockProducts as $product) {
        if ($currentBranch !== $product['branch_name']) {
            $currentBranch = $product['branch_name'];
            $message .= "\n=== " . ($currentBranch ?: 'All Branches') . " ===\n";
        }
        
        $message .= sprintf(
            "Product: %s\n",
            $product['display_name']
        );
        $message .= sprintf(
            "  Code: %s\n",
            $product['product_code'] ?: 'N/A'
        );
        $message .= sprintf(
            "  Current Stock: %s\n",
            $product['quantity_in_stock']
        );
        $message .= sprintf(
            "  Reorder Level: %s\n",
            $product['reorder_level']
        );
        $message .= sprintf(
            "  Category: %s\n",
            $product['category_name'] ?: 'Uncategorized'
        );
        $message .= "\n";
    }
    
    $message .= "\nPlease review and restock these items as needed.\n";
    $message .= "\nGenerated: " . date('Y-m-d H:i:s');
    
    // Send email
    $headers = "From: " . APP_NAME . " <noreply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
    $headers .= "Reply-To: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    $mailSent = @mail($notificationEmail, $subject, $message, $headers);
    
    if ($mailSent) {
        error_log("Low stock notification sent successfully to: $notificationEmail");
    } else {
        error_log("Failed to send low stock notification to: $notificationEmail");
    }
    
} catch (Exception $e) {
    error_log("Low stock notification cron error: " . $e->getMessage());
}

