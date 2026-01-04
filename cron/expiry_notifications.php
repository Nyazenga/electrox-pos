<?php
/**
 * Product Expiry Date Notification Cron Job
 * Run daily at 7:00 AM
 * Sends email notifications for products expiring within 3 months
 */

// Set script execution time limit
set_time_limit(300); // 5 minutes

// Define APP_PATH before requiring config (same as fiscal day cron jobs)
define('APP_PATH', dirname(dirname(__FILE__)));

require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/mailer.php';

// Email recipient - use same as fiscal day cron jobs
$emailRecipient = 'nyazengamd@gmail.com';

try {
    // Connect directly to primary database (same pattern as fiscal day cron jobs)
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Calculate date 3 months from now
    $threeMonthsFromNow = date('Y-m-d', strtotime('+3 months'));
    $today = date('Y-m-d');
    
    // Get products expiring within 3 months
    $stmt = $pdo->prepare("SELECT p.*, 
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
                           ORDER BY p.expiry_date ASC, p.product_name ASC");
    
    $stmt->execute([
        ':today' => $today,
        ':three_months' => $threeMonthsFromNow
    ]);
    
    $products = $stmt->fetchAll();
    
    if (empty($products)) {
        error_log("EXPIRY NOTIFICATION CRON: No expiring products found");
        exit(0);
    }
    
    error_log("EXPIRY NOTIFICATION CRON: Found " . count($products) . " products expiring within 3 months");
    
    // Prepare email content - use HTML format like fiscal day cron jobs
    $subject = "Product Expiry Alert - " . date('Y-m-d');
    
    // Build HTML email body
    $body = "<html><body>";
    $body .= "<h2>Product Expiry Date Notification</h2>";
    $body .= "<p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>";
    $body .= "<p>The following " . count($products) . " product(s) are expiring within 3 months:</p>";
    $body .= "<hr>";
    
    $currentBranch = null;
    foreach ($products as $product) {
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
            echo "EXPIRY NOTIFICATION CRON: Email sent successfully to {$emailRecipient}\n";
        } else {
            $mailerError = $mailer->getMailer()->ErrorInfo;
            error_log("EXPIRY NOTIFICATION CRON: Failed to send email to {$emailRecipient} - Error: $mailerError");
            echo "EXPIRY NOTIFICATION CRON: Failed to send email - Error: $mailerError\n";
        }
    } catch (Exception $e) {
        error_log("EXPIRY NOTIFICATION CRON: Exception sending email: " . $e->getMessage());
        echo "EXPIRY NOTIFICATION CRON: Exception - " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    error_log("EXPIRY NOTIFICATION CRON: Error: " . $e->getMessage());
    echo "EXPIRY NOTIFICATION CRON: Error - " . $e->getMessage() . "\n";
}

exit(0);
