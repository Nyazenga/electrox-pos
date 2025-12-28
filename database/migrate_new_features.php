<?php
/**
 * Database Migration Script for New Features
 * Run this script to add all necessary tables and columns for the new features
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

// Use primary database for system tables
$primaryDb = Database::getPrimaryInstance();
$db = Database::getInstance();

echo "Starting database migration...\n";

try {
    // 1. Add credit_note_id to refunds table
    echo "1. Adding credit_note_id to refunds table...\n";
    try {
        $primaryDb->executeQuery("ALTER TABLE refunds ADD COLUMN credit_note_id INT(11) DEFAULT NULL AFTER refund_number");
        $primaryDb->executeQuery("ALTER TABLE refunds ADD COLUMN credit_note_number VARCHAR(50) DEFAULT NULL AFTER credit_note_id");
        $primaryDb->executeQuery("ALTER TABLE refunds ADD COLUMN fiscalized TINYINT(1) DEFAULT 0 AFTER status");
        $primaryDb->executeQuery("ALTER TABLE refunds ADD COLUMN fiscal_details TEXT DEFAULT NULL AFTER fiscalized");
        $primaryDb->executeQuery("ALTER TABLE refunds ADD INDEX idx_credit_note_id (credit_note_id)");
        echo "   ✓ Added credit_note fields to refunds\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            echo "   ⚠ Error: " . $e->getMessage() . "\n";
        } else {
            echo "   ✓ Columns already exist\n";
        }
    }

    // 2. Create price_change_history table
    echo "2. Creating price_change_history table...\n";
    $primaryDb->executeQuery("CREATE TABLE IF NOT EXISTS price_change_history (
        id INT(11) NOT NULL AUTO_INCREMENT,
        product_id INT(11) NOT NULL,
        branch_id INT(11) DEFAULT NULL,
        old_price DECIMAL(10,2) DEFAULT NULL,
        new_price DECIMAL(10,2) NOT NULL,
        price_type ENUM('cost_price', 'selling_price') DEFAULT 'selling_price',
        changed_by INT(11) NOT NULL,
        change_reason VARCHAR(255) DEFAULT NULL,
        changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_product_id (product_id),
        KEY idx_branch_id (branch_id),
        KEY idx_changed_at (changed_at),
        KEY idx_changed_by (changed_by),
        KEY idx_price_type (price_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   ✓ Created price_change_history table\n";
    
    // Add price_type column if table exists without it
    try {
        $primaryDb->executeQuery("ALTER TABLE price_change_history ADD COLUMN price_type ENUM('cost_price', 'selling_price') DEFAULT 'selling_price' AFTER new_price");
        $primaryDb->executeQuery("ALTER TABLE price_change_history ADD INDEX idx_price_type (price_type)");
        echo "   ✓ Added price_type column to price_change_history\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false && strpos($e->getMessage(), 'Duplicate key') === false) {
            echo "   ⚠ Error: " . $e->getMessage() . "\n";
        } else {
            echo "   ✓ price_type column already exists\n";
        }
    }

    // 3. Create payment_terms table
    echo "3. Creating payment_terms table...\n";
    $primaryDb->executeQuery("CREATE TABLE IF NOT EXISTS payment_terms (
        id INT(11) NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL,
        days INT(11) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   ✓ Created payment_terms table\n";

    // 4. Add credit_sale fields to sales table
    echo "4. Adding credit sale fields to sales table...\n";
    try {
        $primaryDb->executeQuery("ALTER TABLE sales ADD COLUMN is_credit_sale TINYINT(1) DEFAULT 0 AFTER payment_status");
        $primaryDb->executeQuery("ALTER TABLE sales ADD COLUMN payment_term_id INT(11) DEFAULT NULL AFTER is_credit_sale");
        $primaryDb->executeQuery("ALTER TABLE sales ADD COLUMN account_balance DECIMAL(10,2) DEFAULT 0.00 AFTER payment_term_id");
        $primaryDb->executeQuery("ALTER TABLE sales ADD COLUMN account_settled TINYINT(1) DEFAULT 0 AFTER account_balance");
        $primaryDb->executeQuery("ALTER TABLE sales ADD INDEX idx_is_credit_sale (is_credit_sale)");
        $primaryDb->executeQuery("ALTER TABLE sales ADD INDEX idx_payment_term_id (payment_term_id)");
        echo "   ✓ Added credit sale fields to sales\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            echo "   ⚠ Error: " . $e->getMessage() . "\n";
        } else {
            echo "   ✓ Columns already exist\n";
        }
    }

    // 5. Create account_payments table for credit sales settlements
    echo "5. Creating account_payments table...\n";
    $primaryDb->executeQuery("CREATE TABLE IF NOT EXISTS account_payments (
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
    echo "   ✓ Created account_payments table\n";

    // 6. Create system_settings table
    echo "6. Creating system_settings table...\n";
    $primaryDb->executeQuery("CREATE TABLE IF NOT EXISTS system_settings (
        id INT(11) NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT DEFAULT NULL,
        setting_type VARCHAR(50) DEFAULT 'string',
        description TEXT DEFAULT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT(11) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   ✓ Created system_settings table\n";

    // 7. Create function_keys table (REMOVED - Feature not compatible with web browsers)
    // Function keys feature has been removed due to browser compatibility issues
    // (F-keys conflict with browser shortcuts like F5=reload, F11=fullscreen, etc.)
    // echo "7. Creating function_keys table...\n";
    // ... (table creation code removed)
    // echo "   ✓ Created function_keys table\n";

    // 8. Create stock_takes table
    echo "8. Creating stock_takes table...\n";
    $primaryDb->executeQuery("CREATE TABLE IF NOT EXISTS stock_takes (
        id INT(11) NOT NULL AUTO_INCREMENT,
        stock_take_number VARCHAR(50) NOT NULL,
        branch_id INT(11) NOT NULL,
        take_type ENUM('full', 'single') DEFAULT 'full',
        product_id INT(11) DEFAULT NULL,
        status ENUM('draft', 'completed', 'cancelled') DEFAULT 'draft',
        taken_by INT(11) NOT NULL,
        taken_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_stock_take_number (stock_take_number),
        KEY idx_branch_id (branch_id),
        KEY idx_product_id (product_id),
        KEY idx_status (status),
        KEY idx_taken_at (taken_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   ✓ Created stock_takes table\n";

    // 9. Create stock_take_items table
    echo "9. Creating stock_take_items table...\n";
    $primaryDb->executeQuery("CREATE TABLE IF NOT EXISTS stock_take_items (
        id INT(11) NOT NULL AUTO_INCREMENT,
        stock_take_id INT(11) NOT NULL,
        product_id INT(11) NOT NULL,
        current_stock INT(11) DEFAULT 0,
        counted_stock INT(11) NOT NULL,
        difference INT(11) DEFAULT 0,
        notes TEXT DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_stock_take_id (stock_take_id),
        KEY idx_product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   ✓ Created stock_take_items table\n";

    // 10. Add delivery_cost to sales table
    echo "10. Adding delivery_cost to sales table...\n";
    try {
        $primaryDb->executeQuery("ALTER TABLE sales ADD COLUMN delivery_cost DECIMAL(10,2) DEFAULT 0.00 AFTER discount_amount");
        echo "   ✓ Added delivery_cost to sales\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            echo "   ⚠ Error: " . $e->getMessage() . "\n";
        } else {
            echo "   ✓ Column already exists\n";
        }
    }

    // 11. Create credit_notes table (for storing credit note details)
    echo "11. Creating credit_notes table...\n";
    $primaryDb->executeQuery("CREATE TABLE IF NOT EXISTS credit_notes (
        id INT(11) NOT NULL AUTO_INCREMENT,
        credit_note_number VARCHAR(50) NOT NULL,
        refund_id INT(11) NOT NULL,
        sale_id INT(11) NOT NULL,
        branch_id INT(11) DEFAULT NULL,
        customer_id INT(11) DEFAULT NULL,
        credit_note_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        total_amount DECIMAL(10,2) NOT NULL,
        fiscalized TINYINT(1) DEFAULT 0,
        fiscal_details TEXT DEFAULT NULL,
        created_by INT(11) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_credit_note_number (credit_note_number),
        KEY idx_refund_id (refund_id),
        KEY idx_sale_id (sale_id),
        KEY idx_branch_id (branch_id),
        KEY idx_credit_note_date (credit_note_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   ✓ Created credit_notes table\n";

    // 12. Create credit_note_items table
    echo "12. Creating credit_note_items table...\n";
    $primaryDb->executeQuery("CREATE TABLE IF NOT EXISTS credit_note_items (
        id INT(11) NOT NULL AUTO_INCREMENT,
        credit_note_id INT(11) NOT NULL,
        product_id INT(11) NOT NULL,
        product_name VARCHAR(255) NOT NULL,
        quantity INT(11) NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        total_price DECIMAL(10,2) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_credit_note_id (credit_note_id),
        KEY idx_product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   ✓ Created credit_note_items table\n";

    // 13. Ensure products table has barcode and expiry_date (already exists, but verify)
    echo "13. Verifying products table structure...\n";
    try {
        $result = $primaryDb->getRow("SHOW COLUMNS FROM products LIKE 'barcode'");
        if (!$result) {
            $primaryDb->executeQuery("ALTER TABLE products ADD COLUMN barcode VARCHAR(100) DEFAULT NULL AFTER sku");
        }
        $result = $primaryDb->getRow("SHOW COLUMNS FROM products LIKE 'expiry_date'");
        if (!$result) {
            $primaryDb->executeQuery("ALTER TABLE products ADD COLUMN expiry_date DATE DEFAULT NULL");
        }
        echo "   ✓ Products table structure verified\n";
    } catch (Exception $e) {
        echo "   ⚠ Error: " . $e->getMessage() . "\n";
    }

    // 14. Create user_sessions table for duplicate login prevention
    echo "14. Creating user_sessions table...\n";
    $primaryDb->executeQuery("CREATE TABLE IF NOT EXISTS user_sessions (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        session_id VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        last_activity DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_session_id (session_id),
        KEY idx_last_activity (last_activity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   ✓ Created user_sessions table\n";

    // 15. Seed default payment terms
    echo "15. Seeding default payment terms...\n";
    $defaultTerms = [
        ['Pay in 7 days', 'Payment due within 7 days', 7],
        ['Pay in 14 days', 'Payment due within 14 days', 14],
        ['Pay in 30 days', 'Payment due within 30 days', 30],
        ['Pay in 60 days', 'Payment due within 60 days', 60],
        ['Pay in 90 days', 'Payment due within 90 days', 90],
    ];
    
    foreach ($defaultTerms as $term) {
        $existing = $primaryDb->getRow("SELECT id FROM payment_terms WHERE name = :name", [':name' => $term[0]]);
        if (!$existing) {
            $primaryDb->insert('payment_terms', [
                'name' => $term[0],
                'description' => $term[1],
                'days' => $term[2],
                'is_active' => 1
            ]);
        }
    }
    echo "   ✓ Seeded default payment terms\n";

    // 16. Seed default system settings
    echo "16. Seeding default system settings...\n";
    $defaultSettings = [
        ['allow_negative_stock', '0', 'boolean', 'Allow products to have negative stock values'],
        ['allow_product_discounts', '1', 'boolean', 'Allow discounts on individual products'],
        ['allow_invoice_discounts', '1', 'boolean', 'Allow discounts on entire invoices'],
        ['allow_cashier_view_stock', '1', 'boolean', 'Allow cashiers to view stock balances on POS'],
        ['allow_credit_sales', '0', 'boolean', 'Allow account/credit sales'],
        ['add_delivery_costs', '0', 'boolean', 'Enable delivery cost feature'],
        ['send_low_stock_notifications', '0', 'boolean', 'Send email notifications for low stock levels'],
        ['low_stock_notification_email', '', 'string', 'Email address for low stock notifications'],
        ['send_expiry_notifications', '0', 'boolean', 'Send email notifications for products nearing expiry'],
        ['expiry_notification_email', '', 'string', 'Email address for expiry date notifications'],
        ['timezone', 'Africa/Harare', 'string', 'System timezone'],
        ['check_low_stock_at_login', '0', 'boolean', 'Check and notify about low stock levels at login'],
        ['allow_stock_take', '1', 'boolean', 'Allow stock take functionality'],
        ['disallow_duplicate_logins', '0', 'boolean', 'Prevent duplicate logins with same credentials'],
        ['prices_include_tax', '1', 'boolean', 'Prices entered include tax (show tax separately on receipts)'],
    ];

    foreach ($defaultSettings as $setting) {
        $existing = $primaryDb->getRow("SELECT id FROM system_settings WHERE setting_key = :key", [':key' => $setting[0]]);
        if (!$existing) {
            $primaryDb->insert('system_settings', [
                'setting_key' => $setting[0],
                'setting_value' => $setting[1],
                'setting_type' => $setting[2],
                'description' => $setting[3]
            ]);
        }
    }
    echo "   ✓ Seeded default system settings\n";

    echo "\n✓ Database migration completed successfully!\n";
    echo "All tables and columns have been created/updated.\n";

} catch (Exception $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

