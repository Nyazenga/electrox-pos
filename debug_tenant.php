<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$tenant_name = $_GET['tenant'] ?? 'primary';

echo "<h2>Debug Tenant Check for: $tenant_name</h2>";

try {
    // Test 1: Database connection
    echo "<h3>Test 1: Database Connection</h3>";
    $db = Database::getMainInstance();
    echo "✓ Connected to: " . BASE_DB_NAME . "<br>";
    
    // Test 2: Check database exists
    echo "<h3>Test 2: Check Database Exists</h3>";
    $dbName = 'electrox_' . strtolower(trim($tenant_name));
    $stmt = $db->getPdo()->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :dbname");
    $stmt->execute([':dbname' => $dbName]);
    if ($stmt->fetch()) {
        echo "✓ Database '$dbName' exists<br>";
    } else {
        echo "✗ Database '$dbName' does NOT exist<br>";
    }
    
    // Test 3: Check tenant in table
    echo "<h3>Test 3: Check Tenant in Table</h3>";
    $tenantSlug = strtolower(trim($tenant_name));
    $tenant = $db->getRow(
        "SELECT * FROM tenants WHERE tenant_slug = :slug",
        [':slug' => $tenantSlug]
    );
    
    if ($tenant) {
        echo "✓ Tenant found:<br>";
        echo "ID: " . $tenant['id'] . "<br>";
        echo "Name: " . $tenant['tenant_name'] . "<br>";
        echo "Slug: " . $tenant['tenant_slug'] . "<br>";
        echo "Status: " . $tenant['status'] . "<br>";
    } else {
        echo "✗ Tenant NOT found<br>";
        $all = $db->getRows("SELECT tenant_slug, tenant_name FROM tenants");
        echo "All tenants: " . print_r($all, true) . "<br>";
    }
    
    // Test 4: Function check
    echo "<h3>Test 4: checkTenantExists() Function</h3>";
    $result = @checkTenantExists($tenant_name);
    echo "Result: " . ($result ? "TRUE" : "FALSE") . "<br>";
    
    // Test 5: isTenantActive
    echo "<h3>Test 5: isTenantActive() Function</h3>";
    $active = @isTenantActive($tenant_name);
    echo "Result: " . ($active ? "TRUE" : "FALSE") . "<br>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

