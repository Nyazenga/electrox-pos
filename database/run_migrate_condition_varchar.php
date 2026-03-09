<?php
/**
 * Run Condition VARCHAR Migration
 * Changes condition column from ENUM to VARCHAR on all tenant databases
 * Date: 2026-03-09
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

$dbHost = DB_HOST;
$dbUser = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1') ? 'root' : DB_USER;
$dbPass = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1') ? '' : DB_PASS;

echo "🔧 CONDITION VARCHAR MIGRATION\n";
echo str_repeat("=", 60) . "\n\n";

try {
    $primaryPdo = new PDO(
        "mysql:host=$dbHost;dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    
    $tenants = $primaryPdo->query("SELECT tenant_slug FROM tenants WHERE status = 'active'")->fetchAll();
    
    foreach ($tenants as $tenant) {
        $dbName = 'electrox_' . $tenant['tenant_slug'];
        echo "📍 Processing: $dbName\n";
        
        try {
            $tenantPdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            
            // Check current column type
            $col = $tenantPdo->query("SHOW COLUMNS FROM product_specific_list WHERE Field = 'condition'")->fetch();
            
            if ($col) {
                if (stripos($col['Type'], 'varchar') !== false) {
                    echo "  ⏭️  Already VARCHAR - skipping\n";
                } else {
                    $tenantPdo->exec("ALTER TABLE `product_specific_list` MODIFY COLUMN `condition` VARCHAR(50) DEFAULT 'New'");
                    echo "  ✅ Changed from {$col['Type']} to VARCHAR(50)\n";
                }
            } else {
                echo "  ⚠️  condition column not found\n";
            }
        } catch (PDOException $e) {
            echo "  ❌ Error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n✅ ALL MIGRATIONS COMPLETE\n";
    
} catch (Exception $e) {
    echo "❌ FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
