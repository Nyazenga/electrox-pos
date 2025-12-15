<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Creating default tenant 'primary'...\n";
    
    $tenantSlug = 'primary';
    $databaseName = 'electrox_' . $tenantSlug;
    
    // Check if tenant already exists
    $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :dbname");
    $stmt->execute([':dbname' => $databaseName]);
    if ($stmt->fetch()) {
        echo "Tenant database already exists. Skipping creation.\n";
    } else {
        // Create database
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$databaseName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "Database created: $databaseName\n";
        
        // Clone from primary
        $pdo->exec("USE `{$databaseName}`");
        
        $primaryDb = Database::getPrimaryInstance();
        $tables = $primaryDb->getRows("SHOW TABLES");
        $tableKey = 'Tables_in_' . PRIMARY_DB_NAME;
        
        foreach ($tables as $table) {
            $tableName = $table[$tableKey];
            echo "Cloning table: $tableName\n";
            
            $pdo->exec("CREATE TABLE `{$databaseName}`.`{$tableName}` LIKE `" . PRIMARY_DB_NAME . "`.`{$tableName}`");
            $pdo->exec("INSERT INTO `{$databaseName}`.`{$tableName}` SELECT * FROM `" . PRIMARY_DB_NAME . "`.`{$tableName}`");
        }
        
        echo "Database cloned successfully!\n";
    }
    
    // Create tenant record in base database
    $baseDb = Database::getMainInstance();
    
    $existingTenant = $baseDb->getRow(
        "SELECT * FROM tenants WHERE tenant_slug = :slug",
        [':slug' => $tenantSlug]
    );
    
    if (!$existingTenant) {
        $tenantData = [
            'tenant_name' => 'ELECTROX Primary',
            'tenant_slug' => $tenantSlug,
            'database_name' => $databaseName,
            'company_name' => 'ELECTROX Electronics',
            'business_type' => 'Electronics Retail',
            'contact_email' => 'admin@electrox.co.zw',
            'contact_person' => 'System Administrator',
            'subscription_plan' => 'Professional',
            'max_users' => 50,
            'max_branches' => 10,
            'max_products' => 10000,
            'storage_limit_gb' => 100,
            'status' => 'active',
            'country' => 'Zimbabwe',
            'currency' => 'USD',
            'timezone' => 'Africa/Harare',
            'created_at' => date('Y-m-d H:i:s'),
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => 1
        ];
        
        $baseDb->insert('tenants', $tenantData);
        echo "Tenant record created in base database!\n";
    } else {
        echo "Tenant record already exists in base database.\n";
    }
    
    echo "\n=== DEFAULT TENANT CREATED SUCCESSFULLY ===\n";
    echo "Tenant Slug: primary\n";
    echo "Database: electrox_primary\n";
    echo "\nYou can now login with:\n";
    echo "URL: http://localhost/electrox-pos/login.php\n";
    echo "Tenant Name: primary\n";
    echo "Email: admin@electrox.co.zw\n";
    echo "Password: Admin@123\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

