<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Force localhost mode
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';

require_once dirname(__FILE__) . '/../config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Running Migration for " . PRIMARY_DB_NAME . " ===\n\n";

try {
    $db = Database::getPrimaryInstance();
    echo "Connected to: " . PRIMARY_DB_NAME . "\n\n";
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// Create proforma_terms table
$createTable = "CREATE TABLE IF NOT EXISTS `proforma_terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

try {
    $db->getPdo()->exec($createTable);
    echo "✓ Created proforma_terms table\n";
} catch (Exception $e) {
    echo "✗ Error creating table: " . $e->getMessage() . "\n";
}

// Add banking_details_included column if it doesn't exist
try {
    $checkColumn = $db->getRow("SHOW COLUMNS FROM invoices LIKE 'banking_details_included'");
    if (!$checkColumn) {
        $db->getPdo()->exec("ALTER TABLE `invoices` ADD COLUMN `banking_details_included` tinyint(1) NOT NULL DEFAULT 1 AFTER `terms`");
        echo "✓ Added banking_details_included column to invoices\n";
    } else {
        echo "- banking_details_included column already exists\n";
    }
} catch (Exception $e) {
    echo "✗ Error adding banking_details_included: " . $e->getMessage() . "\n";
}

// Add terms_id column if it doesn't exist
try {
    $checkColumn = $db->getRow("SHOW COLUMNS FROM invoices LIKE 'terms_id'");
    if (!$checkColumn) {
        $db->getPdo()->exec("ALTER TABLE `invoices` ADD COLUMN `terms_id` int(11) NULL AFTER `terms`");
        echo "✓ Added terms_id column to invoices\n";
    } else {
        echo "- terms_id column already exists\n";
    }
    
    // Add foreign key if it doesn't exist
    $checkFK = $db->getRow("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND CONSTRAINT_NAME = 'fk_invoices_terms'");
    if (!$checkFK) {
        $db->getPdo()->exec("ALTER TABLE `invoices` ADD KEY `idx_terms_id` (`terms_id`), ADD CONSTRAINT `fk_invoices_terms` FOREIGN KEY (`terms_id`) REFERENCES `proforma_terms` (`id`) ON DELETE SET NULL");
        echo "✓ Added foreign key constraint\n";
    } else {
        echo "- Foreign key constraint already exists\n";
    }
} catch (Exception $e) {
    echo "✗ Error adding terms_id: " . $e->getMessage() . "\n";
}

echo "\n=== Migration completed! ===\n";
