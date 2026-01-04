<?php
/**
 * Simple email test
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/mailer.php';

$testEmail = 'nyazengamd@gmail.com';

echo "Testing email...\n";

try {
    $mailer = new Mailer();
    $result = $mailer->send($testEmail, 'Cron Test Email - ' . date('Y-m-d H:i:s'), 'This is a simple test email from the cron system.', false);
    echo "Email sent successfully!\n";
    echo "Check $testEmail for the test email.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

