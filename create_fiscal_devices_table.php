<?php
/**
 * Manually create fiscal_devices table
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "Creating fiscal_devices table...\n";

$primaryDb = Database::getPrimaryInstance();
$pdo = $primaryDb->getPdo();

$sql = "CREATE TABLE IF NOT EXISTS `fiscal_devices` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

try {
    $pdo->exec($sql);
    echo "✓ fiscal_devices table created\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "✓ fiscal_devices table already exists\n";
    } else {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
}

// Verify
$stmt = $pdo->query("SHOW TABLES LIKE 'fiscal_devices'");
if ($stmt->rowCount() > 0) {
    echo "✓ Table verified\n";
} else {
    echo "✗ Table not found after creation\n";
}

