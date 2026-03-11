-- Migration: Create printers table for POS printer configurations
-- This table stores printer configurations per branch

CREATE TABLE IF NOT EXISTS `printers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `branch_id` INT(11) NOT NULL,
  `printer_name` VARCHAR(255) NOT NULL,
  `connection_mode` ENUM('USB', 'Network', 'Bluetooth') DEFAULT 'USB',
  `device_id` VARCHAR(255) DEFAULT NULL COMMENT 'Device identifier from printer service',
  `paper_size` ENUM('58mm', '80mm') DEFAULT '80mm',
  `print_receipts` TINYINT(1) DEFAULT 1 COMMENT 'Enable receipt printing',
  `print_bills` TINYINT(1) DEFAULT 0 COMMENT 'Enable bill/invoice printing',
  `cash_drawer_connected` TINYINT(1) DEFAULT 0 COMMENT 'Cash drawer connected to printer',
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_branch_id` (`branch_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
