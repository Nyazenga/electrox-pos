-- Setup Laybyes and Wholesale Sales Features
-- Run this SQL script in your MySQL database (electrox_primary)

USE electrox_primary;

-- 1. Add wholesale_price to products table
ALTER TABLE products ADD COLUMN wholesale_price DECIMAL(10,2) DEFAULT NULL AFTER selling_price;
ALTER TABLE products ADD INDEX idx_wholesale_price (wholesale_price);

-- 2. Add wholesale sale fields to sales table
ALTER TABLE sales ADD COLUMN is_wholesale_sale TINYINT(1) DEFAULT 0 AFTER is_credit_sale;
ALTER TABLE sales ADD COLUMN is_pending_payment TINYINT(1) DEFAULT 0 AFTER is_wholesale_sale;
ALTER TABLE sales ADD INDEX idx_is_wholesale_sale (is_wholesale_sale);
ALTER TABLE sales ADD INDEX idx_is_pending_payment (is_pending_payment);

-- 3. Create laybyes table
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

-- 4. Create laybye_items table
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

-- 5. Create laybye_payments table
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

-- 6. Create laybye_payment_schedule table
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
