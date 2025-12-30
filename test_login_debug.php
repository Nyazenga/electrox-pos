<?php
require 'config.php';
require 'includes/db.php';
require 'includes/functions.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head><title>Login Debug</title></head>
<body>
<h2>Login Debug Test</h2>
<?php
$tenant_name = 'primary';
$email = 'admin@electrox.co.zw';

echo "<p>Testing tenant: <strong>$tenant_name</strong></p>";

try {
    $db = Database::getMainInstance();
    echo "<p>✅ Connected to: " . BASE_DB_NAME . "</p>";
    
    // Test 1: Check database exists
    $stmt = $db->getPdo()->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :dbname");
    $stmt->execute([':dbname' => 'electrox_primary']);
    $dbExists = $stmt->fetch();
    echo "<p>" . ($dbExists ? "✅" : "❌") . " Database 'electrox_primary' exists</p>";
    
    // Test 2: Check tenant in table
    $tenant = $db->getRow("SELECT * FROM tenants WHERE tenant_slug = :slug", [':slug' => 'primary']);
    if ($tenant) {
        echo "<p>✅ Tenant found:</p>";
        echo "<pre>";
        print_r($tenant);
        echo "</pre>";
    } else {
        echo "<p>❌ Tenant not found in tenants table</p>";
    }
    
    // Test 3: checkTenantExists function
    echo "<h3>Testing checkTenantExists() function:</h3>";
    $result = checkTenantExists($tenant_name);
    echo "<p>" . ($result ? "✅" : "❌") . " checkTenantExists('$tenant_name') returned: " . ($result ? 'TRUE' : 'FALSE') . "</p>";
    
    // Test 4: isTenantActive function
    $active = isTenantActive($tenant_name);
    echo "<p>" . ($active ? "✅" : "❌") . " isTenantActive('$tenant_name') returned: " . ($active ? 'TRUE' : 'FALSE') . "</p>";
    
    // Test 5: Check user exists (Auth class uses 'users' table, not 'admin_users')
    if ($result && $active) {
        setCurrentTenant($tenant_name);
        $tenantDb = Database::getInstance();
        $user = $tenantDb->getRow("SELECT * FROM users WHERE email = :email", [':email' => $email]);
        if ($user) {
            echo "<p>✅ User found in users table:</p>";
            echo "<pre>";
            print_r(['id' => $user['id'], 'email' => $user['email'], 'username' => $user['username'] ?? 'N/A', 'status' => $user['status'] ?? 'N/A']);
            echo "</pre>";
        } else {
            echo "<p>❌ User '$email' not found in users table</p>";
            echo "<p>Checking all users in database:</p>";
            $allUsers = $tenantDb->getRows("SELECT id, email, username FROM users LIMIT 10");
            if ($allUsers) {
                echo "<pre>";
                print_r($allUsers);
                echo "</pre>";
            } else {
                echo "<p>No users found in database</p>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
</body>
</html>

