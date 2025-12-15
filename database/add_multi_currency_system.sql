-- Multi-Currency System Tables

-- Currencies Table
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(3) NOT NULL UNIQUE,
  `name` varchar(100) NOT NULL,
  `symbol` varchar(10) NOT NULL,
  `symbol_position` enum('before','after') DEFAULT 'before',
  `decimal_places` int(11) DEFAULT 2,
  `is_base` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `exchange_rate` decimal(10,6) DEFAULT 1.000000,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_code` (`code`),
  KEY `idx_is_base` (`is_base`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Currency Exchange Rates History Table
CREATE TABLE IF NOT EXISTS `currency_exchange_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_currency_id` int(11) NOT NULL,
  `to_currency_id` int(11) NOT NULL,
  `rate` decimal(10,6) NOT NULL,
  `effective_date` date NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_from_currency` (`from_currency_id`),
  KEY `idx_to_currency` (`to_currency_id`),
  KEY `idx_effective_date` (`effective_date`),
  KEY `idx_from_to_date` (`from_currency_id`, `to_currency_id`, `effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update sale_payments table to support multi-currency
ALTER TABLE `sale_payments` 
  ADD COLUMN IF NOT EXISTS `currency_id` int(11) DEFAULT NULL AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `exchange_rate` decimal(10,6) DEFAULT 1.000000 AFTER `currency_id`,
  ADD COLUMN IF NOT EXISTS `original_amount` decimal(10,2) DEFAULT NULL AFTER `exchange_rate`,
  ADD COLUMN IF NOT EXISTS `base_amount` decimal(10,2) DEFAULT NULL AFTER `original_amount`,
  ADD KEY IF NOT EXISTS `idx_currency_id` (`currency_id`);

-- Update refund_payments table to support multi-currency
ALTER TABLE `refund_payments` 
  ADD COLUMN IF NOT EXISTS `currency_id` int(11) DEFAULT NULL AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `exchange_rate` decimal(10,6) DEFAULT 1.000000 AFTER `currency_id`,
  ADD COLUMN IF NOT EXISTS `original_amount` decimal(10,2) DEFAULT NULL AFTER `exchange_rate`,
  ADD COLUMN IF NOT EXISTS `base_amount` decimal(10,2) DEFAULT NULL AFTER `original_amount`,
  ADD KEY IF NOT EXISTS `idx_currency_id` (`currency_id`);

-- Update payments table (for invoices) to support multi-currency
ALTER TABLE `payments` 
  ADD COLUMN IF NOT EXISTS `currency_id` int(11) DEFAULT NULL AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `exchange_rate` decimal(10,6) DEFAULT 1.000000 AFTER `currency_id`,
  ADD COLUMN IF NOT EXISTS `original_amount` decimal(10,2) DEFAULT NULL AFTER `exchange_rate`,
  ADD COLUMN IF NOT EXISTS `base_amount` decimal(10,2) DEFAULT NULL AFTER `original_amount`,
  ADD KEY IF NOT EXISTS `idx_currency_id` (`currency_id`);

-- Update sales table to store base currency totals
ALTER TABLE `sales` 
  ADD COLUMN IF NOT EXISTS `base_currency_id` int(11) DEFAULT NULL AFTER `total_amount`,
  ADD KEY IF NOT EXISTS `idx_base_currency_id` (`base_currency_id`);

-- Update invoices table to support multi-currency
ALTER TABLE `invoices` 
  ADD COLUMN IF NOT EXISTS `currency_id` int(11) DEFAULT NULL AFTER `total_amount`,
  ADD COLUMN IF NOT EXISTS `base_currency_id` int(11) DEFAULT NULL AFTER `currency_id`,
  ADD KEY IF NOT EXISTS `idx_currency_id` (`currency_id`),
  ADD KEY IF NOT EXISTS `idx_base_currency_id` (`base_currency_id`);

-- Update shifts table to track multi-currency cash
ALTER TABLE `shifts` 
  ADD COLUMN IF NOT EXISTS `base_currency_id` int(11) DEFAULT NULL AFTER `difference`,
  ADD KEY IF NOT EXISTS `idx_base_currency_id` (`base_currency_id`);

-- Currency Cash Summary Table (for shift closing)
CREATE TABLE IF NOT EXISTS `shift_currency_summary` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `cash_sales` decimal(10,2) DEFAULT 0.00,
  `cash_refunds` decimal(10,2) DEFAULT 0.00,
  `paid_in` decimal(10,2) DEFAULT 0.00,
  `paid_out` decimal(10,2) DEFAULT 0.00,
  `expected_cash` decimal(10,2) DEFAULT 0.00,
  `actual_cash` decimal(10,2) DEFAULT NULL,
  `difference` decimal(10,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_shift_currency` (`shift_id`, `currency_id`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_currency_id` (`currency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default currencies (USD as base)
INSERT IGNORE INTO `currencies` (`code`, `name`, `symbol`, `symbol_position`, `decimal_places`, `is_base`, `is_active`, `exchange_rate`) VALUES
('USD', 'US Dollar', '$', 'before', 2, 1, 1, 1.000000),
('ZAR', 'South African Rand', 'R', 'before', 2, 0, 1, 18.500000),
('EUR', 'Euro', '€', 'before', 2, 0, 1, 0.920000),
('GBP', 'British Pound', '£', 'before', 2, 0, 1, 0.790000);

