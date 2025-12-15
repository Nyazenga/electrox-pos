<?php
/**
 * Seed Default Currencies
 * Run this to add default currencies if they don't exist
 */

require_once __DIR__ . '/config.php';
require_once APP_PATH . '/includes/db.php';

$db = Database::getInstance();

echo "Seeding default currencies...\n\n";

try {
    // Check if currencies table exists (try different methods)
    $tableExists = false;
    try {
        $result = $db->getRow("SHOW TABLES LIKE 'currencies'");
        $tableExists = !empty($result);
    } catch (Exception $e) {
        // Try alternative method
        try {
            $result = $db->getRow("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'currencies'");
            $tableExists = !empty($result) && intval($result['count']) > 0;
        } catch (Exception $e2) {
            // Try to query the table directly
            try {
                $db->getRow("SELECT COUNT(*) as count FROM currencies LIMIT 1");
                $tableExists = true;
            } catch (Exception $e3) {
                $tableExists = false;
            }
        }
    }
    
    if (!$tableExists) {
        echo "ERROR: currencies table does not exist. Creating it now...\n";
        
        // Create the table
        $createTable = "CREATE TABLE IF NOT EXISTS `currencies` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo = $db->getPdo();
        $pdo->exec($createTable);
        echo "✓ Created currencies table\n\n";
    }
    
    // Check if any currencies exist
    $existing = $db->getRows("SELECT COUNT(*) as count FROM currencies");
    $count = $existing && isset($existing[0]['count']) ? intval($existing[0]['count']) : 0;
    
    if ($count > 0) {
        echo "Currencies already exist ($count currencies found).\n";
        echo "Existing currencies:\n";
        $currencies = $db->getRows("SELECT * FROM currencies ORDER BY is_base DESC, code ASC");
        foreach ($currencies as $currency) {
            $base = $currency['is_base'] ? ' (BASE)' : '';
            $active = $currency['is_active'] ? 'Active' : 'Inactive';
            echo "  - {$currency['code']}: {$currency['name']} ({$currency['symbol']}) - {$active}{$base}\n";
        }
    } else {
        echo "No currencies found. Inserting default currencies...\n\n";
        
        // Insert default currencies
        $defaultCurrencies = [
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'symbol_position' => 'before',
                'decimal_places' => 2,
                'is_base' => 1,
                'is_active' => 1,
                'exchange_rate' => 1.000000
            ],
            [
                'code' => 'ZAR',
                'name' => 'South African Rand',
                'symbol' => 'R',
                'symbol_position' => 'before',
                'decimal_places' => 2,
                'is_base' => 0,
                'is_active' => 1,
                'exchange_rate' => 18.500000
            ],
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'symbol_position' => 'before',
                'decimal_places' => 2,
                'is_base' => 0,
                'is_active' => 1,
                'exchange_rate' => 0.920000
            ],
            [
                'code' => 'GBP',
                'name' => 'British Pound',
                'symbol' => '£',
                'symbol_position' => 'before',
                'decimal_places' => 2,
                'is_base' => 0,
                'is_active' => 1,
                'exchange_rate' => 0.790000
            ]
        ];
        
        foreach ($defaultCurrencies as $currency) {
            try {
                $db->insert('currencies', $currency);
                $base = $currency['is_base'] ? ' (BASE)' : '';
                echo "✓ Inserted: {$currency['code']} - {$currency['name']}{$base}\n";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    echo "⚠ Skipped: {$currency['code']} already exists\n";
                } else {
                    echo "✗ Error inserting {$currency['code']}: " . $e->getMessage() . "\n";
                }
            }
        }
        
        echo "\n✓ Currency seeding completed!\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

