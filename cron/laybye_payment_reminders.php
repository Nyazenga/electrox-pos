<?php
/**
 * Laybye Payment Reminder Cron Job
 * Run monthly (e.g., 1st of each month at 9:00 AM)
 * Sends email reminders to customers with upcoming or overdue laybye payments
 */

// Set script execution time limit
set_time_limit(300); // 5 minutes

// Define APP_PATH before requiring config
define('APP_PATH', dirname(dirname(__FILE__)));

require_once APP_PATH . '/config.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/includes/mailer.php';

// Email recipient - use same as other cron jobs
$emailRecipient = 'nyazengamd@gmail.com';

try {
    // Connect directly to primary database
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . PRIMARY_DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    $today = date('Y-m-d');
    $nextWeek = date('Y-m-d', strtotime('+7 days'));
    
    // Get laybyes with upcoming or overdue payments
    $stmt = $pdo->prepare("SELECT 
        l.*,
        c.first_name,
        c.last_name,
        c.email,
        c.phone,
        b.branch_name,
        COUNT(CASE WHEN lps.is_paid = 0 AND lps.scheduled_date <= :next_week THEN 1 END) as upcoming_payments,
        SUM(CASE WHEN lps.is_paid = 0 AND lps.scheduled_date < :today THEN lps.scheduled_amount - lps.paid_amount ELSE 0 END) as overdue_amount
    FROM laybyes l
    LEFT JOIN customers c ON l.customer_id = c.id
    LEFT JOIN branches b ON l.branch_id = b.id
    LEFT JOIN laybye_payment_schedule lps ON l.id = lps.laybye_id
    WHERE l.status IN ('pending', 'in_progress')
    AND l.amount_remaining > 0
    AND lps.is_paid = 0
    AND lps.scheduled_date <= :next_week
    AND (lps.reminder_sent = 0 OR lps.reminder_sent_at < DATE_SUB(:today, INTERVAL 30 DAY))
    GROUP BY l.id
    HAVING upcoming_payments > 0 OR overdue_amount > 0
    ORDER BY lps.scheduled_date ASC");
    
    $stmt->execute([
        ':today' => $today,
        ':next_week' => $nextWeek
    ]);
    
    $laybyes = $stmt->fetchAll();
    
    if (empty($laybyes)) {
        error_log("LAYBYE REMINDER CRON: No laybyes with upcoming or overdue payments found");
        exit(0);
    }
    
    error_log("LAYBYE REMINDER CRON: Found " . count($laybyes) . " laybyes requiring payment reminders");
    
    $mailer = new Mailer();
    $remindersSent = 0;
    $remindersFailed = 0;
    
    foreach ($laybyes as $laybye) {
        // Get payment schedule entries
        $scheduleStmt = $pdo->prepare("SELECT * FROM laybye_payment_schedule 
            WHERE laybye_id = :laybye_id 
            AND is_paid = 0 
            AND scheduled_date <= :next_week
            ORDER BY scheduled_date ASC");
        $scheduleStmt->execute([
            ':laybye_id' => $laybye['id'],
            ':next_week' => $nextWeek
        ]);
        $scheduleEntries = $scheduleStmt->fetchAll();
        
        if (empty($scheduleEntries)) {
            continue;
        }
        
        // Build email content
        $customerName = trim(($laybye['first_name'] ?? '') . ' ' . ($laybye['last_name'] ?? ''));
        $customerEmail = $laybye['email'] ?? null;
        
        if (!$customerEmail) {
            error_log("LAYBYE REMINDER CRON: No email for customer ID {$laybye['customer_id']}, laybye {$laybye['laybye_number']}");
            continue;
        }
        
        $subject = "Laybye Payment Reminder - " . $laybye['laybye_number'];
        
        $htmlContent = "<html><body>";
        $htmlContent .= "<h2>Laybye Payment Reminder</h2>";
        $htmlContent .= "<p>Dear " . htmlspecialchars($customerName) . ",</p>";
        $htmlContent .= "<p>This is a reminder regarding your laybye: <strong>" . htmlspecialchars($laybye['laybye_number']) . "</strong></p>";
        $htmlContent .= "<p><strong>Total Amount:</strong> " . number_format($laybye['total_amount'], 2) . "<br>";
        $htmlContent .= "<strong>Amount Paid:</strong> " . number_format($laybye['amount_paid'], 2) . "<br>";
        $htmlContent .= "<strong>Amount Remaining:</strong> " . number_format($laybye['amount_remaining'], 2) . "</p>";
        
        $htmlContent .= "<h3>Upcoming Payments:</h3>";
        $htmlContent .= "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
        $htmlContent .= "<tr><th>Due Date</th><th>Amount</th><th>Status</th></tr>";
        
        foreach ($scheduleEntries as $entry) {
            $dueDate = date('M d, Y', strtotime($entry['scheduled_date']));
            $amount = number_format($entry['scheduled_amount'] - $entry['paid_amount'], 2);
            $isOverdue = $entry['scheduled_date'] < $today;
            $status = $isOverdue ? '<span style="color: red;">OVERDUE</span>' : 'Due Soon';
            
            $htmlContent .= "<tr>";
            $htmlContent .= "<td>" . $dueDate . "</td>";
            $htmlContent .= "<td>" . $amount . "</td>";
            $htmlContent .= "<td>" . $status . "</td>";
            $htmlContent .= "</tr>";
        }
        
        $htmlContent .= "</table>";
        $htmlContent .= "<p>Please visit " . htmlspecialchars($laybye['branch_name']) . " to make your payment.</p>";
        $htmlContent .= "<p>Thank you for your business!</p>";
        $htmlContent .= "</body></html>";
        
        try {
            $mailer->send($customerEmail, $subject, $htmlContent, true);
            
            // Mark reminders as sent
            foreach ($scheduleEntries as $entry) {
                $updateStmt = $pdo->prepare("UPDATE laybye_payment_schedule 
                    SET reminder_sent = 1, reminder_sent_at = NOW() 
                    WHERE id = :id");
                $updateStmt->execute([':id' => $entry['id']]);
            }
            
            $remindersSent++;
            error_log("LAYBYE REMINDER CRON: Sent reminder for laybye {$laybye['laybye_number']} to {$customerEmail}");
            
        } catch (Exception $e) {
            $remindersFailed++;
            error_log("LAYBYE REMINDER CRON: Failed to send reminder for laybye {$laybye['laybye_number']}: " . $e->getMessage());
        }
    }
    
    // Send summary email to admin
    if ($remindersSent > 0 || $remindersFailed > 0) {
        $summarySubject = "Laybye Payment Reminders - " . date('Y-m-d');
        $summaryContent = "<html><body>";
        $summaryContent .= "<h2>Laybye Payment Reminder Summary</h2>";
        $summaryContent .= "<p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>";
        $summaryContent .= "<p><strong>Reminders Sent:</strong> {$remindersSent}</p>";
        $summaryContent .= "<p><strong>Reminders Failed:</strong> {$remindersFailed}</p>";
        $summaryContent .= "</body></html>";
        
        try {
            $mailer->send($emailRecipient, $summarySubject, $summaryContent, true);
        } catch (Exception $e) {
            error_log("LAYBYE REMINDER CRON: Failed to send summary email: " . $e->getMessage());
        }
    }
    
    error_log("LAYBYE REMINDER CRON: Completed. Sent: {$remindersSent}, Failed: {$remindersFailed}");
    
} catch (Exception $e) {
    error_log("LAYBYE REMINDER CRON ERROR: " . $e->getMessage());
    exit(1);
}
