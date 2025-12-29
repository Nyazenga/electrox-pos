<?php
/**
 * Create stock_take_reports table in electrox_primary database
 * Run this script once to create the table
 */

require_once dirname(dirname(__FILE__)) . '/config.php';
require_once APP_PATH . '/includes/db.php';

// Connect directly to primary database
$host = 'localhost';
$dbname = 'electrox_primary';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql = "CREATE TABLE IF NOT EXISTS `stock_take_reports` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `stock_take_id` INT(11) NOT NULL,
        `branch_id` INT(11) NOT NULL,
        `taken_by` INT(11) NOT NULL,
        `report_date` DATETIME NOT NULL,
        `total_items` INT(11) NOT NULL DEFAULT 0,
        `items_with_gains` INT(11) NOT NULL DEFAULT 0,
        `items_with_losses` INT(11) NOT NULL DEFAULT 0,
        `items_no_change` INT(11) NOT NULL DEFAULT 0,
        `total_gain_quantity` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `total_loss_quantity` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `net_difference` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `summary_data` TEXT NULL COMMENT 'JSON data with detailed breakdown',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_stock_take_id` (`stock_take_id`),
        INDEX `idx_branch_id` (`branch_id`),
        INDEX `idx_report_date` (`report_date`),
        INDEX `idx_taken_by` (`taken_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql);
    
    echo "Table 'stock_take_reports' created successfully!\n";
} catch (Exception $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
    exit(1);
}

