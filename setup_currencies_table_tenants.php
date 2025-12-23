<?php
/**
 * Setup currencies table in all tenant databases
 * This ensures each tenant has their own currencies table
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

echo "=== Setting up currencies table in tenant databases ===\n\n";

// Get list of all tenant databases from base database (tenants table is in electrox_base)
$baseDb = Database::getMainInstance();
$tenants = $baseDb->getRows("SELECT tenant_slug, database_name FROM tenants WHERE status = 'active'");

if (empty($tenants)) {
    echo "No active tenants found. Checking if we should create for 'primary' tenant...\n";
    // Try to connect to primary tenant
    $tenants = [['tenant_slug' => 'primary', 'database_name' => 'electrox_primary']];
}

$currenciesTableSQL = "
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(3) NOT NULL,
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
  UNIQUE KEY `idx_code` (`code`),
  KEY `idx_is_base` (`is_base`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

$currencyExchangeRatesTableSQL = "
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
";

foreach ($tenants as $tenant) {
    $tenantSlug = $tenant['tenant_slug'] ?? $tenant['database_name'] ?? 'primary';
    // Remove 'electrox_' prefix if present
    if (strpos($tenantSlug, 'electrox_') === 0) {
        $tenantSlug = substr($tenantSlug, 9);
    }
    echo "Processing tenant: $tenantSlug\n";
    
    try {
        // Connect to tenant database
        $_SESSION['tenant_name'] = $tenantSlug;
        $db = Database::getInstance();
        
        // Check if currencies table exists
        $tableExists = $db->getRow("SHOW TABLES LIKE 'currencies'");
        
        if (!$tableExists) {
            echo "  Creating currencies table...\n";
            $db->executeQuery($currenciesTableSQL);
            echo "  ✓ Currencies table created\n";
            
            // Insert default currencies
            echo "  Inserting default currencies...\n";
            $defaultCurrencies = [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'symbol_position' => 'before', 'decimal_places' => 2, 'is_base' => 1, 'is_active' => 1, 'exchange_rate' => 1.000000],
                ['code' => 'ZWL', 'name' => 'Zimbabwean Dollar', 'symbol' => 'ZWL$', 'symbol_position' => 'before', 'decimal_places' => 2, 'is_base' => 0, 'is_active' => 1, 'exchange_rate' => 35.000000]
            ];
            
            foreach ($defaultCurrencies as $currency) {
                $db->insert('currencies', $currency);
            }
            echo "  ✓ Default currencies inserted\n";
        } else {
            echo "  ✓ Currencies table already exists\n";
            
            // Check if there are any currencies
            $count = $db->getRow("SELECT COUNT(*) as count FROM currencies");
            if ($count && $count['count'] == 0) {
                echo "  Table is empty, inserting default currencies...\n";
                $defaultCurrencies = [
                    ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'symbol_position' => 'before', 'decimal_places' => 2, 'is_base' => 1, 'is_active' => 1, 'exchange_rate' => 1.000000],
                    ['code' => 'ZWL', 'name' => 'Zimbabwean Dollar', 'symbol' => 'ZWL$', 'symbol_position' => 'before', 'decimal_places' => 2, 'is_base' => 0, 'is_active' => 1, 'exchange_rate' => 35.000000]
                ];
                
                foreach ($defaultCurrencies as $currency) {
                    $db->insert('currencies', $currency);
                }
                echo "  ✓ Default currencies inserted\n";
            }
        }
        
        // Check if currency_exchange_rates table exists
        $tableExists = $db->getRow("SHOW TABLES LIKE 'currency_exchange_rates'");
        if (!$tableExists) {
            echo "  Creating currency_exchange_rates table...\n";
            $db->executeQuery($currencyExchangeRatesTableSQL);
            echo "  ✓ Currency exchange rates table created\n";
        } else {
            echo "  ✓ Currency exchange rates table already exists\n";
        }
        
        echo "  ✓ Tenant $tenantSlug setup complete\n\n";
        
    } catch (Exception $e) {
        echo "  ✗ Error processing tenant $tenantSlug: " . $e->getMessage() . "\n\n";
    }
}

echo "=== Setup complete ===\n";

