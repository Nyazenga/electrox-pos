-- Fiscal Device Gateway API Tables
-- ZIMRA Fiscalization Support

CREATE TABLE IF NOT EXISTS `fiscal_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `device_serial_no` varchar(20) NOT NULL,
  `activation_key` varchar(8) NOT NULL,
  `device_model_name` varchar(100) DEFAULT 'Server',
  `device_model_version` varchar(50) DEFAULT 'v1',
  `certificate_pem` text DEFAULT NULL,
  `certificate_valid_till` datetime DEFAULT NULL,
  `private_key_pem` text DEFAULT NULL,
  `is_registered` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `operating_mode` enum('Online','Offline') DEFAULT 'Online',
  `last_config_sync` datetime DEFAULT NULL,
  `last_ping` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_branch_device` (`branch_id`, `device_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_device_id` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_days` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `fiscal_day_no` int(11) NOT NULL,
  `fiscal_day_opened` datetime NOT NULL,
  `fiscal_day_closed` datetime DEFAULT NULL,
  `status` enum('FiscalDayOpened','FiscalDayCloseInitiated','FiscalDayCloseFailed','FiscalDayClosed') DEFAULT 'FiscalDayOpened',
  `reconciliation_mode` enum('Auto','Manual') DEFAULT NULL,
  `fiscal_day_device_signature` text DEFAULT NULL,
  `fiscal_day_server_signature` text DEFAULT NULL,
  `last_receipt_global_no` int(11) DEFAULT 0,
  `last_receipt_counter` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_branch_day` (`branch_id`, `device_id`, `fiscal_day_no`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) DEFAULT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `branch_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `fiscal_day_no` int(11) NOT NULL,
  `receipt_type` enum('FiscalInvoice','CreditNote','DebitNote') NOT NULL,
  `receipt_currency` varchar(3) NOT NULL,
  `receipt_counter` int(11) NOT NULL,
  `receipt_global_no` int(11) NOT NULL,
  `invoice_no` varchar(50) NOT NULL,
  `receipt_date` datetime NOT NULL,
  `receipt_total` decimal(21,2) NOT NULL,
  `receipt_hash` varchar(255) DEFAULT NULL,
  `receipt_device_signature` text DEFAULT NULL,
  `receipt_server_signature` text DEFAULT NULL,
  `receipt_id` bigint(20) DEFAULT NULL,
  `receipt_qr_code` text DEFAULT NULL,
  `receipt_qr_data` varchar(16) DEFAULT NULL,
  `receipt_verification_code` varchar(20) DEFAULT NULL,
  `submission_status` enum('Pending','Submitted','Failed','Retry') DEFAULT 'Pending',
  `submission_error` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_receipt_global` (`device_id`, `receipt_global_no`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_sale_id` (`sale_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_fiscal_day_no` (`fiscal_day_no`),
  KEY `idx_submission_status` (`submission_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_receipt_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fiscal_receipt_id` int(11) NOT NULL,
  `receipt_line_no` int(11) NOT NULL,
  `receipt_line_type` enum('Sale','Discount') NOT NULL,
  `receipt_line_name` varchar(200) NOT NULL,
  `receipt_line_hs_code` varchar(8) DEFAULT NULL,
  `receipt_line_price` decimal(25,6) DEFAULT NULL,
  `receipt_line_quantity` decimal(25,6) NOT NULL,
  `receipt_line_total` decimal(21,2) NOT NULL,
  `tax_code` varchar(3) DEFAULT NULL,
  `tax_percent` decimal(5,2) DEFAULT NULL,
  `tax_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fiscal_receipt_id` (`fiscal_receipt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_receipt_taxes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fiscal_receipt_id` int(11) NOT NULL,
  `tax_code` varchar(3) DEFAULT NULL,
  `tax_percent` decimal(5,2) DEFAULT NULL,
  `tax_id` int(11) NOT NULL,
  `tax_amount` decimal(21,2) NOT NULL,
  `sales_amount_with_tax` decimal(21,2) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fiscal_receipt_id` (`fiscal_receipt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_receipt_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fiscal_receipt_id` int(11) NOT NULL,
  `money_type_code` enum('Cash','Card','MobileWallet','Coupon','Credit','BankTransfer','Other') NOT NULL,
  `payment_amount` decimal(21,2) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fiscal_receipt_id` (`fiscal_receipt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_counters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fiscal_day_id` int(11) NOT NULL,
  `fiscal_counter_type` enum('SaleByTax','SaleTaxByTax','CreditNoteByTax','CreditNoteTaxByTax','DebitNoteByTax','DebitNoteTaxByTax','BalanceByMoneyType') NOT NULL,
  `fiscal_counter_currency` varchar(3) NOT NULL,
  `fiscal_counter_tax_id` int(11) DEFAULT NULL,
  `fiscal_counter_tax_percent` decimal(5,2) DEFAULT NULL,
  `fiscal_counter_money_type` enum('Cash','Card','MobileWallet','Coupon','Credit','BankTransfer','Other') DEFAULT NULL,
  `fiscal_counter_value` decimal(19,2) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fiscal_day_id` (`fiscal_day_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `taxpayer_name` varchar(250) DEFAULT NULL,
  `taxpayer_tin` varchar(10) DEFAULT NULL,
  `vat_number` varchar(9) DEFAULT NULL,
  `device_branch_name` varchar(250) DEFAULT NULL,
  `device_branch_address` text DEFAULT NULL,
  `device_branch_contacts` text DEFAULT NULL,
  `device_operating_mode` enum('Online','Offline') DEFAULT 'Online',
  `taxpayer_day_max_hrs` int(11) DEFAULT 24,
  `taxpayer_day_end_notification_hrs` int(11) DEFAULT 2,
  `applicable_taxes` text DEFAULT NULL,
  `certificate_valid_till` datetime DEFAULT NULL,
  `qr_url` varchar(50) DEFAULT NULL,
  `last_synced` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_branch_device_config` (`branch_id`, `device_id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_device_id` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add fiscalization enabled flag to branches
ALTER TABLE `branches` 
ADD COLUMN IF NOT EXISTS `fiscalization_enabled` tinyint(1) DEFAULT 0 AFTER `status`;

