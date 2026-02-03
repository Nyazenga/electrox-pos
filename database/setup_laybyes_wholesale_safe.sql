-- Safe setup script - handles existing columns gracefully
USE electrox_primary;

-- 1. Add wholesale_price to products table (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'electrox_primary' 
    AND TABLE_NAME = 'products' 
    AND COLUMN_NAME = 'wholesale_price');
    
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE products ADD COLUMN wholesale_price DECIMAL(10,2) DEFAULT NULL AFTER selling_price, ADD INDEX idx_wholesale_price (wholesale_price)',
    'SELECT "wholesale_price column already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add is_wholesale_sale to sales (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'electrox_primary' 
    AND TABLE_NAME = 'sales' 
    AND COLUMN_NAME = 'is_wholesale_sale');
    
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE sales ADD COLUMN is_wholesale_sale TINYINT(1) DEFAULT 0 AFTER is_credit_sale, ADD INDEX idx_is_wholesale_sale (is_wholesale_sale)',
    'SELECT "is_wholesale_sale column already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Add is_pending_payment to sales (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'electrox_primary' 
    AND TABLE_NAME = 'sales' 
    AND COLUMN_NAME = 'is_pending_payment');
    
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE sales ADD COLUMN is_pending_payment TINYINT(1) DEFAULT 0 AFTER is_wholesale_sale, ADD INDEX idx_is_pending_payment (is_pending_payment)',
    'SELECT "is_pending_payment column already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Create laybyes table (if not exists)
CREATE TABLE IF NOT EXISTS laybyes (
    id INT(11) NOT NULL AUTO_INCREMENT,
    laybye_number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT(11) NOT NULL,
    branch_id INT(11) NOT NULL,
    user_id INT(11) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_remaining DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    payment_schedule_type ENUM('monthly', 'custom') DEFAULT 'monthly',
    payment_schedule_data TEXT DEFAULT NULL COMMENT 'JSON data for payment schedule',
    next_payment_date DATE DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    cancelled_reason TEXT DEFAULT NULL,
    sale_id INT(11) DEFAULT NULL COMMENT 'Linked sale when completed',
    notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_laybye_number (laybye_number),
    KEY idx_customer_id (customer_id),
    KEY idx_branch_id (branch_id),
    KEY idx_user_id (user_id),
    KEY idx_status (status),
    KEY idx_next_payment_date (next_payment_date),
    KEY idx_sale_id (sale_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Create laybye_items table (if not exists)
CREATE TABLE IF NOT EXISTS laybye_items (
    id INT(11) NOT NULL AUTO_INCREMENT,
    laybye_id INT(11) NOT NULL,
    product_id INT(11) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    quantity INT(11) NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_laybye_id (laybye_id),
    KEY idx_product_id (product_id),
    FOREIGN KEY (laybye_id) REFERENCES laybyes(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Create laybye_payments table (if not exists)
CREATE TABLE IF NOT EXISTS laybye_payments (
    id INT(11) NOT NULL AUTO_INCREMENT,
    laybye_id INT(11) NOT NULL,
    payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash', 'card', 'bank_transfer', 'mobile_money', 'other') DEFAULT 'cash',
    currency_id INT(11) DEFAULT NULL,
    exchange_rate DECIMAL(10,6) DEFAULT 1.000000,
    base_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    user_id INT(11) DEFAULT NULL,
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_laybye_id (laybye_id),
    KEY idx_user_id (user_id),
    KEY idx_payment_date (payment_date),
    FOREIGN KEY (laybye_id) REFERENCES laybyes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Create laybye_payment_schedule table (if not exists)
CREATE TABLE IF NOT EXISTS laybye_payment_schedule (
    id INT(11) NOT NULL AUTO_INCREMENT,
    laybye_id INT(11) NOT NULL,
    scheduled_date DATE NOT NULL,
    scheduled_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(10,2) DEFAULT 0.00,
    is_paid TINYINT(1) DEFAULT 0,
    paid_at DATETIME DEFAULT NULL,
    reminder_sent TINYINT(1) DEFAULT 0,
    reminder_sent_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_laybye_id (laybye_id),
    KEY idx_scheduled_date (scheduled_date),
    KEY idx_is_paid (is_paid),
    KEY idx_reminder_sent (reminder_sent),
    FOREIGN KEY (laybye_id) REFERENCES laybyes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Setup completed successfully!' AS message;
