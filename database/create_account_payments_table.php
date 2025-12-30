<?php
/**
 * Create account_payments table in tenant database if it doesn't exist
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

try {
    $db = Database::getInstance();
    
    // Check if table exists
    $tableExists = $db->getRow("SHOW TABLES LIKE 'account_payments'");
    
    if (!$tableExists) {
        echo "Creating account_payments table in tenant database...\n";
        
        $db->executeQuery("CREATE TABLE IF NOT EXISTS account_payments (
            id INT(11) NOT NULL AUTO_INCREMENT,
            sale_id INT(11) NOT NULL,
            customer_id INT(11) NOT NULL,
            branch_id INT(11) DEFAULT NULL,
            payment_method VARCHAR(50) NOT NULL,
            currency_id INT(11) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT DEFAULT NULL,
            created_by INT(11) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sale_id (sale_id),
            KEY idx_customer_id (customer_id),
            KEY idx_branch_id (branch_id),
            KEY idx_payment_date (payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        echo "✓ Created account_payments table successfully\n";
    } else {
        echo "✓ account_payments table already exists\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}


