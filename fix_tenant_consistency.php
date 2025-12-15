<?php
/**
 * Fix Tenant Consistency Script
 * 
 * This script ensures that:
 * 1. tenant_slug in tenants table matches the database suffix (e.g., tenant_slug = "primary" means database = "electrox_primary")
 * 2. All tenant records have correct database_name values
 * 3. All databases follow the naming pattern electrox_{tenant_slug}
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "=== FIXING TENANT CONSISTENCY ===\n\n";

try {
    $baseDb = Database::getMainInstance();
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all tenants
    $tenants = $baseDb->getRows("SELECT id, tenant_slug, database_name, tenant_name FROM tenants");
    
    echo "Checking and fixing tenant records:\n\n";
    
    foreach ($tenants as $tenant) {
        $tenantSlug = $tenant['tenant_slug'];
        $currentDbName = $tenant['database_name'];
        $expectedDbName = 'electrox_' . $tenantSlug;
        
        echo "Tenant: {$tenant['tenant_name']} (slug: $tenantSlug)\n";
        echo "  Current DB Name: $currentDbName\n";
        echo "  Expected DB Name: $expectedDbName\n";
        
        // Check if database name matches pattern
        if ($currentDbName !== $expectedDbName) {
            echo "  ⚠️  FIXING: Updating database_name from '$currentDbName' to '$expectedDbName'\n";
            $baseDb->update('tenants', [
                'database_name' => $expectedDbName
            ], ['id' => $tenant['id']]);
        } else {
            echo "  ✓ Database name is correct\n";
        }
        
        // Check if database actually exists
        $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$expectedDbName]);
        if ($stmt->fetch()) {
            echo "  ✓ Database exists\n";
        } else {
            echo "  ⚠️  WARNING: Database '$expectedDbName' does not exist!\n";
        }
        
        echo "\n";
    }
    
    // Check for orphaned databases (databases without tenant records)
    echo "Checking for orphaned databases:\n";
    $stmt = $pdo->query("SHOW DATABASES LIKE 'electrox_%'");
    $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($databases as $dbName) {
        if ($dbName === 'electrox_base' || $dbName === 'electrox_primary') {
            continue; // Skip base and primary databases
        }
        
        $tenantSlug = str_replace('electrox_', '', $dbName);
        $tenant = $baseDb->getRow("SELECT id FROM tenants WHERE tenant_slug = :slug", [':slug' => $tenantSlug]);
        
        if (!$tenant) {
            echo "  ⚠️  Orphaned database found: $dbName (no tenant record for slug: $tenantSlug)\n";
        }
    }
    
    echo "\n=== TENANT CONSISTENCY CHECK COMPLETE ===\n";
    echo "\nPattern confirmed: tenant_slug = database suffix\n";
    echo "Example: tenant_slug = 'primary' → database = 'electrox_primary'\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

