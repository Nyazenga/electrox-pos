<?php
/**
 * Product Expiry Date Notification Cron Job
 * Run daily at 7:00 AM
 * Sends email notifications for products expiring within 3 months
 */

// Set time limit for long-running script
set_time_limit(300);

// Include configuration
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';
require_once APP_PATH . '/includes/mailer.php';

// Get notification settings
$sendNotifications = getSetting('send_expiry_notifications', '0') == '1';
$notificationEmail = getSetting('expiry_notification_email', '');

if (!$sendNotifications || empty($notificationEmail)) {
    exit(0); // Notifications disabled or no email configured
}

try {
    $db = Database::getInstance();
    $primaryDb = Database::getPrimaryInstance();
    
    // Calculate date 3 months from now
    $threeMonthsFromNow = date('Y-m-d', strtotime('+3 months'));
    $today = date('Y-m-d');
    
    // Get all branches
    $branches = $primaryDb->getRows("SELECT * FROM branches WHERE status = 'Active'");
    if ($branches === false) $branches = [];
    
    $expiringProducts = [];
    
    // Check each branch
    foreach ($branches as $branch) {
        // Get products expiring within 3 months
        $products = $db->getRows("SELECT p.*, 
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
                                 AND p.expiry_date <= :three_months
                                 AND (p.branch_id = :branch_id OR :branch_id IS NULL)
                                 ORDER BY p.expiry_date ASC, p.product_name ASC", [
            ':today' => $today,
            ':three_months' => $threeMonthsFromNow,
            ':branch_id' => $branch['id']
        ]);
        
        if ($products !== false && !empty($products)) {
            foreach ($products as $product) {
                $expiringProducts[] = $product;
            }
        }
    }
    
    if (empty($expiringProducts)) {
        exit(0); // No expiring products
    }
    
    // Prepare email content
    $subject = "Product Expiry Alert - " . date('Y-m-d');
    $message = "Product Expiry Date Notification\n\n";
    $message .= "The following products are expiring within 3 months:\n\n";
    
    $currentBranch = null;
    foreach ($expiringProducts as $product) {
        if ($currentBranch !== $product['branch_name']) {
            $currentBranch = $product['branch_name'];
            $message .= "\n=== " . ($currentBranch ?: 'All Branches') . " ===\n";
        }
        
        $daysUntilExpiry = $product['days_until_expiry'];
        $urgency = '';
        if ($daysUntilExpiry <= 30) {
            $urgency = ' (URGENT - Expires in ' . $daysUntilExpiry . ' days)';
        } elseif ($daysUntilExpiry <= 60) {
            $urgency = ' (Expires in ' . $daysUntilExpiry . ' days)';
        } else {
            $urgency = ' (Expires in ' . $daysUntilExpiry . ' days)';
        }
        
        $message .= sprintf(
            "Product: %s%s\n",
            $product['display_name'],
            $urgency
        );
        $message .= sprintf(
            "  Code: %s\n",
            $product['product_code'] ?: 'N/A'
        );
        $message .= sprintf(
            "  Expiry Date: %s\n",
            date('Y-m-d', strtotime($product['expiry_date']))
        );
        $message .= sprintf(
            "  Current Stock: %s\n",
            $product['quantity_in_stock']
        );
        $message .= sprintf(
            "  Category: %s\n",
            $product['category_name'] ?: 'Uncategorized'
        );
        $message .= "\n";
    }
    
    $message .= "\nPlease review and take appropriate action for these items.\n";
    $message .= "\nGenerated: " . date('Y-m-d H:i:s');
    
    // Send email using Mailer class
    try {
        $mailer = new Mailer();
        // Send as plain text (not HTML)
        $mailSent = $mailer->send($notificationEmail, $subject, $message, false);
        
        if ($mailSent) {
            error_log("Expiry notification sent successfully to: $notificationEmail");
            echo "Expiry notification sent successfully to: $notificationEmail\n";
        } else {
            $mailerError = $mailer->getMailer()->ErrorInfo;
            error_log("Failed to send expiry notification to: $notificationEmail - Error: $mailerError");
            echo "Failed to send expiry notification to: $notificationEmail - Error: $mailerError\n";
        }
    } catch (Exception $e) {
        error_log("Error sending expiry notification: " . $e->getMessage());
        echo "Error sending expiry notification: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    error_log("Expiry notification cron error: " . $e->getMessage());
}


