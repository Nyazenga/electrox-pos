<?php
require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

$primaryDb = Database::getPrimaryInstance();

try {
    $primaryDb->executeQuery("ALTER TABLE refund_payments ADD COLUMN currency_id INT(11) DEFAULT NULL AFTER amount");
    echo "Added currency_id column to refund_payments\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "currency_id column already exists\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}


