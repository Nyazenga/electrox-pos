<?php
/**
 * Run printers table migration across all tenant databases
 */

require_once __DIR__ . '/../config.php';

function getAllTenantDatabases() {
    // Auto-detect environment
    $isLocal = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1');
    $host = DB_HOST;
    $user = $isLocal ? 'root' : DB_USER;
    $pass = $isLocal ? '' : DB_PASS;
    
    try {
        $pdo = new PDO("mysql:host=$host", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get all tenant databases
        $stmt = $pdo->query("SELECT tenant_slug, database_name FROM electrox_base.tenants WHERE status = 'active'");
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $tenants;
    } catch (PDOException $e) {
        die("Error connecting to database: " . $e->getMessage() . "\n");
    }
}

function runMigration($database) {
    // Auto-detect environment
    $isLocal = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1');
    $host = DB_HOST;
    $user = $isLocal ? 'root' : DB_USER;
    $pass = $isLocal ? '' : DB_PASS;
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$database", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $sqlFile = __DIR__ . '/migrate_printers_table.sql';
        $sql = file_get_contents($sqlFile);
        
        // Remove comments and split by semicolon
        $sql = preg_replace('/--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Migration failed for $database: " . $e->getMessage());
        return false;
    }
}

// Run migration
echo "Starting printers table migration...\n\n";

// Migrate primary database
echo "Migrating electrox_primary...\n";
if (runMigration('electrox_primary')) {
    echo "✅ electrox_primary migrated successfully\n";
} else {
    echo "❌ electrox_primary migration failed\n";
}

// Migrate all tenant databases
$tenants = getAllTenantDatabases();
echo "\nMigrating " . count($tenants) . " tenant databases...\n";

foreach ($tenants as $tenant) {
    $dbName = $tenant['database_name'];
    echo "Migrating $dbName...\n";
    if (runMigration($dbName)) {
        echo "✅ $dbName migrated successfully\n";
    } else {
        echo "❌ $dbName migration failed\n";
    }
}

echo "\n✅ Migration complete!\n";
