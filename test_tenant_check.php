<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

echo "<h2>Testing Tenant Check</h2>";

$tenant_name = 'primary';

echo "<p>Checking tenant: <strong>$tenant_name</strong></p>";

// Test database connection
try {
    $db = Database::getMainInstance();
    echo "<p>✓ Database connection successful</p>";
    
    // Check if database exists
    $dbName = 'electrox_' . $tenant_name;
    $stmt = $db->getPdo()->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :dbname");
    $stmt->execute([':dbname' => $dbName]);
    $dbExists = $stmt->fetch();
    
    if ($dbExists) {
        echo "<p>✓ Database '$dbName' exists</p>";
    } else {
        echo "<p>✗ Database '$dbName' does NOT exist</p>";
    }
    
    // Check tenant in tenants table
    $tenant = $db->getRow(
        "SELECT * FROM tenants WHERE tenant_slug = :slug",
        [':slug' => $tenant_name]
    );
    
    if ($tenant) {
        echo "<p>✓ Tenant found in tenants table:</p>";
        echo "<pre>";
        print_r($tenant);
        echo "</pre>";
    } else {
        echo "<p>✗ Tenant NOT found in tenants table</p>";
        
        // Show all tenants
        $allTenants = $db->getRows("SELECT * FROM tenants");
        echo "<p>All tenants in database:</p>";
        echo "<pre>";
        print_r($allTenants);
        echo "</pre>";
    }
    
    // Test checkTenantExists function
    echo "<h3>Testing checkTenantExists() function:</h3>";
    $result = checkTenantExists($tenant_name);
    if ($result) {
        echo "<p>✓ checkTenantExists('$tenant_name') returned TRUE</p>";
    } else {
        echo "<p>✗ checkTenantExists('$tenant_name') returned FALSE</p>";
    }
    
    // Test isTenantActive
    $isActive = isTenantActive($tenant_name);
    if ($isActive) {
        echo "<p>✓ isTenantActive('$tenant_name') returned TRUE</p>";
    } else {
        echo "<p>✗ isTenantActive('$tenant_name') returned FALSE</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

