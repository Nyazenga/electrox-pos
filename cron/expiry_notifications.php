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

// Email recipient - use same as fiscal day cron jobs
$emailRecipient = 'nyazengamd@gmail.com';

// Get notification settings
$sendNotifications = getSetting('send_expiry_notifications', '0') == '1';

if (!$sendNotifications) {
    exit(0); // Notifications disabled
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
    
    // Prepare email content - use HTML format like fiscal day cron jobs
    $subject = "Product Expiry Alert - " . date('Y-m-d');
    
    // Build HTML email body
    $body = "<html><body>";
    $body .= "<h2>Product Expiry Date Notification</h2>";
    $body .= "<p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>";
    $body .= "<p>The following " . count($expiringProducts) . " product(s) are expiring within 3 months:</p>";
    $body .= "<hr>";
    
    $currentBranch = null;
    foreach ($expiringProducts as $product) {
        if ($currentBranch !== $product['branch_name']) {
            $currentBranch = $product['branch_name'];
            if ($currentBranch) {
                $body .= "<h3>" . htmlspecialchars($currentBranch) . "</h3>";
            }
        }
        
        $daysUntilExpiry = $product['days_until_expiry'];
        $urgencyColor = '#000000';
        $urgencyText = '';
        if ($daysUntilExpiry <= 30) {
            $urgencyColor = '#ff0000';
            $urgencyText = ' (URGENT - Expires in ' . $daysUntilExpiry . ' days)';
        } elseif ($daysUntilExpiry <= 60) {
            $urgencyColor = '#ff8800';
            $urgencyText = ' (Expires in ' . $daysUntilExpiry . ' days)';
        } else {
            $urgencyText = ' (Expires in ' . $daysUntilExpiry . ' days)';
        }
        
        $body .= "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
        $body .= "<p><strong>Product:</strong> " . htmlspecialchars($product['display_name']) . " <span style='color: {$urgencyColor}; font-weight: bold;'>" . htmlspecialchars($urgencyText) . "</span></p>";
        $body .= "<p><strong>Code:</strong> " . htmlspecialchars($product['product_code'] ?: 'N/A') . "</p>";
        $body .= "<p><strong>Expiry Date:</strong> " . htmlspecialchars(date('Y-m-d', strtotime($product['expiry_date']))) . "</p>";
        $body .= "<p><strong>Current Stock:</strong> " . htmlspecialchars($product['quantity_in_stock']) . "</p>";
        $body .= "<p><strong>Category:</strong> " . htmlspecialchars($product['category_name'] ?: 'Uncategorized') . "</p>";
        $body .= "</div>";
    }
    
    $body .= "<hr>";
    $body .= "<p>Please review and take appropriate action for these items.</p>";
    $body .= "<p><em>Generated: " . date('Y-m-d H:i:s') . "</em></p>";
    $body .= "</body></html>";
    
    // Send email using Mailer class - use HTML format like fiscal day cron jobs
    try {
        $mailer = new Mailer();
        $mailSent = $mailer->send($emailRecipient, $subject, $body, true);
        
        if ($mailSent) {
            error_log("EXPIRY NOTIFICATION CRON: Email sent successfully to {$emailRecipient}");
        } else {
            $mailerError = $mailer->getMailer()->ErrorInfo;
            error_log("EXPIRY NOTIFICATION CRON: Failed to send email to {$emailRecipient} - Error: $mailerError");
        }
    } catch (Exception $e) {
        error_log("EXPIRY NOTIFICATION CRON: Exception sending email: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Expiry notification cron error: " . $e->getMessage());
}


