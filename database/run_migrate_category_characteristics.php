<?php
/**
 * Run Category Characteristics Migration
 * Runs on all tenant databases
 */

define('APP_PATH', dirname(dirname(__FILE__)));
require_once APP_PATH . '/config.php';

$dbHost = DB_HOST;
$dbUser = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1') ? 'root' : DB_USER;
$dbPass = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1') ? '' : DB_PASS;

echo "🔧 CATEGORY CHARACTERISTICS MIGRATION\n";
echo str_repeat("=", 60) . "\n\n";

try {
    // Connect to primary database to get tenant list
    $primaryPdo = new PDO(
        "mysql:host=$dbHost;dbname=" . PRIMARY_DB_NAME . ";charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    
    // Get tenants
    $tenants = $primaryPdo->query("SELECT tenant_slug FROM tenants WHERE status = 'active'")->fetchAll();
    
    $sqlFile = __DIR__ . '/migrate_category_characteristics.sql';
    $sql = file_get_contents($sqlFile);
    
    // Remove SQL comment lines, then split into individual statements
    $sqlClean = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sqlClean)));
    
    foreach ($tenants as $tenant) {
        $dbName = 'electrox_' . $tenant['tenant_slug'];
        echo "📍 Processing: $dbName\n";
        
        try {
            $tenantPdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            
            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if (empty($stmt)) continue;
                
                try {
                    $tenantPdo->exec($stmt);
                } catch (PDOException $e) {
                    // Ignore "already exists" errors
                    if (strpos($e->getMessage(), 'Duplicate') === false && 
                        strpos($e->getMessage(), 'already exists') === false) {
                        echo "  ⚠️  " . substr($e->getMessage(), 0, 100) . "\n";
                    }
                }
            }
            
            echo "  ✅ Migration complete\n";
        } catch (PDOException $e) {
            echo "  ❌ Error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n✅ ALL MIGRATIONS COMPLETE\n";
    
} catch (Exception $e) {
    echo "❌ FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
