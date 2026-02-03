-- Create table for Proforma Invoice Terms & Conditions
CREATE TABLE IF NOT EXISTS `proforma_terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add banking_details_included column to invoices table if it doesn't exist
ALTER TABLE `invoices` 
ADD COLUMN IF NOT EXISTS `banking_details_included` tinyint(1) NOT NULL DEFAULT 1 AFTER `terms`;

-- Add terms_id column to invoices table if it doesn't exist
ALTER TABLE `invoices` 
ADD COLUMN IF NOT EXISTS `terms_id` int(11) NULL AFTER `terms`,
ADD KEY IF NOT EXISTS `idx_terms_id` (`terms_id`),
ADD CONSTRAINT IF NOT EXISTS `fk_invoices_terms` FOREIGN KEY (`terms_id`) REFERENCES `proforma_terms` (`id`) ON DELETE SET NULL;
